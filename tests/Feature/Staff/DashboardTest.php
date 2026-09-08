<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * داشبورد، صفحه‌ی اولِ پنل.
 *
 * صف امروز روی خودِ داشبورد نشان داده می‌شود؛ مدیر نباید برای دیدن اینکه
 * الان چه خبر است به صفحه‌ی دیگری برود.
 */
final class DashboardTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // ۰۹:۰۰ یک روز کاری.
        //
        // بدون ثابت‌کردن ساعت، این تست‌ها بعدازظهرها می‌شکستند: مهلت حضورِ
        // نوبتِ صبح گذشته بود و هشدار «راننده نیامده» درست فعال می‌شد.
        // رفتار درست بود؛ تست ناپایدار.
        $this->travelTo(CarbonImmutable::parse('2026-09-07 09:00', 'Asia/Tehran'));

        $this->factory = $this->seedFactory();
        $this->seedStaff();
    }

    private function staff(string $role): User
    {
        return User::role($role)->firstOrFail();
    }

    private function todayAppointment(): Appointment
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '67'),
        ));

        $appointment->forceFill(['date' => now()->toDateString()])->save();

        return $appointment->fresh();
    }

    #[Test]
    public function the_dashboard_carries_todays_queue(): void
    {
        $appointment = $this->todayAppointment();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Dashboard')
                ->where('canSeeQueue', true)
                ->has('queue', 1)
                ->where('queue.0.number', $appointment->number)
                ->where('queue.0.driver', $appointment->driver->name)
                ->where('queue.0.is_active', true)
                ->where('counters.total', 1));
    }

    #[Test]
    public function a_loading_truck_shows_up_on_its_bay(): void
    {
        $appointment = $this->todayAppointment();
        $line = LoadingPoint::where('factory_id', $this->factory->id)->firstOrFail();

        $appointment->forceFill([
            'status' => S::Loading,
            'loading_point_id' => $line->id,
            'loading_started_at' => now()->subMinutes(10),
        ])->save();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lines.0.busy', true)
                ->where('lines.0.number', $appointment->number)
                ->where('lines.0.elapsed_minutes', 10)
                ->where('lines.0.is_late', false)
                ->has('alerts', 0));
    }

    /**
     * بارگیریِ طولانی باید هم روی کارتِ لاین قرمز شود و هم در هشدارها بیاید.
     * آستانه ۱.۵ برابرِ مدتِ نوع کامیون است.
     */
    #[Test]
    public function a_loading_that_ran_long_raises_an_alert(): void
    {
        $appointment = $this->todayAppointment();
        $line = LoadingPoint::where('factory_id', $this->factory->id)->firstOrFail();

        $appointment->forceFill([
            'status' => S::Loading,
            'loading_point_id' => $line->id,
            'loading_started_at' => now()->subMinutes(600),
        ])->save();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lines.0.is_late', true)
                // درصد روی ۱۰۰ سقف می‌خورد، وگرنه نوار از کادر بیرون می‌زند
                ->where('lines.0.percent', 100)
                ->has('alerts', 1)
                ->where('alerts.0.kind', 'late_loading'));
    }

    #[Test]
    public function a_driver_past_the_arrival_deadline_raises_an_alert(): void
    {
        $appointment = $this->todayAppointment();

        $appointment->forceFill([
            'status' => S::Waiting,
            'start_time' => now()->subHours(6)->format('H:i:s'),
        ])->save();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('alerts', 1)
                ->where('alerts.0.kind', 'overdue'));
    }

    #[Test]
    public function an_idle_bay_reports_itself_free(): void
    {
        $this->todayAppointment();

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('lines.0.busy', false)
                ->has('alerts', 0));
    }

    /**
     * مدیرعامل عمداً QUEUE_VIEW ندارد — صف عملیاتی صفحه‌ی او نیست.
     * داشبورد باید برایش باز شود، ولی بدون صف.
     */
    #[Test]
    public function the_ceo_sees_the_dashboard_without_the_operational_queue(): void
    {
        $this->todayAppointment();

        $this->actingAs($this->staff(Roles::CEO))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canSeeQueue', false)
                ->has('queue', 0)
                ->has('lines', 0)
                ->has('alerts', 0)
                ->has('upcomingDays', 0));
    }

    #[Test]
    public function a_booking_on_another_day_is_announced_on_the_dashboard_too(): void
    {
        // ۱۹:۳۰ — کارخانه ۱۸:۰۰ بسته شده، پس نوبت روی فردا می‌نشیند
        $this->travelTo(CarbonImmutable::parse('2026-09-07 19:30', 'Asia/Tehran'));

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '67'),
        ));

        $this->assertFalse($appointment->date->isToday());

        $this->actingAs($this->staff(Roles::FACTORY_MANAGER))
            ->get(route('staff.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('queue', 0)
                ->has('upcomingDays', 1)
                ->where('upcomingDays.0.total', 1));
    }
}
