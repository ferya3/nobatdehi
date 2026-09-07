<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Slot\AppointmentScheduler;
use App\Domain\Slot\Opening;
use App\Domain\Slot\SlotGenerator;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\Product;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * زمان‌بند، بدون عبور از صدور نوبت.
 *
 * قواعدی که اینجا نگه داشته می‌شوند و اگر بشکنند، صف کارخانه از صبح روز
 * اول از برنامه عقب می‌افتد.
 */
final class SchedulerTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private AppointmentScheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->scheduler = app(AppointmentScheduler::class);
    }

    #[Test]
    public function the_site_clock_runs_on_tehran_time(): void
    {
        // نوبت ساعت ۰۸:۰۰ باید ۰۸:۰۰ باشد، نه ۰۴:۳۰ به وقت UTC
        $this->assertSame('Asia/Tehran', config('app.timezone'));
        $this->assertSame('Asia/Tehran', now()->getTimezone()->getName());
    }

    #[Test]
    public function the_first_appointment_of_the_day_starts_when_the_factory_opens(): void
    {
        // نیمه‌شب: ساعت باز شدن کارخانه دیرتر از «حالا + مهلت» است
        $this->travelTo(CarbonImmutable::today()->setTime(1, 0));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 30]);

        $opening = $this->scheduler->nextOpening($factory, 30);

        $this->assertNotNull($opening);
        $this->assertSame('07:00', $opening->startsAt->format('H:i'));
    }

    #[Test]
    public function a_late_first_booking_starts_from_now_not_from_opening_time(): void
    {
        // ساعت ۱۰:۰۰ نوبت می‌گیرد؛ نوبتی برای ۰۷:۰۰ صادر کردن بی‌معنی است
        $this->travelTo(CarbonImmutable::today()->setTime(10, 0));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 60]);

        $opening = $this->scheduler->nextOpening($factory, 30);

        $this->assertNotNull($opening);
        $this->assertSame('11:00', $opening->startsAt->format('H:i'));
    }

    #[Test]
    public function the_announced_time_lands_on_a_clean_five_minutes(): void
    {
        // ساعتی که هیچ‌وقت رُند نیست
        $this->travelTo(CarbonImmutable::today()->setTime(9, 3, 47));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 60]);

        $opening = $this->scheduler->nextOpening($factory, 30);

        $this->assertNotNull($opening);
        // ۱۰:۰۳:۴۷ به بالا رُند می‌شود، نه به پایین
        $this->assertSame('10:05', $opening->startsAt->format('H:i'));
        $this->assertSame(0, $opening->startsAt->second);
    }

    #[Test]
    public function a_closed_day_is_skipped_entirely(): void
    {
        $this->travelTo(CarbonImmutable::today()->setTime(6, 0));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 30]);

        // امروز را تعطیل می‌کنیم
        WorkingHour::where('factory_id', $factory->id)
            ->where('weekday', SlotGenerator::weekdayFor(CarbonImmutable::today()))
            ->update(['is_open' => false]);

        $opening = $this->scheduler->nextOpening($factory->fresh(), 30);

        $this->assertNotNull($opening);
        $this->assertFalse($opening->date->isToday(), 'روز تعطیل نباید نوبت بگیرد');
    }

    #[Test]
    public function a_truck_that_cannot_finish_before_closing_waits_for_the_next_day(): void
    {
        // نزدیک تعطیلی، و کامیونی که دو ساعت طول می‌کشد
        $this->travelTo(CarbonImmutable::today()->setTime(16, 0));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 30]);

        // ۱۶:۳۰ + ۱۲۰ دقیقه = ۱۸:۳۰، بعد از ساعت تعطیلی ۱۸:۰۰
        $opening = $this->scheduler->nextOpening($factory, 120);

        $this->assertNotNull($opening);
        $this->assertFalse($opening->date->isToday(), 'بارگیری باید تا تعطیلی تمام شود، نه فقط شروع');
    }

    #[Test]
    public function nothing_is_offered_beyond_the_horizon(): void
    {
        $this->travelTo(CarbonImmutable::today()->setTime(8, 0));

        $factory = $this->openEveryDay([
            'booking_horizon_days' => 2,
            'booking_lead_minutes' => 30,
        ]);

        // کامیونی که از کلِ یک روز کاری بلندتر است، هیچ روزی جا نمی‌شود
        $this->assertNull($this->scheduler->nextOpening($factory, 60 * 24));
    }

    /**
     * لغو، ظرفیت روز را نمی‌سوزاند.
     *
     * این همان چیزی است که روی سرور دیده شد: بعد از چند لغوِ آزمایشی، اولین
     * نوبتِ فردا به‌جای ۰۷:۰۰ ساعت‌ها دیرتر اعلام می‌شد — دقیقاً به اندازه‌ی
     * جمعِ بارگیریِ نوبت‌هایی که هیچ‌وقت نیامدند.
     */
    #[Test]
    public function a_cancelled_appointment_gives_its_slot_back(): void
    {
        $this->travelTo(CarbonImmutable::today()->setTime(1, 0));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 30, 'loading_lines' => 1]);

        $first = $this->scheduler->nextOpening($factory, 80);
        $this->assertNotNull($first);
        $this->assertSame('07:00', $first->startsAt->format('H:i'));

        $appointment = $this->bookInto($first);

        // تا وقتی زنده است، نوبت بعدی پشت سرش می‌نشیند
        $this->assertSame(
            '08:20',
            $this->scheduler->nextOpening($factory, 80)?->startsAt->format('H:i'),
        );

        app(TransitionAppointment::class)(
            $appointment,
            S::Cancelled,
            Actor::driver($appointment->driver),
            'راننده منصرف شد',
        );

        // و با لغو، همان ۰۷:۰۰ دوباره آزاد می‌شود
        $this->assertSame(
            '07:00',
            $this->scheduler->nextOpening($factory, 80)?->startsAt->format('H:i'),
        );
    }

    #[Test]
    public function a_completed_appointment_keeps_its_slot(): void
    {
        $this->travelTo(CarbonImmutable::today()->setTime(1, 0));

        $factory = $this->openEveryDay(['booking_lead_minutes' => 30, 'loading_lines' => 1]);

        $opening = $this->scheduler->nextOpening($factory, 80);
        $appointment = $this->bookInto($opening);

        // آن کامیون واقعاً لاین را گرفت؛ آزاد کردنش یعنی دو بارگیری هم‌زمان
        $appointment->forceFill(['status' => S::Completed])->save();

        $this->assertSame(
            '08:20',
            $this->scheduler->nextOpening($factory, 80)?->startsAt->format('H:i'),
        );
    }

    /** نوبتی که دقیقاً روی یک Opening نشسته — بدون عبور از CreateAppointment */
    private function bookInto(Opening $opening): Appointment
    {
        $driver = $this->makeDriver('0912'.str_pad((string) random_int(1, 9999999), 7, '0'));

        return Appointment::create([
            'factory_id' => $this->factory->id,
            'number' => 1 + (int) Appointment::where('factory_id', $this->factory->id)
                ->whereDate('date', $opening->date->toDateString())->max('number'),
            'driver_id' => $driver->id,
            'truck_id' => $this->makeTruck(
                str_pad((string) random_int(10, 99), 2, '0'), 'ب',
                str_pad((string) random_int(100, 999), 3, '0'), '67',
            )->id,
            'product_id' => Product::where('factory_id', $this->factory->id)->firstOrFail()->id,
            'date' => $opening->date->toDateString(),
            'start_time' => $opening->startTime(),
            'end_time' => $opening->endTime(),
            'line_no' => $opening->line,
            'status' => S::Booked,
        ]);
    }

    /** کارخانه‌ای که همه‌ی هفته ۰۷:۰۰ تا ۱۸:۰۰ باز است */
    private function openEveryDay(array $overrides = []): Factory
    {
        $this->factory->update($overrides);

        WorkingHour::where('factory_id', $this->factory->id)->update([
            'is_open' => true,
            'opens_at' => '07:00:00',
            'closes_at' => '18:00:00',
        ]);

        return $this->factory->fresh();
    }
}
