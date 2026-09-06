<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Models\Appointment;
use App\Models\LoadingPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class QueuePanelTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private \App\Models\Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->seed(\Database\Seeders\UserSeeder::class);
    }

    private function staff(string $role): User
    {
        return User::role($role)->firstOrFail();
    }

    private function todayAppointment(string $mobile = '09123456789', string $two = '12'): Appointment
    {
        $slot = $this->futureSlot($this->factory);

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver($mobile),
            $this->makeTruck($two, 'ب', '345', '67'),
            $slot,
        ));

        // به امروز منتقلش می‌کنیم تا در صف امروز دیده شود
        $appointment->forceFill(['date' => now()->toDateString()])->save();

        return $appointment->fresh();
    }

    #[Test]
    public function the_queue_page_lists_todays_appointments_with_counters(): void
    {
        $this->todayAppointment();

        $this->actingAs($this->staff(Roles::OPERATOR))
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Queue/Index')
                ->has('appointments', 1)
                ->where('counters.total', 1)
                ->where('counters.waiting', 1)
                ->where('isToday', true));
    }

    #[Test]
    public function an_operator_can_walk_a_truck_through_the_whole_flow(): void
    {
        $appointment = $this->todayAppointment();
        $operator = $this->staff(Roles::OPERATOR);
        $line = LoadingPoint::where('factory_id', $this->factory->id)->firstOrFail();

        $steps = [
            [S::Waiting, []],
            [S::Called, []],
            [S::CheckedIn, []],
            [S::Loading, ['loading_point_id' => $line->id]],
            [S::Loaded, []],
            [S::Completed, []],
        ];

        foreach ($steps as [$status, $extra]) {
            $this->actingAs($operator)
                ->from(route('staff.queue.index'))
                ->post(route('staff.queue.transition', $appointment), ['to' => $status->value] + $extra)
                ->assertRedirect(route('staff.queue.index'))
                ->assertSessionHas('success');
        }

        $appointment->refresh();

        $this->assertSame(S::Completed, $appointment->status);
        $this->assertSame($line->id, $appointment->loading_point_id);
        $this->assertSame(0, $appointment->slot->fresh()->reserved_count);
    }

    #[Test]
    public function the_gate_role_can_check_in_but_cannot_start_loading(): void
    {
        $appointment = $this->todayAppointment();
        $gate = $this->staff(Roles::GATE);

        $this->actingAs($gate)
            ->from(route('staff.queue.index'))
            ->post(route('staff.queue.transition', $appointment), ['to' => S::CheckedIn->value])
            ->assertSessionHas('success');

        $this->assertSame(S::CheckedIn, $appointment->fresh()->status);

        $this->actingAs($gate)
            ->from(route('staff.queue.index'))
            ->post(route('staff.queue.transition', $appointment->fresh()), ['to' => S::Loading->value])
            ->assertSessionHas('error');

        $this->assertSame(S::CheckedIn, $appointment->fresh()->status);
    }

    #[Test]
    public function the_ceo_is_kept_out_of_the_operator_panel_entirely(): void
    {
        $appointment = $this->todayAppointment();
        $ceo = $this->staff(Roles::CEO);

        // دیدِ مدیرعامل از داشبورد و گزارش است، نه صف عملیاتی
        $this->actingAs($ceo)->get(route('staff.queue.index'))->assertForbidden();
        $this->actingAs($ceo)->get(route('staff.dashboard'))->assertOk();

        $this->actingAs($ceo)
            ->from(route('staff.dashboard'))
            ->post(route('staff.queue.transition', $appointment), ['to' => S::Waiting->value])
            ->assertSessionHas('error');

        $this->assertSame(S::Booked, $appointment->fresh()->status);
    }

    #[Test]
    public function an_operator_is_never_offered_a_rollback(): void
    {
        $appointment = $this->todayAppointment();
        $operator = $this->staff(Roles::OPERATOR);

        $this->actingAs($operator)->post(route('staff.queue.transition', $appointment), ['to' => S::Waiting->value]);
        $this->actingAs($operator)->post(route('staff.queue.transition', $appointment->fresh()), ['to' => S::Called->value]);

        $this->actingAs($operator)
            ->get(route('staff.queue.index'))
            ->assertInertia(function (AssertableInertia $page) {
                $actions = collect($page->toArray()['props']['appointments'][0]['actions']);

                $this->assertTrue($actions->every(fn ($a) => $a['is_rollback'] === false));
                $this->assertTrue($actions->contains(fn ($a) => $a['value'] === 'CHECKED_IN'));
            });
    }

    #[Test]
    public function a_manager_is_offered_a_rollback_and_it_is_recorded(): void
    {
        $appointment = $this->todayAppointment();
        $manager = $this->staff(Roles::FACTORY_MANAGER);

        foreach ([S::Waiting, S::Called, S::CheckedIn] as $status) {
            $this->actingAs($manager)->post(route('staff.queue.transition', $appointment->fresh()), ['to' => $status->value]);
        }

        $this->actingAs($manager)
            ->from(route('staff.queue.index'))
            ->post(route('staff.queue.transition', $appointment->fresh()), [
                'to' => S::Called->value,
                'reason' => 'ورود اشتباه ثبت شد',
            ])
            ->assertSessionHas('success');

        $this->assertSame(S::Called, $appointment->fresh()->status);
        $this->assertDatabaseHas('appointment_transitions', [
            'appointment_id' => $appointment->id,
            'to_status' => S::Called->value,
            'is_rollback' => true,
            'reason' => 'ورود اشتباه ثبت شد',
            'user_id' => $manager->id,
        ]);
    }

    #[Test]
    public function an_illegal_jump_is_refused_even_for_a_manager(): void
    {
        $appointment = $this->todayAppointment();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->from(route('staff.queue.index'))
            ->post(route('staff.queue.transition', $appointment), ['to' => S::Completed->value])
            ->assertSessionHas('error');

        $this->assertSame(S::Booked, $appointment->fresh()->status);
    }

    #[Test]
    public function the_detail_page_shows_the_full_transition_history(): void
    {
        $appointment = $this->todayAppointment();
        $operator = $this->staff(Roles::OPERATOR);

        $this->actingAs($operator)->post(route('staff.queue.transition', $appointment), ['to' => S::Waiting->value]);

        $this->actingAs($operator)
            ->get(route('staff.queue.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Queue/Show')
                ->has('timeline', 2)
                ->where('timeline.1.actor', $operator->name));
    }

    #[Test]
    public function expiry_is_never_offered_as_a_button(): void
    {
        $this->todayAppointment();

        // انقضا کار زمان‌بند است؛ حتی مدیر کارخانه هم نباید دکمه‌اش را ببیند
        foreach ([Roles::OPERATOR, Roles::FACTORY_MANAGER] as $role) {
            $this->actingAs($this->staff($role))
                ->get(route('staff.queue.index'))
                ->assertInertia(function (AssertableInertia $page) {
                    $actions = collect($page->toArray()['props']['appointments'][0]['actions']);

                    $this->assertFalse($actions->contains(fn ($a) => $a['value'] === S::Expired->value));
                });
        }
    }

    #[Test]
    public function the_scheduled_command_expires_yesterdays_stragglers(): void
    {
        $appointment = $this->todayAppointment();
        $appointment->forceFill(['date' => now()->subDays(2)->toDateString()])->save();

        $this->artisan('appointments:expire')->assertSuccessful();

        $this->assertSame(S::Expired, $appointment->fresh()->status);
        $this->assertSame(0, $appointment->slot->fresh()->reserved_count);
        $this->assertDatabaseHas('appointment_transitions', [
            'appointment_id' => $appointment->id,
            'to_status' => S::Expired->value,
            'actor_label' => 'سامانه',
        ]);
    }

    #[Test]
    public function a_todays_appointment_is_left_alone_by_the_expiry_command(): void
    {
        $appointment = $this->todayAppointment();

        $this->artisan('appointments:expire')->assertSuccessful();

        $this->assertSame(S::Booked, $appointment->fresh()->status);
    }

    #[Test]
    public function roles_reach_the_front_end_with_persian_labels(): void
    {
        $this->actingAs($this->staff(Roles::OPERATOR))
            ->get(route('staff.queue.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.roles', ['اپراتور']));
    }

    #[Test]
    public function guests_are_sent_to_the_staff_login_not_the_driver_one(): void
    {
        $this->get(route('staff.queue.index'))->assertRedirect(route('staff.login'));
    }

    #[Test]
    public function a_driver_session_cannot_reach_the_staff_panel(): void
    {
        $driver = $this->makeDriver('09120000055')->refresh();

        $this->actingAs($driver, 'driver')
            ->get(route('staff.queue.index'))
            ->assertRedirect(route('staff.login'));
    }

    #[Test]
    public function each_role_lands_on_its_own_page(): void
    {
        $this->actingAs($this->staff(Roles::OPERATOR))->get(route('staff.home'))
            ->assertRedirect(route('staff.queue.index'));

        $this->actingAs($this->staff(Roles::CEO))->get(route('staff.home'))
            ->assertRedirect(route('staff.dashboard'));
    }
}
