<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Driver;
use App\Models\Product;
use App\Models\TruckType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class BookingFlowTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Driver $driver;

    private \App\Models\Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->driver = $this->makeDriver('09123456789')->refresh();
    }

    /** @return array<string, mixed> */
    private function payload(AppointmentSlot $slot, array $overrides = []): array
    {
        return array_merge([
            'driver_name' => 'علی رضایی',
            'national_code' => '0499370899',
            'plate_two' => '12',
            'plate_letter' => 'ب',
            'plate_three' => '345',
            'plate_iran' => '67',
            'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
            'product_id' => Product::where('factory_id', $this->factory->id)->value('id'),
            'slot_id' => $slot->id,
            'idempotency_key' => 'test-'.uniqid(),
        ], $overrides);
    }

    #[Test]
    public function the_booking_page_offers_days_products_and_truck_types(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.booking.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Driver/Booking/Create')
                ->has('truckTypes', 5)
                ->has('products', 2)
                ->has('days')
                ->has('plateLetters'));
    }

    #[Test]
    public function slots_for_a_day_arrive_through_a_partial_reload(): void
    {
        $slot = $this->futureSlot($this->factory);

        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.booking.create', ['date' => $slot->date->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedDate', $slot->date->toDateString())
                ->has('slots'));
    }

    #[Test]
    public function a_driver_can_book_a_slot_end_to_end(): void
    {
        $slot = $this->futureSlot($this->factory);

        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.booking.store'), $this->payload($slot))
            ->assertRedirect()
            ->assertSessionHas('success');

        $appointment = Appointment::firstOrFail();

        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertSame($this->driver->id, $appointment->driver_id);
        $this->assertSame('12-ب-345-67', $appointment->truck->plate_key);
        $this->assertSame('علی رضایی', $this->driver->fresh()->name);
        $this->assertSame(1, $slot->fresh()->reserved_count);
    }

    #[Test]
    public function submitting_twice_with_one_idempotency_key_issues_one_appointment(): void
    {
        $slot = $this->futureSlot($this->factory);
        $payload = $this->payload($slot, ['idempotency_key' => 'double-tap']);

        $this->actingAs($this->driver, 'driver')->post(route('driver.booking.store'), $payload);
        $this->actingAs($this->driver, 'driver')->post(route('driver.booking.store'), $payload);

        $this->assertSame(1, Appointment::count());
        $this->assertSame(1, $slot->fresh()->reserved_count);
    }

    #[Test]
    public function a_full_slot_returns_a_field_error_rather_than_an_exception(): void
    {
        $slot = $this->futureSlot($this->factory);
        $slot->update(['capacity' => 0]);

        $this->actingAs($this->driver, 'driver')
            ->from(route('driver.booking.create'))
            ->post(route('driver.booking.store'), $this->payload($slot))
            ->assertRedirect(route('driver.booking.create'))
            ->assertSessionHasErrors('slot_id');

        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function an_invalid_plate_letter_is_rejected(): void
    {
        $slot = $this->futureSlot($this->factory);

        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.booking.store'), $this->payload($slot, ['plate_letter' => 'Z']))
            ->assertSessionHasErrors('plate_letter');
    }

    #[Test]
    public function the_receipt_shows_the_appointment_and_a_signed_qr_token(): void
    {
        $appointment = $this->book();

        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Driver/Appointments/Show')
                ->where('appointment.number', 1)
                ->has('qr.token'));

        $appointment->refresh();
        $this->assertNotNull($appointment->qr_token_hash);
    }

    #[Test]
    public function the_qr_token_is_signed_scoped_and_not_the_raw_id(): void
    {
        $appointment = $this->book();

        $response = $this->actingAs($this->driver, 'driver')
            ->get(route('driver.appointments.show', $appointment));

        $token = $response->viewData('page')['props']['qr']['token'];

        $this->assertSame(['ulid' => $appointment->ulid], QrToken::parse($token));
        $this->assertTrue(QrToken::matches($appointment->fresh(), $token));

        // دستکاری امضا باید توکن را باطل کند
        $this->assertNull(QrToken::parse(substr($token, 0, -1).'x'));

        // ساختن توکن با حدس‌زدن شناسه ممکن نیست: بدون امضا هیچ‌چیز پذیرفته نمی‌شود
        $this->assertNull(QrToken::parse("v1.{$appointment->ulid}.nonce.".(time() + 3600)));
        $this->assertNull(QrToken::parse((string) $appointment->id));
        $this->assertNull(QrToken::parse($appointment->ulid));

        // توکن یک نوبت نباید روی نوبت دیگری بنشیند
        $other = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09120000077'),
            $this->makeTruck('88', 'ج', '888', '88'),
            $this->futureSlot($this->factory, 1),
        ));

        $this->assertFalse(QrToken::matches($other, $token));
    }

    #[Test]
    public function a_driver_cannot_open_someone_elses_appointment(): void
    {
        $appointment = $this->book();
        $other = $this->makeDriver('09120000099')->refresh();

        $this->actingAs($other, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->assertNotFound();
    }

    #[Test]
    public function a_driver_can_cancel_before_arriving_and_the_slot_is_freed(): void
    {
        $appointment = $this->book();
        $slot = $appointment->slot;

        $this->assertSame(1, $slot->fresh()->reserved_count);

        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.appointments.cancel', $appointment))
            ->assertRedirect(route('driver.home'));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
        $this->assertSame(0, $slot->fresh()->reserved_count);
    }

    #[Test]
    public function a_driver_cannot_cancel_once_the_truck_is_on_site(): void
    {
        $appointment = $this->book();
        $appointment->forceFill(['status' => AppointmentStatus::CheckedIn])->save();

        $this->actingAs($this->driver, 'driver')
            ->from(route('driver.appointments.show', $appointment))
            ->post(route('driver.appointments.cancel', $appointment))
            ->assertRedirect(route('driver.appointments.show', $appointment))
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    #[Test]
    public function the_home_page_lists_active_appointments_with_queue_position(): void
    {
        $this->book();

        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Driver/Home')
                ->has('active', 1)
                ->has('active.0.ahead')
                ->where('canBook', true));
    }

    #[Test]
    public function booking_is_blocked_once_the_active_limit_is_reached(): void
    {
        $this->factory->update(['max_active_per_mobile' => 1]);
        $this->book();

        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.booking.create'))
            ->assertRedirect(route('driver.home'))
            ->assertSessionHas('error');
    }

    private function book(): Appointment
    {
        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->driver,
            $this->makeTruck('12', 'ب', '345', '67'),
            $this->futureSlot($this->factory),
        ));
    }
}
