<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Models\Appointment;
use App\Models\Factory;
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
