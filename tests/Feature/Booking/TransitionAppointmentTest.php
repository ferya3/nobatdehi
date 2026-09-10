<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Access\Permissions;
use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
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

        $user->assignRole(Role::findByName(Roles::OPERATOR));

        return $user;
    }

    #[Test]
    public function it_walks_the_happy_path_and_stamps_every_step(): void
    {
        $appointment = $this->bookOne();
        $actor = Actor::user($this->operator(), '10.0.0.1');

        foreach ([S::Called, S::CheckedIn, S::Loading, S::Loaded, S::Completed] as $status) {
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

        // پنج انتقال + یکی برای خود صدور نوبت. نوبت از همان اول «در انتظار»
        // صادر می‌شود، پس قدمِ جداگانه‌ای برای آن وجود ندارد.
        $this->assertSame(6, $appointment->transitions()->count());
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

    /**
     * لغو، بازه را برمی‌گرداند — ولی هیچ نوبتِ زنده‌ای جابه‌جا نمی‌شود.
     *
     * این دو با هم می‌آیند و جدا کردنشان همان جایی است که قبلاً اشتباه شد:
     * برای اینکه راننده‌ی ساعت ۱۰:۲۰ جلو نیفتد، بازه‌ی لغوشده هم قفل ماند و
     * ظرفیت روز کم‌کم سوخت.
     */
    #[Test]
    public function a_cancelled_slot_goes_back_into_the_pool_without_moving_anyone(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['loading_lines' => 1]);
        $factory = $factory->fresh();

        $create = app(CreateAppointment::class);
        $actor = Actor::user($this->operator());

        $appointments = [];

        foreach (range(0, 2) as $i) {
            $appointments[] = $create($this->booking(
                $factory,
                $this->makeDriver('0912300000'.$i),
                $this->makeTruck(str_pad((string) (20 + $i), 2, '0', STR_PAD_LEFT), 'ب', '345', '67'),
            ));
        }

        $cancelled = $appointments[0];
        $place = [$cancelled->date->toDateString(), $cancelled->line_no, $cancelled->start_time];

        // ساعتِ نوبت‌های بعدی، پیش از لغو
        $untouched = collect($appointments)->skip(1)
            ->map(fn (Appointment $a) => [$a->id, $a->start_time])->all();

        ($this->transition)($cancelled, S::Cancelled, $actor);

        // بازه‌ی آزادشده به نوبت تازه می‌رسد
        $newcomer = $create($this->booking(
            $factory, $this->makeDriver('09123000099'), $this->makeTruck('99', 'د', '999', '67')));

        $this->assertSame(
            $place,
            [$newcomer->date->toDateString(), $newcomer->line_no, $newcomer->start_time],
        );

        // و کسی که در صف بود، سر جای خودش ماند
        foreach ($untouched as [$id, $startTime]) {
            $this->assertSame($startTime, Appointment::findOrFail($id)->start_time);
        }
    }

    #[Test]
    public function a_no_show_frees_its_slot_too_but_a_completed_one_does_not(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['loading_lines' => 1]);
        $factory = $factory->fresh();

        $create = app(CreateAppointment::class);
        $actor = Actor::user($this->operator());

        $first = $create($this->booking(
            $factory, $this->makeDriver('09123000010'), $this->makeTruck('31', 'ب', '345', '67')));

        ($this->transition)($first, S::Waiting, $actor);
        ($this->transition)($first, S::NoShow, $actor);

        // کامیون نیامد؛ لاین آن ساعت خالی ماند
        $second = $create($this->booking(
            $factory, $this->makeDriver('09123000011'), $this->makeTruck('32', 'ب', '345', '67')));

        $this->assertSame($first->start_time, $second->start_time);

        // ولی کامیونی که بارگیری شد، واقعاً لاین را گرفته بود
        $second->forceFill(['status' => S::Completed])->save();

        $third = $create($this->booking(
            $factory, $this->makeDriver('09123000012'), $this->makeTruck('33', 'ب', '345', '67')));

        $this->assertNotSame($second->start_time, $third->start_time);
    }

    /**
     * بازگرداندنِ لغو، وقتی جایش را کسی گرفته باشد، با پیام رد می‌شود.
     *
     * قبل از آزاد شدنِ بازه‌ها این حالت ممکن نبود. حالا هست و اگر بی‌پاسخ
     * بماند، اپراتور به‌جای دلیل یک خطای ۵۰۰ می‌بیند.
     */
    #[Test]
    public function reviving_a_cancelled_appointment_fails_when_its_slot_was_taken(): void
    {
        $factory = $this->seedFactory();
        $factory->update(['loading_lines' => 1]);
        $factory = $factory->fresh();

        $create = app(CreateAppointment::class);
        $actor = Actor::user($this->operator());

        $cancelled = $create($this->booking(
            $factory, $this->makeDriver('09123000020'), $this->makeTruck('41', 'ب', '345', '67')));

        ($this->transition)($cancelled, S::Cancelled, $actor);

        $newcomer = $create($this->booking(
            $factory, $this->makeDriver('09123000021'), $this->makeTruck('42', 'ب', '345', '67')));

        $this->assertSame($cancelled->start_time, $newcomer->start_time);

        $reverter = $this->operator('rollback@test.local');
        $reverter->givePermissionTo(Permissions::APPOINTMENTS_ROLLBACK);

        $this->expectException(TransitionBlocked::class);

        ($this->transition)(
            $cancelled->fresh(),
            S::Booked,
            Actor::user($reverter->fresh()),
            'لغو اشتباه بود',
        );
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

    /**
     * لغوِ اشتباه، تا وقتی جایش را کسی نگرفته، با همان ساعت برمی‌گردد.
     *
     * حالت شلوغ‌ترش — وقتی جا رفته باشد — در تست بالا پوشش داده شده.
     */
    #[Test]
    public function a_cancelled_appointment_comes_back_with_its_own_hour(): void
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
        ($this->transition)($first->fresh(), S::Booked, Actor::user($manager));

        $revived = $first->fresh();

        $this->assertSame(S::Booked, $revived->status);
        $this->assertSame($place, [$revived->date->toDateString(), (int) $revived->line_no, $revived->start_time]);
    }
}
