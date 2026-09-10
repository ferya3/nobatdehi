<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Events\WeightDiscrepancyDetected;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\TruckType;
use App\Models\User;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * بار که با حواله نمی‌خواند، کسی باید خبردار شود.
 *
 * قفل شدنِ برگه‌ی خروج به تنهایی کافی نیست: نتیجه‌اش یک کامیونِ ایستاده در
 * محوطه است و یک اپراتور که باید تصمیم بگیرد، در حالی که تصمیم‌گیرنده
 * هیچ‌وقت خبردار نشده. این آزمون همان زنجیره را نگه می‌دارد.
 */
final class WeightDiscrepancyAlertTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->seed(SmsTemplateSeeder::class);
        $this->freezeOnWorkingMorning($this->factory);

        // محصول A تناژ حواله‌اش ۳۰ تن است؛ رواداری کارخانه ۳٪ یعنی ۹۰۰ کیلو
        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();

        Setting::putMany([
            'sms_provider' => 'console',
            'sms_manager_recipients' => '09120000099',
        ]);
    }

    private ?User $scaleman = null;

    private function scaleman(): User
    {
        if ($this->scaleman !== null) {
            return $this->scaleman;
        }

        $user = User::create([
            'name' => 'باسکول‌بان',
            'email' => 'scale@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(Roles::WEIGHBRIDGE));

        return $this->scaleman = $user->fresh();
    }

    private function loaded(?TruckType $type = null): Appointment
    {
        $truck = $this->makeTruck('12', 'ب', '345', '11');

        if ($type !== null) {
            $truck->forceFill(['truck_type_id' => $type->id])->save();
        }

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
            $this->product,
        ));

        $appointment = app(TransitionAppointment::class)(
            $appointment, AppointmentStatus::CheckedIn, Actor::system(),
        );

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $appointment = app(TransitionAppointment::class)($appointment, AppointmentStatus::Loading, Actor::system());

        return app(TransitionAppointment::class)($appointment, AppointmentStatus::Loaded, Actor::system());
    }

    private function weighGross(Appointment $appointment, int $kg): void
    {
        $this->actingAs($this->scaleman())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'gross',
                'weight_kg' => $kg,
            ]);
    }

    #[Test]
    public function a_tonnage_that_misses_the_waybill_texts_the_manager(): void
    {
        $appointment = $this->loaded();

        // خالص ۳۶ تن در برابر ۳۰ تنِ حواله — ۶ تن بیرون از رواداری
        $this->weighGross($appointment, 50000);

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09120000099',
            'template_key' => 'weight.discrepancy.manager',
        ]);

        $body = (string) SmsMessage::where('template_key', 'weight.discrepancy.manager')->value('body');

        // متن باید واقعاً پر شده باشد و عددها را داشته باشد
        $this->assertStringNotContainsString('{', $body);
        $this->assertStringContainsString('مغایرت وزن', $body);
        $this->assertStringContainsString((string) $appointment->number, $body);
    }

    #[Test]
    public function an_overload_texts_the_manager_too(): void
    {
        $tak = TruckType::where('code', 'tak')->firstOrFail();

        // ظرفیت تک ۱۰ تن است؛ خالص ۱۲ تن یعنی اضافه‌بار
        $this->weighGross($this->loaded($tak), 26000);

        $body = (string) SmsMessage::where('template_key', 'weight.discrepancy.manager')->value('body');

        $this->assertStringContainsString('اضافه‌بار', $body);
    }

    #[Test]
    public function a_clean_weighing_texts_nobody(): void
    {
        // خالص دقیقاً ۳۰ تن — همان تناژ حواله
        $this->weighGross($this->loaded(), 44000);

        $this->assertSame(0, SmsMessage::where('template_key', 'weight.discrepancy.manager')->count());
        $this->assertNotNull(LoadingRecord::firstOrFail()->exit_permit_number);
    }

    #[Test]
    public function a_mistyped_weight_is_not_treated_as_a_discrepancy(): void
    {
        // پر کمتر از خالی: عددِ اشتباه تایپ‌شده است، نه مغایرتِ بار.
        // اپراتور ده ثانیه بعد خودش درستش می‌کند؛ پیامک برای این یعنی
        // آموزش دادنِ مدیر به نخواندنِ پیامک‌های سامانه.
        $this->weighGross($this->loaded(), 13000);

        $this->assertSame(0, SmsMessage::where('template_key', 'weight.discrepancy.manager')->count());
        $this->assertNull(LoadingRecord::firstOrFail()->discrepancy_kind);
    }

    #[Test]
    public function weighing_the_same_truck_again_does_not_text_a_second_time(): void
    {
        $appointment = $this->loaded();

        // هر سه توزین واقعاً ثبت می‌شوند — وگرنه این آزمون به دلیل غلط سبز
        // می‌ماند: توزینِ ردشده‌ای که اصلاً پذیرفته نشود، پیامکی هم ندارد.
        foreach ([50000, 51000, 52000] as $kg) {
            $this->weighGross($appointment, $kg);
        }

        $this->assertSame('52000.00', LoadingRecord::firstOrFail()->loaded_weight_kg);

        $this->assertSame(
            1,
            SmsMessage::where('template_key', 'weight.discrepancy.manager')->count(),
            'اپراتور چند بار وزن می‌کند تا تکلیف روشن شود؛ مدیر نباید چند پیامک بگیرد.',
        );
    }

    #[Test]
    public function the_ceos_own_number_wins_over_the_manager_list(): void
    {
        Setting::putMany(['sms_weight_alert_recipients' => '09121234567']);

        $this->weighGross($this->loaded(), 50000);

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09121234567',
            'template_key' => 'weight.discrepancy.manager',
        ]);

        // اخطارِ وزن مالِ تصمیم‌گیرنده است، نه فهرستِ «نوبت جدید»
        $this->assertDatabaseMissing('sms_messages', [
            'to' => '09120000099',
            'template_key' => 'weight.discrepancy.manager',
        ]);
    }

    #[Test]
    public function the_event_carries_what_the_message_needs(): void
    {
        Event::fake([WeightDiscrepancyDetected::class]);

        $appointment = $this->loaded();
        $this->weighGross($appointment, 50000);

        Event::assertDispatched(
            WeightDiscrepancyDetected::class,
            fn (WeightDiscrepancyDetected $e) => $e->appointmentId === $appointment->id
                && $e->kind === WeightDiscrepancyDetected::KIND_VARIANCE
                && (int) $e->netKg === 36000
                && (int) $e->expectedKg === 30000
                && (int) $e->varianceKg === 6000,
        );
    }

    #[Test]
    public function the_discrepancy_is_written_on_the_record_for_the_panel_to_show(): void
    {
        $this->weighGross($this->loaded(), 50000);

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('variance', $record->discrepancy_kind);
        $this->assertNotNull($record->discrepancy_alerted_at);
        // برگه‌ی خروج قفل می‌ماند تا تعیین تکلیف
        $this->assertNull($record->exit_permit_number);
    }
}
