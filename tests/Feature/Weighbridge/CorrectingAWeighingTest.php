<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TruckType;
use App\Models\User;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * توزینی که سامانه ردش می‌کند، باید راهی برای درست شدن داشته باشد.
 *
 * تا پیش از این نداشت: اولین وزنِ پر «آخرین» بود، حتی وقتی خودِ سامانه ردش
 * کرده بود. کامیونِ اضافه‌بار بار را کم می‌کرد، باسکول می‌گفت «قبلاً ثبت
 * شده»، و چون برگه‌ی خروج فقط از توزینِ پاک صادر می‌شود، کامیون نه برگه
 * می‌گرفت و نه می‌توانست تکمیل شود — تا ابد در محوطه.
 *
 * صفحه‌ی باسکول همان موقع هم می‌نوشت «تا کاهش بار و توزین دوباره…» یعنی
 * رابط کاربری چیزی را وعده می‌داد که سرور اجازه‌اش را نمی‌داد.
 */
final class CorrectingAWeighingTest extends TestCase
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

        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();

        Setting::putMany(['sms_provider' => 'console']);
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

        $user->assignRole(Role::findByName(Roles::WEIGHBRIDGE, 'web'));

        return $this->scaleman = $user->fresh();
    }

    /** کامیونی که وارد شده، وزن خالی دارد، و بارگیری‌اش تمام شده */
    private function loaded(?string $truckTypeCode = null): Appointment
    {
        $truck = $this->makeTruck('12', 'ب', '345', '11');

        if ($truckTypeCode !== null) {
            $truck->forceFill([
                'truck_type_id' => TruckType::where('code', $truckTypeCode)->value('id'),
            ])->save();
        }

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
            $this->product,
        ));

        $move = app(TransitionAppointment::class);
        $appointment = $move($appointment, AppointmentStatus::CheckedIn, Actor::system());

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $appointment = $move($appointment, AppointmentStatus::Loading, Actor::system());

        return $move($appointment, AppointmentStatus::Loaded, Actor::system());
    }

    private function weigh(Appointment $appointment, string $stage, int $kg): TestResponse
    {
        return $this->actingAs($this->scaleman(), 'web')
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => $stage,
                'weight_kg' => $kg,
            ]);
    }

    #[Test]
    public function an_overloaded_truck_can_shed_weight_and_be_weighed_again(): void
    {
        // ظرفیت «تریلی» ۳۰ تن است و حواله هم ۳۰ تن
        $appointment = $this->loaded('teriler');

        // خالص ۳۱ تن: یک تن بیشتر از ظرفیت مجاز
        $this->weigh($appointment, 'gross', 45000)->assertSessionHas('error');

        $this->assertNull(LoadingRecord::firstOrFail()->exit_permit_number);

        // راننده یک تن بار کم می‌کند و دوباره روی باسکول می‌رود
        $this->weigh($appointment, 'gross', 44000)->assertSessionHas('success');

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('30000.00', $record->net_weight_kg);
        $this->assertNotNull($record->exit_permit_number, 'توزین دوباره باید برگه خروج بگیرد.');
        $this->assertFalse((bool) $record->is_overload);
        $this->assertNull($record->discrepancy_kind);
    }

    #[Test]
    public function a_mistyped_gross_weight_can_be_retyped(): void
    {
        $appointment = $this->loaded();

        // پر کمتر از خالی — انگشت روی صفر اضافه نرفته
        $this->weigh($appointment, 'gross', 4400)->assertSessionHas('error');

        $this->weigh($appointment, 'gross', 44000)->assertSessionHas('success');

        $this->assertSame('30000.00', LoadingRecord::firstOrFail()->net_weight_kg);
    }

    #[Test]
    public function the_weighbridge_keeps_offering_the_second_weighing_until_the_permit_is_issued(): void
    {
        $appointment = $this->loaded('teriler');

        $this->weigh($appointment, 'gross', 45000);

        // بدون این، صفحه هیچ مرحله‌ای پیشنهاد نمی‌داد و باسکول‌بان
        // فرمی برای وارد کردن وزنِ تازه نمی‌دید
        $this->actingAs($this->scaleman(), 'web')
            ->get(route('staff.weighbridge.index', ['waybill' => $appointment->ulid]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment.stage', 'gross'));
    }

    #[Test]
    public function once_the_exit_permit_is_issued_the_weight_is_final(): void
    {
        $appointment = $this->loaded();

        $this->weigh($appointment, 'gross', 44000)->assertSessionHas('success');

        $permit = LoadingRecord::firstOrFail()->exit_permit_number;

        $this->weigh($appointment, 'gross', 50000)
            ->assertSessionHas('error', 'برگه خروج این حواله صادر شده است؛ وزن دیگر تغییر نمی‌کند.');

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('44000.00', $record->loaded_weight_kg);
        $this->assertSame($permit, $record->exit_permit_number);
    }

    #[Test]
    public function a_tare_is_never_rewritten_and_the_message_says_what_to_do_instead(): void
    {
        // برخلاف وزن پر، وزن خالی حتی پیش از بارگیری هم دوباره نوشته نمی‌شود:
        // خالصِ بار از همین عدد کم می‌شود، پس بازنویسی‌اش یعنی تناژِ دلخواه.
        $truck = $this->makeTruck('12', 'ب', '345', '11');

        $appointment = app(TransitionAppointment::class)(
            app(CreateAppointment::class)($this->booking(
                $this->factory, $this->makeDriver('09123456789'), $truck->refresh(), $this->product,
            )),
            AppointmentStatus::CheckedIn,
            Actor::system(),
        );

        $this->weigh($appointment, 'tare', 1400)->assertSessionHas('success');

        $this->weigh($appointment, 'tare', 14000)->assertSessionHas(
            'error',
            'وزن خالی این حواله ثبت شده و تغییر نمی‌کند. اگر اشتباه است، حواله را لغو کنید و نوبت تازه بگیرید.',
        );

        $this->assertSame('1400.00', LoadingRecord::firstOrFail()->empty_weight_kg);
    }

    #[Test]
    public function a_tare_cannot_be_recorded_once_loading_has_started(): void
    {
        $appointment = $this->loaded();

        $this->weigh($appointment, 'tare', 9000)->assertSessionHas('error');

        $this->assertSame('14000.00', LoadingRecord::firstOrFail()->empty_weight_kg);
    }
}
