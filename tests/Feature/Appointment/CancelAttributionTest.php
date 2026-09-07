<?php

declare(strict_types=1);

namespace Tests\Feature\Appointment;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * پنل باید بداند چه کسی نوبت را بست — راننده، اپراتور، یا زمان‌بند شبانه.
 */
final class CancelAttributionTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->driver = $this->makeDriver('09123456789')->refresh();
    }

    /** User::factory() رابطه‌ی «کارخانه» است، نه Model Factory — پس با create می‌سازیم. */
    private function staff(string $name, string $email, string $role): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role));

        return $user->fresh();
    }

    private function book(): Appointment
    {
        return app(CreateAppointment::class)(
            $this->booking(
                $this->factory,
                $this->driver,
                $this->makeTruck('12', 'ب', '345', '11'),
            ),
        );
    }

    #[Test]
    public function test_a_driver_cancelling_is_recorded_as_the_driver(): void
    {
        $appointment = $this->book();

        $this->actingAs($this->driver, 'driver')
            ->post(route('driver.appointments.cancel', $appointment))
            ->assertRedirect(route('driver.home'));

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertSame(Actor::TYPE_DRIVER, $appointment->cancelled_by_type);
        $this->assertSame('لغو توسط راننده', $appointment->cancelledByLabel());
    }

    #[Test]
    public function test_an_operator_cancelling_is_recorded_by_name(): void
    {
        $appointment = $this->book();

        $operator = $this->staff('مریم احمدی', 'maryam@test.local', Roles::OPERATOR);

        app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::Cancelled,
            Actor::user($operator),
            'درخواست واحد فروش',
        );

        $appointment->refresh();

        $this->assertSame(Actor::TYPE_STAFF, $appointment->cancelled_by_type);
        $this->assertSame('لغو توسط مریم احمدی', $appointment->cancelledByLabel());
        $this->assertSame('درخواست واحد فروش', $appointment->cancel_reason);
    }

    #[Test]
    public function test_a_no_show_by_the_system_reads_as_a_no_show_not_a_cancellation(): void
    {
        $appointment = $this->book();

        $appointment = app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::Waiting,
            Actor::system(),
        );

        app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::NoShow,
            Actor::system(),
        );

        $this->assertSame('ثبت عدم حضور توسط سامانه', $appointment->refresh()->cancelledByLabel());
    }

    #[Test]
    public function test_reopening_an_appointment_clears_the_stale_attribution(): void
    {
        $appointment = $this->book();

        app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::Cancelled,
            Actor::driver($this->driver),
        );

        // مدیری با دسترسی rollback نوبت را برمی‌گرداند
        $manager = $this->staff('مدیر', 'manager@test.local', Roles::OPERATOR);
        $manager->givePermissionTo('appointments.rollback');

        app(TransitionAppointment::class)(
            $appointment->refresh(),
            AppointmentStatus::Booked,
            Actor::user($manager->fresh()),
        );

        $appointment->refresh();

        $this->assertNull($appointment->cancelled_by_type);
        $this->assertNull($appointment->cancel_reason);
        $this->assertNull($appointment->cancelledByLabel());
    }

    #[Test]
    public function test_the_queue_panel_shows_who_cancelled(): void
    {
        $appointment = $this->book();

        app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::Cancelled,
            Actor::driver($this->driver),
        );

        $operator = $this->staff('اپراتور', 'op@test.local', Roles::OPERATOR);

        $this->actingAs($operator)
            ->get(route('staff.queue.show', $appointment))
            ->assertInertia(fn ($page) => $page
                ->where('appointment.cancelled_by', Actor::TYPE_DRIVER)
                ->where('appointment.cancelled_by_label', 'لغو توسط راننده'));
    }
}
