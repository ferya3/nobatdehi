<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Domain\Slot\SlotGenerator;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\TruckType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * انتخاب روز، از سمت راننده.
 *
 * چیزی که بیش از همه باید نگه داشته شود این است: انتخابِ راننده یک *کف* است
 * و نه یک زمان. اگر ساعتِ قطعی از درخواست خوانده می‌شد، هر کسی می‌توانست
 * نوبتی روی لاینِ اشغال بنشاند.
 */
final class BookingDayChoiceTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
        $this->driver = $this->makeDriver('09123456789')->refresh();
    }

    private function nextOpenDay(): CarbonImmutable
    {
        $slots = app(SlotGenerator::class);
        $date = CarbonImmutable::today()->addDay();

        while ($slots->planFor($this->factory, $date) === null) {
            $date = $date->addDay();
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'driver_name' => 'علی رضایی',
            'national_code' => '0499370899',
            'plate_two' => '12',
            'plate_letter' => 'ب',
            'plate_three' => '345',
            'plate_iran' => '67',
            'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
            'product_id' => Product::where('factory_id', $this->factory->id)->orderBy('id')->value('id'),
            'idempotency_key' => 'choice-'.uniqid(),
        ], $overrides);
    }

    #[Test]
    public function the_page_offers_days_from_tomorrow_and_never_today(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.booking.create'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $days = collect($page->toArray()['props']['bookableDays']);

                $this->assertNotEmpty($days, 'هیچ روزی برای انتخاب پیشنهاد نشد.');

                $this->assertFalse(
                    $days->contains('date', CarbonImmutable::today()->toDateString()),
                    'امروز نباید قابل انتخاب باشد — صفِ امروز را سامانه می‌چیند.',
                );

                // برچسب شمسی لازم است: مرورگر تاریخ شمسی نمی‌سازد
                $this->assertNotEmpty($days->first()['jalali']);
                $this->assertNotEmpty($days->first()['day_label']);
            });
    }

    #[Test]
    public function booking_without_a_choice_still_gets_the_soonest_opening(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.booking.store'), $this->payload())
            ->assertRedirect();

        $this->assertTrue(Appointment::firstOrFail()->date->isToday());
    }

    #[Test]
    public function a_driver_can_book_a_later_day_and_hour(): void
    {
        $day = $this->nextOpenDay();

        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.booking.store'), $this->payload([
                'preferred_date' => $day->toDateString(),
                'preferred_time' => '10:00',
            ]))
            ->assertRedirect();

        $appointment = Appointment::firstOrFail();

        $this->assertSame($day->toDateString(), $appointment->date->toDateString());
        $this->assertSame('10:00:00', (string) $appointment->start_time);
    }

    #[Test]
    public function asking_for_today_is_refused_by_validation(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->from(route('driver.booking.create'))
            ->post(route('driver.booking.store'), $this->payload([
                'preferred_date' => CarbonImmutable::today()->toDateString(),
                'preferred_time' => '10:00',
            ]))
            ->assertSessionHasErrors('preferred_date');

        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function a_day_without_an_hour_is_refused(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->from(route('driver.booking.create'))
            ->post(route('driver.booking.store'), $this->payload([
                'preferred_date' => $this->nextOpenDay()->toDateString(),
            ]))
            ->assertSessionHasErrors('preferred_time');
    }

    #[Test]
    public function the_openings_endpoint_reports_the_day_honestly(): void
    {
        $day = $this->nextOpenDay();

        $response = $this->actingAs($this->driver, 'driver')
            ->getJson(route('driver.booking.openings', [
                'date' => $day->toDateString(),
                'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
            ]))
            ->assertOk();

        $windows = $response->json('windows');

        $this->assertNotEmpty($windows);
        $this->assertArrayHasKey('available', $windows[0]);
        $this->assertArrayHasKey('starts_at', $windows[0]);
    }

    #[Test]
    public function the_openings_endpoint_refuses_today(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->getJson(route('driver.booking.openings', [
                'date' => CarbonImmutable::today()->toDateString(),
                'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
            ]))
            ->assertStatus(422);
    }

    #[Test]
    public function a_stranger_cannot_read_the_openings(): void
    {
        $this->getJson(route('driver.booking.openings', [
            'date' => $this->nextOpenDay()->toDateString(),
            'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
        ]))->assertStatus(401);
    }

    #[Test]
    public function an_hour_that_is_full_comes_back_as_an_error_on_the_hour_field(): void
    {
        // خطا باید به فیلدی بچسبد که راننده می‌تواند عوضش کند
        $day = $this->nextOpenDay();

        [, $closesAt] = app(SlotGenerator::class)->planFor($this->factory, $day);

        $this->actingAs($this->driver, 'driver')
            ->from(route('driver.booking.create'))
            ->post(route('driver.booking.store'), $this->payload([
                'preferred_date' => $day->toDateString(),
                'preferred_time' => $closesAt->subMinutes(5)->format('H:i'),
            ]))
            ->assertSessionHasErrors('preferred_time');
    }
}
