<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use App\Models\WorkingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class SettingsAndReportsTest extends TestCase
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

    /** @return array<string, mixed> */
    private function settingsPayload(array $overrides = []): array
    {
        $hours = collect(range(0, 6))->map(fn (int $weekday) => [
            'weekday' => $weekday,
            'is_open' => $weekday !== 6,
            'opens_at' => '08:00',
            'closes_at' => '17:00',
            'capacity_per_slot' => 4,
        ])->all();

        return array_merge([
            'slot_minutes' => 20,
            'daily_capacity' => 60,
            'loading_lines' => 2,
            'avg_loading_minutes' => 30,
            'no_show_grace_minutes' => 45,
            'booking_horizon_days' => 5,
            'booking_lead_minutes' => 90,
            'max_active_per_mobile' => 3,
            'max_active_per_plate' => 2,
            'working_hours' => $hours,
        ], $overrides);
    }

    // ------------------------------------------------------------ تنظیمات

    #[Test]
    public function a_manager_can_change_capacity_and_working_hours(): void
    {
        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->from(route('staff.settings.edit'))
            ->put(route('staff.settings.update'), $this->settingsPayload())
            ->assertRedirect(route('staff.settings.edit'))
            ->assertSessionHas('success');

        $this->factory->refresh();

        $this->assertSame(20, $this->factory->slot_minutes);
        $this->assertSame(60, $this->factory->daily_capacity);
        $this->assertSame(90, $this->factory->booking_lead_minutes);

        $saturday = WorkingHour::where('factory_id', $this->factory->id)->where('weekday', 0)->firstOrFail();
        $this->assertSame('08:00:00', $saturday->opens_at);
        $this->assertSame(4, $saturday->capacity_per_slot);
    }

    #[Test]
    public function saving_settings_regenerates_the_future_slots(): void
    {
        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->put(route('staff.settings.update'), $this->settingsPayload());

        // با اسلات ۲۰ دقیقه‌ای بین ۰۸:۰۰ و ۱۷:۰۰ روزی ۲۷ اسلات ساخته می‌شود
        $day = AppointmentSlot::where('factory_id', $this->factory->id)
            ->whereDate('date', now()->addDay()->toDateString())
            ->get();

        if ($day->isNotEmpty()) {
            $this->assertSame('08:00:00', $day->min('start_time'));
            $this->assertSame(4, $day->first()->capacity);
        }
    }

    #[Test]
    public function lowering_capacity_never_drops_below_what_is_already_reserved(): void
    {
        $slot = $this->futureSlot($this->factory);

        // پنج نوبت روی یک اسلات ثبت می‌کنیم
        foreach (range(0, 4) as $i) {
            app(CreateAppointment::class)($this->booking(
                $this->factory,
                $this->makeDriver('0912200000'.$i),
                $this->makeTruck(str_pad((string) (40 + $i), 2, '0', STR_PAD_LEFT), 'ب', '345', '67'),
                $slot,
            ));
        }

        $this->assertSame(5, $slot->fresh()->reserved_count);

        // بعد ظرفیت هر اسلات را به ۱ کاهش می‌دهیم
        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))->put(
            route('staff.settings.update'),
            $this->settingsPayload([
                'slot_minutes' => $this->factory->slot_minutes,
                'working_hours' => collect(range(0, 6))->map(fn (int $w) => [
                    'weekday' => $w,
                    'is_open' => $w !== 6,
                    'opens_at' => '07:00',
                    'closes_at' => '18:00',
                    'capacity_per_slot' => 1,
                ])->all(),
            ]),
        );

        $fresh = $slot->fresh();

        $this->assertGreaterThanOrEqual($fresh->reserved_count, $fresh->capacity);
        $this->assertSame(5, $fresh->reserved_count);
    }

    #[Test]
    public function a_closing_time_before_the_opening_time_is_rejected(): void
    {
        $payload = $this->settingsPayload();
        $payload['working_hours'][0]['closes_at'] = '06:00';

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->from(route('staff.settings.edit'))
            ->put(route('staff.settings.update'), $payload)
            ->assertSessionHasErrors('working_hours.0.closes_at');
    }

    #[Test]
    public function changing_settings_is_written_to_the_audit_log(): void
    {
        $manager = $this->staff(Roles::FACTORY_MANAGER);

        $this->actingAs($manager)->put(route('staff.settings.update'), $this->settingsPayload());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'UPDATE_BOOKING_SETTINGS',
            'user_id' => $manager->id,
        ]);

        $log = \App\Models\AuditLog::latest('id')->firstOrFail();

        $this->assertSame(30, $log->old_values['slot_minutes']);
        $this->assertSame(20, $log->new_values['slot_minutes']);
    }

    #[Test]
    public function an_operator_cannot_reach_the_settings(): void
    {
        $this->actingAs($this->staff(Roles::OPERATOR))
            ->get(route('staff.settings.edit'))
            ->assertForbidden();

        $this->actingAs($this->staff(Roles::OPERATOR))
            ->put(route('staff.settings.update'), $this->settingsPayload())
            ->assertForbidden();
    }

    #[Test]
    public function the_ceo_cannot_touch_the_settings_either(): void
    {
        $this->actingAs($this->staff(Roles::CEO))
            ->put(route('staff.settings.update'), $this->settingsPayload())
            ->assertForbidden();

        $this->assertSame(30, $this->factory->fresh()->slot_minutes);
    }

    // ------------------------------------------------------------- گزارش

    private function completedAppointment(int $waitMinutes, int $loadingMinutes): Appointment
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123'.str_pad((string) random_int(100000, 999999), 6, '0')),
            $this->makeTruck(str_pad((string) random_int(10, 99), 2, '0'), 'ب', str_pad((string) random_int(100, 999), 3, '0'), '67'),
            $this->futureSlot($this->factory),
        ));

        $checkedIn = now()->subMinutes($waitMinutes + $loadingMinutes);

        $appointment->forceFill([
            'date' => now()->toDateString(),
            // شماره‌ی نوبت روزانه است؛ با جابه‌جایی روز باید دوباره گرفته شود
            'number' => $this->nextNumberForToday(),
            'status' => S::Completed,
            'checked_in_at' => $checkedIn,
            'loading_started_at' => $checkedIn->copy()->addMinutes($waitMinutes),
            'loading_completed_at' => $checkedIn->copy()->addMinutes($waitMinutes + $loadingMinutes),
            'completed_at' => $checkedIn->copy()->addMinutes($waitMinutes + $loadingMinutes),
        ])->save();

        return $appointment->fresh();
    }

    private function nextNumberForToday(): int
    {
        return 1 + (int) Appointment::where('factory_id', $this->factory->id)
            ->whereDate('date', now()->toDateString())
            ->max('number');
    }

    #[Test]
    public function the_report_computes_averages_from_the_recorded_timestamps(): void
    {
        $this->completedAppointment(waitMinutes: 20, loadingMinutes: 30);
        $this->completedAppointment(waitMinutes: 40, loadingMinutes: 50);

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.reports', ['range' => 'week']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Reports')
                ->where('summary.total', 2)
                ->where('summary.completed', 2)
                ->where('summary.avg_wait_minutes', 30)
                ->where('summary.avg_loading_minutes', 40));
    }

    #[Test]
    public function the_no_show_rate_is_computed(): void
    {
        $this->completedAppointment(10, 10);

        $noShow = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09129990001'),
            $this->makeTruck('77', 'ج', '777', '67'),
            $this->futureSlot($this->factory, 1),
        ));

        $noShow->forceFill([
            'date' => now()->toDateString(),
            'number' => $this->nextNumberForToday(),
            'status' => S::NoShow,
        ])->save();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.reports'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.no_show', 1)
                ->where('summary.no_show_rate', 50)); // JSON عدد صحیح برمی‌گرداند
    }

    #[Test]
    public function operator_activity_comes_from_the_transition_history(): void
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09128880001'),
            $this->makeTruck('88', 'د', '888', '67'),
            $this->futureSlot($this->factory),
        ));

        $appointment->forceFill([
            'date' => now()->toDateString(),
            'number' => $this->nextNumberForToday(),
        ])->save();

        $operator = $this->staff(Roles::OPERATOR);
        $transition = app(TransitionAppointment::class);

        foreach ([S::Waiting, S::Called] as $status) {
            $transition($appointment->fresh(), $status, Actor::user($operator));
        }

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.reports'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('byOperator.0.name', $operator->name)
                ->where('byOperator.0.actions', 2)
                ->where('byOperator.0.rollbacks', 0));
    }

    #[Test]
    public function the_csv_export_carries_a_bom_so_excel_reads_persian(): void
    {
        $this->completedAppointment(10, 20);

        $response = $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.reports.export', ['range' => 'week']))
            ->assertOk();

        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('تاریخ', $content);
    }

    #[Test]
    public function an_operator_cannot_read_the_reports(): void
    {
        $this->actingAs($this->staff(Roles::OPERATOR))->get(route('staff.reports'))->assertForbidden();
        $this->actingAs($this->staff(Roles::OPERATOR))->get(route('staff.reports.export'))->assertForbidden();
    }

    #[Test]
    public function the_ceo_can_read_the_reports_and_the_dashboard(): void
    {
        $this->actingAs($this->staff(Roles::CEO))->get(route('staff.reports'))->assertOk();
        $this->actingAs($this->staff(Roles::CEO))->get(route('staff.dashboard'))->assertOk();
    }
}
