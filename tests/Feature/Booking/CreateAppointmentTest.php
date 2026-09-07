<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class CreateAppointmentTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private CreateAppointment $create;

    protected function setUp(): void
    {
        parent::setUp();
        $this->create = app(CreateAppointment::class);
    }

    #[Test]
    public function it_issues_an_appointment_the_system_scheduled(): void
    {
        $factory = $this->seedFactory();
        $driver = $this->makeDriver('09123456789');
        $truck = $this->makeTruck('12', 'ب', '345', '67');

        $appointment = ($this->create)($this->booking($factory, $driver, $truck));

        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertSame(1, $appointment->number);
        $this->assertNotNull($appointment->ulid);

        // ساعت را سامانه گذاشته، نه راننده — و روی مضرب پنج دقیقه
        $this->assertSame(0, (int) substr((string) $appointment->start_time, 6, 2));
        $this->assertSame(0, ((int) substr((string) $appointment->start_time, 3, 2)) % 5);
        $this->assertGreaterThanOrEqual(1, (int) $appointment->line_no);

        // اولین انتقال هم ثبت شده باشد
        $this->assertDatabaseHas('appointment_transitions', [
            'appointment_id' => $appointment->id,
            'to_status' => AppointmentStatus::Booked->value,
        ]);

        // کامیون به راننده وصل شده باشد
        $this->assertTrue($driver->trucks()->whereKey($truck->id)->exists());
    }

    #[Test]
    public function appointment_numbers_are_a_daily_sequence(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);

        $first = ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000011'), $this->makeTruck('11', 'ب', '111', '11')));

        $second = ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000012'), $this->makeTruck('22', 'ج', '222', '22')));

        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
    }

    #[Test]
    public function the_same_idempotency_key_never_issues_a_second_appointment(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);
        $driver = $this->makeDriver('09123456789');
        $truck = $this->makeTruck('12', 'ب', '345', '67');

        $key = 'idem-'.uniqid();

        $first = ($this->create)($this->booking($factory, $driver, $truck, idempotencyKey: $key));
        $second = ($this->create)($this->booking($factory, $driver, $truck, idempotencyKey: $key));
        $third = ($this->create)($this->booking($factory, $driver, $truck, idempotencyKey: $key));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, Appointment::count());
    }

    #[Test]
    public function each_appointment_starts_where_the_previous_one_ended(): void
    {
        $factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($factory);

        // یک لاین: صف کاملاً سریالی می‌شود و ترتیب قابل بررسی است
        $factory->update(['loading_lines' => 1]);

        $first = ($this->create)($this->booking(
            $factory->fresh(), $this->makeDriver('09120000101'), $this->makeTruck('11', 'ب', '111', '11')));

        $second = ($this->create)($this->booking(
            $factory->fresh(), $this->makeDriver('09120000102'), $this->makeTruck('22', 'ج', '222', '22')));

        $this->assertSame($first->end_time, $second->start_time);
        $this->assertSame(1, (int) $second->line_no);
    }

    #[Test]
    public function parallel_lines_run_side_by_side(): void
    {
        $factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($factory);
        $factory->update(['loading_lines' => 2]);

        $first = ($this->create)($this->booking(
            $factory->fresh(), $this->makeDriver('09120000111'), $this->makeTruck('11', 'ب', '111', '11')));

        $second = ($this->create)($this->booking(
            $factory->fresh(), $this->makeDriver('09120000112'), $this->makeTruck('22', 'ج', '222', '22')));

        // دو لاین یعنی دو کامیون هم‌زمان، نه یکی پشت دیگری
        $this->assertSame($first->start_time, $second->start_time);
        $this->assertNotSame((int) $first->line_no, (int) $second->line_no);
    }

    #[Test]
    public function a_day_that_cannot_fit_the_truck_rolls_to_the_next_one(): void
    {
        $factory = $this->seedFactory();
        $morning = $this->freezeOnWorkingMorning($factory);

        // یک لاین، و باری که تقریباً تمام روز را می‌گیرد. مدت از محصول
        // خوانده می‌شود و نه از میانگین کارخانه — همان ترتیبی که
        // Appointment::expectedLoadingMinutes() دارد.
        $factory->update(['loading_lines' => 1]);
        \App\Models\Product::where('factory_id', $factory->id)->update(['loading_minutes' => 600]);

        $first = ($this->create)($this->booking(
            $factory->fresh(), $this->makeDriver('09120000121'), $this->makeTruck('11', 'ب', '111', '11')));

        $second = ($this->create)($this->booking(
            $factory->fresh(), $this->makeDriver('09120000122'), $this->makeTruck('22', 'ج', '222', '22')));

        $this->assertSame($morning->toDateString(), $first->date->toDateString());
        $this->assertTrue($second->date->greaterThan($first->date), 'نوبت دوم باید به روز بعد بیفتد');
    }

    #[Test]
    public function a_plate_cannot_hold_more_than_the_configured_active_appointments(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['max_active_per_plate' => 1]);

        $truck = $this->makeTruck('12', 'ب', '345', '67');

        ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000021'), $truck));

        $this->expectExceptionMessageMatches('/پلاک/');

        ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000022'), $truck));
    }

    #[Test]
    public function a_mobile_cannot_hold_more_than_the_configured_active_appointments(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['max_active_per_mobile' => 1]);

        $driver = $this->makeDriver('09123456789');

        ($this->create)($this->booking(
            $factory, $driver, $this->makeTruck('11', 'ب', '111', '11')));

        $this->expectExceptionMessageMatches('/موبایل/');

        ($this->create)($this->booking(
            $factory, $driver, $this->makeTruck('22', 'ج', '222', '22')));
    }

    #[Test]
    public function the_lead_time_pushes_the_appointment_past_today(): void
    {
        $factory = $this->seedFactory();
        $today = $this->freezeOnWorkingMorning($factory);

        // مهلت رسیدن ۴۸ ساعت: نه امروز، نه فردا
        $factory->update(['booking_lead_minutes' => 60 * 48]);

        $appointment = ($this->create)($this->booking(
            $factory->fresh(),
            $this->makeDriver('09120000031'),
            $this->makeTruck('44', 'س', '444', '44'),
        ));

        $this->assertTrue(
            $appointment->startsAt()->greaterThanOrEqualTo($today->addHours(48)),
            'مهلت رسیدن باید روی هر روز اعمال شود، نه فقط امروز',
        );
    }

    #[Test]
    public function it_says_so_when_the_whole_horizon_is_out_of_room(): void
    {
        $factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($factory);

        // افق یک روزه و کامیونی که بیش از یک روز کاری طول می‌کشد:
        // هیچ روزی در افق جا ندارد.
        $factory->update(['booking_horizon_days' => 1, 'loading_lines' => 1]);
        \App\Models\Product::where('factory_id', $factory->id)->update(['loading_minutes' => 60 * 24]);

        $this->expectExceptionMessageMatches('/روز آینده/');

        ($this->create)($this->booking(
            $factory->fresh(),
            $this->makeDriver('09120000041'),
            $this->makeTruck('55', 'ص', '555', '55'),
        ));
    }

    #[Test]
    public function a_blocked_driver_cannot_book(): void
    {
        $factory = $this->seedFactory();
        $driver = $this->makeDriver('09123456789');
        $driver->update(['is_blocked' => true, 'blocked_reason' => 'بدهی معوق']);

        $this->expectExceptionMessage('بدهی معوق');

        ($this->create)($this->booking(
            $factory, $driver->fresh(), $this->makeTruck('66', 'ط', '666', '66')));
    }
}
