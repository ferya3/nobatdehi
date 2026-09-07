<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Slot\AppointmentScheduler;
use App\Models\Factory;
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
        \App\Models\WorkingHour::where('factory_id', $factory->id)
            ->where('weekday', \App\Domain\Slot\SlotGenerator::weekdayFor(CarbonImmutable::today()))
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

    /** کارخانه‌ای که همه‌ی هفته ۰۷:۰۰ تا ۱۸:۰۰ باز است */
    private function openEveryDay(array $overrides = []): Factory
    {
        $this->factory->update($overrides);

        \App\Models\WorkingHour::where('factory_id', $this->factory->id)->update([
            'is_open' => true,
            'opens_at' => '07:00:00',
            'closes_at' => '18:00:00',
        ]);

        return $this->factory->fresh();
    }
}
