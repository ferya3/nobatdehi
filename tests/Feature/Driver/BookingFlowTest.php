<?php

declare(strict_types=1);

namespace Tests\Feature\Driver;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Models\Appointment;
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

    /**
     * درخواست نوبت — بدون ساعت.
     *
     * slot_id حذف شد چون راننده ساعتی انتخاب نمی‌کند و سرور هم چنین فیلدی
     * را نمی‌پذیرد.
     *
     * @return array<string, mixed>
     */
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
            'product_id' => Product::where('factory_id', $this->factory->id)->value('id'),
            'idempotency_key' => 'test-'.uniqid(),
        ], $overrides);
    }

    #[Test]
    public function the_booking_page_announces_a_time_instead_of_offering_a_choice(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->get(route('driver.booking.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Driver/Booking/Create')
                ->has('truckTypes', 5)
                ->has('products', 2)
                ->has('plateLetters')
                // نه فهرست روز، نه فهرست ساعت — انتخابی در کار نیست
                ->missing('days')
                ->missing('slots')
                // به‌جایش: نوبتی که همین حالا به هر نوع خودرو می‌رسد
                ->has('truckTypes.0.opening.starts_at')
                ->has('truckTypes.0.opening.date'));
    }

    #[Test]
    public function a_driver_books_and_the_system_names_the_time(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.booking.store'), $this->payload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $appointment = Appointment::firstOrFail();

        $this->assertSame(AppointmentStatus::Booked, $appointment->status);
        $this->assertSame($this->driver->id, $appointment->driver_id);
        $this->assertSame('12-ب-345-67', $appointment->truck->plate_key);
        $this->assertSame('علی رضایی', $this->driver->fresh()->name);

        // ساعت را سرور گذاشته و طولش از نوع خودرو آمده
        $this->assertNotNull($appointment->start_time);
        $this->assertTrue($appointment->startsAt()->lessThan($appointment->startsAt()->addDay()));
        $this->assertGreaterThanOrEqual(1, (int) $appointment->line_no);
    }

    #[Test]
    public function submitting_twice_with_one_idempotency_key_issues_one_appointment(): void
    {
        $payload = $this->payload(['idempotency_key' => 'double-tap']);

        $this->actingAs($this->driver, 'driver')->post(route('driver.booking.store'), $payload);
        $this->actingAs($this->driver, 'driver')->post(route('driver.booking.store'), $payload);

        $this->assertSame(1, Appointment::count());
    }

    #[Test]
    public function running_out_of_room_returns_a_field_error_rather_than_an_exception(): void
    {
        // افق یک روزه و خودرویی که بیش از یک روز کاری بارگیری می‌خواهد.
        // مدت از نوع خودرو خوانده می‌شود و نه از محصول — همان ترتیبی که
        // Appointment::expectedLoadingMinutes() دارد.
        $this->factory->update(['booking_horizon_days' => 1, 'loading_lines' => 1]);
        TruckType::where('code', 'teriler')->update(['loading_minutes' => 60 * 24]);

        $this->actingAs($this->driver, 'driver')
            ->from(route('driver.booking.create'))
            ->post(route('driver.booking.store'), $this->payload())
            ->assertRedirect(route('driver.booking.create'))
            // «جا نیست» به نوع خودرو می‌چسبد؛ تنها چیزی که راننده می‌تواند عوض کند
            ->assertSessionHasErrors('truck_type_id');

        $this->assertSame(0, Appointment::count());
    }

    #[Test]
    public function an_invalid_plate_letter_is_rejected(): void
    {
        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.booking.store'), $this->payload(['plate_letter' => 'Z']))
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
    public function a_driver_can_cancel_before_arriving(): void
    {
        $appointment = $this->book();

        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.appointments.cancel', $appointment))
            ->assertRedirect(route('driver.home'));

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->fresh()->status);
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
        ));
    }
}
