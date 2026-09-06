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
    public function it_issues_an_appointment_and_consumes_slot_capacity(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);
        $driver = $this->makeDriver('09123456789');
        $truck = $this->makeTruck('12', 'ب', '345', '67');

        $appointment = ($this->create)($this->booking($factory, $driver, $truck, $slot));

        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertSame(1, $appointment->number);
        $this->assertNotNull($appointment->ulid);
        $this->assertSame(1, $slot->fresh()->reserved_count);

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
            $factory, $this->makeDriver('09120000011'), $this->makeTruck('11', 'ب', '111', '11'), $slot
        ));

        $second = ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000012'), $this->makeTruck('22', 'ج', '222', '22'), $slot
        ));

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

        $first = ($this->create)($this->booking($factory, $driver, $truck, $slot, idempotencyKey: $key));
        $second = ($this->create)($this->booking($factory, $driver, $truck, $slot, idempotencyKey: $key));
        $third = ($this->create)($this->booking($factory, $driver, $truck, $slot, idempotencyKey: $key));

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, Appointment::count());
        $this->assertSame(1, $slot->fresh()->reserved_count);
    }

    #[Test]
    public function it_refuses_to_overbook_a_slot(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);
        $slot->update(['capacity' => 2]);

        foreach ([['11', 'ب', '111', '11'], ['22', 'ج', '222', '22']] as $i => $plate) {
            ($this->create)($this->booking(
                $factory,
                $this->makeDriver('0912000010'.$i),
                $this->makeTruck(...$plate),
                $slot,
            ));
        }

        $this->expectException(BookingException::class);

        ($this->create)($this->booking(
            $factory,
            $this->makeDriver('09120000199'),
            $this->makeTruck('33', 'د', '333', '33'),
            $slot,
        ));
    }

    #[Test]
    public function a_plate_cannot_hold_more_than_the_configured_active_appointments(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['max_active_per_plate' => 1]);

        $truck = $this->makeTruck('12', 'ب', '345', '67');

        ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000021'), $truck, $this->futureSlot($factory, 0)
        ));

        $this->expectExceptionMessageMatches('/پلاک/');

        ($this->create)($this->booking(
            $factory, $this->makeDriver('09120000022'), $truck, $this->futureSlot($factory, 1)
        ));
    }

    #[Test]
    public function a_mobile_cannot_hold_more_than_the_configured_active_appointments(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['max_active_per_mobile' => 1]);

        $driver = $this->makeDriver('09123456789');

        ($this->create)($this->booking(
            $factory, $driver, $this->makeTruck('11', 'ب', '111', '11'), $this->futureSlot($factory, 0)
        ));

        $this->expectExceptionMessageMatches('/موبایل/');

        ($this->create)($this->booking(
            $factory, $driver, $this->makeTruck('22', 'ج', '222', '22'), $this->futureSlot($factory, 1)
        ));
    }

    #[Test]
    public function it_refuses_a_slot_inside_the_lead_time(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);

        // افق را طوری می‌بندیم که فردا هم «خیلی نزدیک» حساب شود
        $factory->update(['booking_lead_minutes' => 60 * 48]);

        $this->expectException(BookingException::class);

        ($this->create)($this->booking(
            $factory->fresh(),
            $this->makeDriver('09120000031'),
            $this->makeTruck('44', 'س', '444', '44'),
            $slot,
        ));
    }

    #[Test]
    public function it_refuses_a_slot_beyond_the_booking_horizon(): void
    {
        $factory = $this->seedFactory();
        $far = CarbonImmutable::today()->addDays(30);
        (new \App\Domain\Slot\SlotGenerator())->generateForDate($factory, $far);

        $slot = \App\Models\AppointmentSlot::where('factory_id', $factory->id)
            ->whereDate('date', $far->toDateString())
            ->orderBy('start_time')
            ->first();

        if ($slot === null) {
            $this->markTestSkipped('روز انتخابی تعطیل بود.');
        }

        $this->expectExceptionMessageMatches('/روز آینده/');

        ($this->create)($this->booking(
            $factory,
            $this->makeDriver('09120000041'),
            $this->makeTruck('55', 'ص', '555', '55'),
            $slot,
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
            $factory, $driver->fresh(), $this->makeTruck('66', 'ط', '666', '66'), $this->futureSlot($factory)
        ));
    }
}
