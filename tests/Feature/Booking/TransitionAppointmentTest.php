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
    public function leaving_the_active_set_records_who_closed_it(): void
    {
        $appointment = $this->bookOne();
        $place = [$appointment->date->toDateString(), (int) $appointment->line_no, $appointment->start_time];

        ($this->transition)($appointment, S::Cancelled, Actor::user($this->operator()), 'انصراف راننده');

        $closed = $appointment->fresh();

        $this->assertSame('انصراف راننده', $closed->cancel_reason);
        $this->assertSame(S::Cancelled, $closed->status);

        // جای نوبت با لغو آزاد نمی‌شود — همان‌جا می‌ماند
        $this->assertSame($place, [$closed->date->toDateString(), (int) $closed->line_no, $closed->start_time]);
    }

    #[Test]
    public function a_cancelled_appointment_keeps_its_place_in_the_schedule(): void
    {
        $factory = $this->seedFactory();
        $create = app(CreateAppointment::class);
        $actor = Actor::user($this->operator());

        $appointments = [];

        foreach (range(0, 3) as $i) {
            $appointments[] = $create($this->booking(
                $factory,
                $this->makeDriver('0912100000'.$i),
                $this->makeTruck(str_pad((string) (20 + $i), 2, '0', STR_PAD_LEFT), 'ب', '345', '67'),
            ));
        }

        $cancelled = $appointments[0];
        $place = [$cancelled->date->toDateString(), $cancelled->line_no, $cancelled->start_time];

        ($this->transition)($cancelled, S::Cancelled, $actor);
        ($this->transition)($appointments[1], S::Waiting, $actor);
        ($this->transition)($appointments[2], S::Waiting, $actor);
        ($this->transition)($appointments[2], S::NoShow, $actor);

        // جای خالیِ نوبتِ لغوشده به کسی داده نمی‌شود: راننده‌ای که ساعتش را
        // پیامک گرفته، نباید ببیند نوبتش جلو افتاده.
        $newcomer = $create($this->booking(
            $factory, $this->makeDriver('09121000099'), $this->makeTruck('99', 'د', '999', '67')));

        $this->assertNotSame(
            $place,
            [$newcomer->date->toDateString(), $newcomer->line_no, $newcomer->start_time],
        );

        $this->assertSame(2, Appointment::whereIn('status', S::activeValues())
            ->whereKeyNot($newcomer->id)
            ->count());
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
    public function a_cancelled_appointment_can_be_revived_because_its_place_was_kept(): void
    {
        $factory = $this->seedFactory();
        $create = app(CreateAppointment::class);

        $first = $create($this->booking(
            $factory, $this->makeDriver('09121110001'), $this->makeTruck('31', 'ب', '311', '67')));

        $place = [$first->date->toDateString(), (int) $first->line_no, $first->start_time];

        $manager = $this->operator();
        $manager->givePermissionTo(Permissions::APPOINTMENTS_ROLLBACK);
        $manager->refresh();

        ($this->transition)($first, S::Cancelled, Actor::user($manager));

        // نفر بعدی جای او را نمی‌گیرد
        $create($this->booking(
            $factory, $this->makeDriver('09121110002'), $this->makeTruck('32', 'ج', '312', '67')));

        ($this->transition)($first->fresh(), S::Booked, Actor::user($manager));

        $revived = $first->fresh();

        $this->assertSame(S::Booked, $revived->status);
        $this->assertSame($place, [$revived->date->toDateString(), (int) $revived->line_no, $revived->start_time]);
    }
}
