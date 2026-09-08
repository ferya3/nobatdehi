<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Truck\PlateNumber;
use App\Events\AppointmentTransitioned;
use App\Listeners\NotifyNextDriverWhenLoadingStarts;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\Truck;
use App\Models\TruckType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * «نوبت جلوتر از شما شروع به بارگیری کرد.»
 *
 * راننده‌ای که نمی‌داند چقدر باید صبر کند یا جلوی در می‌ماند یا سرِ نوبتش
 * نیست. این پیامک همان انتظارِ کور را به یک عدد تبدیل می‌کند.
 */
final class NextInLineNoticeTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // ۰۸:۰۰ یک روز کاری.
        //
        // بدون ثابت‌کردن ساعت، این تست بعدازظهرها می‌شکست: کامیون دوم دیگر
        // تا ساعت تعطیلی جا نمی‌شد و روی فردا می‌رفت، پس «نفر بعدیِ همان
        // روز» وجود نداشت. رفتار درست بود؛ تست ناپایدار بود.
        $this->travelTo(CarbonImmutable::parse('2026-09-07 08:00', 'Asia/Tehran'));

        $this->factory = $this->seedFactory();
        $this->factory->update(['loading_lines' => 1]);
        $this->factory = $this->factory->fresh();

        $this->seedStaff();
        $this->seed(SmsTemplateSeeder::class);
        Setting::putMany(['sms_provider' => 'console']);
    }

    /** یک نوبت با نوع کامیون و نام رانندهٔ مشخص */
    private function book(string $name, string $mobile, string $typeCode, string $plateTwo): Appointment
    {
        $type = TruckType::where('code', $typeCode)->firstOrFail();

        $driver = Driver::create(['mobile' => $mobile, 'name' => $name]);
        $truck = Truck::fromPlate(PlateNumber::make($plateTwo, 'ب', '345', '67'), $type->id);
        $truck->update(['truck_type_id' => $type->id]);

        return app(CreateAppointment::class)($this->booking($this->factory, $driver, $truck));
    }

    private function startLoading(Appointment $appointment): void
    {
        app(NotifyNextDriverWhenLoadingStarts::class)->handle(
            new AppointmentTransitioned($appointment->id, S::CheckedIn->value, S::Loading->value),
        );
    }

    #[Test]
    public function the_next_driver_is_told_how_long_the_truck_ahead_will_take(): void
    {
        TruckType::where('code', 'teriler')->update(['loading_minutes' => 80]);

        $ahead = $this->book('رضا محمدی', '09120000001', 'teriler', '11');
        $next = $this->book('علی کریمی', '09120000002', 'khavar', '12');

        $this->startLoading($ahead);

        $sms = SmsMessage::where('template_key', 'appointment.next-up')->firstOrFail();

        $this->assertSame($next->driver->mobile, $sms->to);
        $this->assertStringContainsString('علی کریمی', $sms->body);
        $this->assertStringContainsString('۱ ساعت و ۲۰ دقیقه', $sms->body);
        $this->assertStringContainsString('نوبت جلوتر از شما شروع به بارگیری کرد', $sms->body);

        // مدت از نوعِ کامیونِ جلویی می‌آید، نه از کامیونِ خودِ راننده
        $this->assertStringNotContainsString('۳۰ دقیقه', $sms->body);

        $this->assertNotNull($next->fresh()->next_up_notified_at);
    }

    #[Test]
    public function the_same_driver_is_never_told_twice(): void
    {
        $first = $this->book('رضا محمدی', '09120000001', 'teriler', '11');
        $second = $this->book('حسن رستمی', '09120000003', 'tak', '13');
        $this->book('علی کریمی', '09120000002', 'khavar', '12');

        $this->startLoading($first);
        $this->startLoading($first);

        $this->assertSame(1, SmsMessage::where('template_key', 'appointment.next-up')->count());

        // ولی وقتی نفر دوم روی لاین می‌رود، نفر سوم خبر خودش را می‌گیرد
        $this->startLoading($second);

        $this->assertSame(2, SmsMessage::where('template_key', 'appointment.next-up')->count());
    }

    /** بازگردانی از «بارگیری‌شده» اصلاحِ اشتباه است، نه شروعِ تازه */
    #[Test]
    public function rolling_back_into_loading_notifies_nobody(): void
    {
        $ahead = $this->book('رضا محمدی', '09120000001', 'teriler', '11');
        $this->book('علی کریمی', '09120000002', 'khavar', '12');

        app(NotifyNextDriverWhenLoadingStarts::class)->handle(
            new AppointmentTransitioned($ahead->id, S::Loaded->value, S::Loading->value),
        );

        $this->assertSame(0, SmsMessage::where('template_key', 'appointment.next-up')->count());
    }

    #[Test]
    public function a_cancelled_appointment_is_not_the_next_in_line(): void
    {
        $ahead = $this->book('رضا محمدی', '09120000001', 'teriler', '11');
        $cancelled = $this->book('حسن رستمی', '09120000003', 'tak', '13');
        $real = $this->book('علی کریمی', '09120000002', 'khavar', '12');

        $cancelled->forceFill(['status' => S::Cancelled])->save();

        $this->startLoading($ahead);

        $sms = SmsMessage::where('template_key', 'appointment.next-up')->firstOrFail();

        $this->assertSame($real->driver->mobile, $sms->to);
    }

    /**
     * سیم‌کشی، نه فقط خودِ Listener.
     *
     * بقیه‌ی تست‌ها handle() را مستقیم صدا می‌زنند و این ثابت نمی‌کند که
     * رویداد واقعی هم به آن می‌رسد. اینجا از همان مسیری می‌رویم که اپراتور
     * با زدن «شروع بارگیری» طی می‌کند.
     */
    #[Test]
    public function the_real_start_loading_button_sets_the_whole_thing_off(): void
    {
        $ahead = $this->book('رضا محمدی', '09120000001', 'teriler', '11');
        $next = $this->book('علی کریمی', '09120000002', 'khavar', '12');

        $operator = User::role(Roles::OPERATOR)->firstOrFail();
        $transition = app(TransitionAppointment::class);

        $ahead = $transition($ahead, S::Waiting, Actor::user($operator));
        $ahead = $transition($ahead, S::CheckedIn, Actor::user($operator));

        // باسکول اول شرطِ شروع بارگیری است
        $ahead->loadingRecord()->updateOrCreate([], ['empty_weight_kg' => 12000]);

        $transition($ahead->fresh(), S::Loading, Actor::user($operator));

        $sms = SmsMessage::where('template_key', 'appointment.next-up')->firstOrFail();

        $this->assertSame($next->driver->mobile, $sms->to);
    }

    #[Test]
    public function the_last_truck_of_the_day_triggers_nothing(): void
    {
        $only = $this->book('رضا محمدی', '09120000001', 'teriler', '11');

        $this->startLoading($only);

        $this->assertSame(0, SmsMessage::where('template_key', 'appointment.next-up')->count());
    }
}
