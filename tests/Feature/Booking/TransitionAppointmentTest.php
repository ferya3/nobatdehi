<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class TransitionAppointmentTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private TransitionAppointment $transition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transition = app(TransitionAppointment::class);
    }

    private function bookOne(): Appointment
    {
        $factory = $this->seedFactory();

        return app(CreateAppointment::class)($this->booking(
            $factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '67'),
            $this->futureSlot($factory),
        ));
    }

    private function operator(string $email = 'op@test.local'): User
    {
        $user = User::create([
            'name' => 'اپراتور',
            'email' => $email,
            'password' => 'secret-password',
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(\App\Domain\Access\Roles::OPERATOR));

        return $user;
    }

    #[Test]
    public function it_walks_the_happy_path_and_stamps_every_step(): void
    {
        $appointment = $this->bookOne();
        $actor = Actor::user($this->operator(), '10.0.0.1');

        foreach ([S::Waiting, S::Called, S::CheckedIn, S::Loading, S::Loaded, S::Completed] as $status) {
            if ($status === S::Loading) {
                $this->recordTare($appointment);
            }

            if ($status === S::Completed) {
                $this->recordGross($appointment);
            }

            $appointment = ($this->transition)($appointment, $status, $actor);
        }

        $this->assertSame(S::Completed, $appointment->status);
        $this->assertNotNull($appointment->waiting_at);
        $this->assertNotNull($appointment->called_at);
        $this->assertNotNull($appointment->checked_in_at);
        $this->assertNotNull($appointment->loading_started_at);
        $this->assertNotNull($appointment->loading_completed_at);
        $this->assertNotNull($appointment->completed_at);

        // شش انتقال + یکی برای خود صدور نوبت
        $this->assertSame(7, $appointment->transitions()->count());
    }

    #[Test]
    public function leaving_the_active_set_frees_slot_capacity(): void
    {
        $appointment = $this->bookOne();
        $slot = $appointment->slot;

        $this->assertSame(1, $slot->fresh()->reserved_count);

        ($this->transition)($appointment, S::Cancelled, Actor::user($this->operator()), 'انصراف راننده');

        $this->assertSame(0, $slot->fresh()->reserved_count);
        $this->assertSame('انصراف راننده', $appointment->fresh()->cancel_reason);
    }

    #[Test]
    public function reserved_count_always_equals_the_number_of_active_appointments(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);
        $create = app(CreateAppointment::class);
        $actor = Actor::user($this->operator());

        $appointments = [];

        foreach (range(0, 3) as $i) {
            $appointments[] = $create($this->booking(
                $factory,
                $this->makeDriver('0912100000'.$i),
                $this->makeTruck(str_pad((string) (20 + $i), 2, '0', STR_PAD_LEFT), 'ب', '345', '67'),
                $slot,
            ));
        }

        ($this->transition)($appointments[0], S::Cancelled, $actor);
        ($this->transition)($appointments[1], S::Waiting, $actor);
        ($this->transition)($appointments[2], S::Waiting, $actor);
        ($this->transition)($appointments[2], S::NoShow, $actor);

        $active = Appointment::where('slot_id', $slot->id)
            ->whereIn('status', S::activeValues())
            ->count();

        $this->assertSame($active, $slot->fresh()->reserved_count);
        $this->assertSame(2, $active);
    }

    #[Test]
    public function an_operator_cannot_roll_a_state_backwards(): void
    {
        $appointment = $this->bookOne();
        $actor = Actor::user($this->operator());

        foreach ([S::Waiting, S::Called, S::CheckedIn, S::Loading] as $status) {
            if ($status === S::Loading) {
                $this->recordTare($appointment);
            }

            $appointment = ($this->transition)($appointment, $status, $actor);
        }

        $this->expectException(InvalidStateTransition::class);

        ($this->transition)($appointment, S::CheckedIn, $actor);
    }

    #[Test]
    public function a_manager_with_the_rollback_permission_can(): void
    {
        $appointment = $this->bookOne();
        $operator = $this->operator();

        foreach ([S::Waiting, S::Called, S::CheckedIn, S::Loading] as $status) {
            if ($status === S::Loading) {
                $this->recordTare($appointment);
            }

            $appointment = ($this->transition)($appointment, $status, Actor::user($operator));
        }

        $manager = $this->operator('manager@test.local');
        $manager->givePermissionTo(Permissions::APPOINTMENTS_ROLLBACK);
        $manager->refresh();

        $rolled = ($this->transition)($appointment, S::CheckedIn, Actor::user($manager), 'اشتباه اپراتور');

        $this->assertSame(S::CheckedIn, $rolled->status);
        $this->assertDatabaseHas('appointment_transitions', [
            'appointment_id' => $appointment->id,
            'to_status' => S::CheckedIn->value,
            'is_rollback' => true,
            'reason' => 'اشتباه اپراتور',
        ]);
    }

    #[Test]
    public function reviving_a_cancelled_appointment_fails_when_the_slot_filled_up(): void
    {
        $factory = $this->seedFactory();
        $slot = $this->futureSlot($factory);
        $slot->update(['capacity' => 1]);

        $create = app(CreateAppointment::class);

        $first = $create($this->booking(
            $factory, $this->makeDriver('09121110001'), $this->makeTruck('31', 'ب', '311', '67'), $slot
        ));

        $manager = $this->operator();
        $manager->givePermissionTo(Permissions::APPOINTMENTS_ROLLBACK);
        $manager->refresh();

        ($this->transition)($first, S::Cancelled, Actor::user($manager));

        // جای خالی را نفر بعدی برمی‌دارد
        $create($this->booking(
            $factory, $this->makeDriver('09121110002'), $this->makeTruck('32', 'ج', '312', '67'), $slot
        ));

        $this->expectException(BookingException::class);

        ($this->transition)($first->fresh(), S::Booked, Actor::user($manager));
    }
}
