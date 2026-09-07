<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Queue\QueueService;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\Product;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * دو قانون که به‌راحتی اشتباه پیاده می‌شوند:
 *
 *   اولویت فقط *داخل* یک ساعت جابه‌جا می‌کند — نه بین ساعت‌ها.
 *   زمان اعلام‌شده باید برای نوبت فردا هم درست باشد، نه فقط امروز.
 */
final class PriorityAndScheduleTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function book(string $mobile, string $two, ?Product $product = null): Appointment
    {
        $truck = $this->makeTruck($two, 'ب', '345', '11');
        $truck->update(['truck_type_id' => TruckType::orderBy('id')->value('id')]);

        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver($mobile),
            $truck->refresh(),
            $product,
        ));
    }

    #[Test]
    public function test_a_priority_product_is_served_first_at_the_same_start_time(): void
    {
        // با زمان‌بندی سریالی، دو نوبت فقط وقتی ساعت یکسان دارند که روی دو
        // لاین موازی بنشینند — و اولویت دقیقاً همان‌جا تصمیم می‌گیرد.
        $this->factory->update(['loading_lines' => 2]);

        $normal = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();

        $urgent = Product::create([
            'factory_id' => $this->factory->id,
            'name' => 'بار صادراتی',
            'code' => 'EXPORT',
            'load_tons' => 20,
            'sort_order' => 9,
            'priority' => 50,
            'is_active' => true,
        ]);

        // عادی زودتر ثبت می‌شود، پس شماره‌ی کوچک‌تری دارد
        $first = $this->book('09120000001', '11', $normal);
        $second = $this->book('09120000002', '22', $urgent);

        $this->assertSame($first->start_time, $second->start_time, 'هر دو باید ساعت یکسان بگیرند');

        $order = Appointment::where('factory_id', $this->factory->id)
            ->whereDate('date', $first->date)
            ->queueOrder()
            ->pluck('id')
            ->all();

        $this->assertSame([$second->id, $first->id], $order);
        $this->assertSame(50, $second->priority);
    }

    #[Test]
    public function test_priority_never_jumps_over_an_earlier_hour(): void
    {
        // یک لاین: نوبت دوم قطعاً ساعت دیرتری می‌گیرد
        $this->factory->update(['loading_lines' => 1]);

        $earlyAppointment = $this->book('09120000001', '11');
        $lateAppointment = $this->book('09120000002', '22');

        $this->assertNotSame($earlyAppointment->start_time, $lateAppointment->start_time);

        // نوبت دیرتر بالاترین اولویت را می‌گیرد
        $lateAppointment->update(['priority' => 100, 'priority_reason' => 'تست']);

        $order = Appointment::where('factory_id', $this->factory->id)
            ->whereDate('date', $earlyAppointment->date)
            ->queueOrder()
            ->pluck('id')
            ->all();

        // ...و باز هم پشت نوبتِ ساعتِ زودتر می‌ماند
        $this->assertSame([$earlyAppointment->id, $lateAppointment->id], $order);
    }

    #[Test]
    public function test_an_operator_must_give_a_reason_to_change_a_priority(): void
    {
        $appointment = $this->book('09120000001', '11');
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('staff.queue.priority', $appointment), ['priority' => 40])
            ->assertSessionHasErrors('priority_reason');

        $this->assertSame(0, $appointment->refresh()->priority);

        $this->actingAs($operator)
            ->post(route('staff.queue.priority', $appointment), [
                'priority' => 40,
                'priority_reason' => 'درخواست واحد فروش برای بار فوری',
            ])
            ->assertSessionHas('success');

        $this->assertSame(40, $appointment->refresh()->priority);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CHANGE_APPOINTMENT_PRIORITY']);
    }

    #[Test]
    public function test_a_gate_guard_cannot_reorder_the_queue(): void
    {
        $appointment = $this->book('09120000001', '11');

        $this->actingAs($this->staff(Roles::GATE, 'gate@test.local'))
            ->post(route('staff.queue.priority', $appointment), [
                'priority' => 100,
                'priority_reason' => 'چون می‌خواهم',
            ])
            ->assertForbidden();

        $this->assertSame(0, $appointment->refresh()->priority);
    }

    #[Test]
    public function test_the_announced_schedule_is_built_from_the_trucks_own_loading_time(): void
    {
        TruckType::query()->update(['loading_minutes' => 45]);

        $appointment = $this->book('09120000001', '11');

        $schedule = app(QueueService::class)->plannedSchedule($appointment->refresh());

        $this->assertSame(45, $schedule['loading_minutes']);

        // ساعت‌ها روی سرور فرمت می‌شوند، وگرنه گوشیِ روی UTC ۰۷:۰۰ را
        // ۰۳:۳۰ نشان می‌دهد
        $this->assertSame($appointment->startsAt()->format('H:i'), $schedule['starts_at']);
        $this->assertSame($appointment->startsAt()->addMinutes(45)->format('H:i'), $schedule['ends_at']);

        // «چقدر تا نوبت من مانده» از همان ساعت اعلام‌شده می‌آید
        $this->assertSame(
            (int) max(0, now()->diffInMinutes($appointment->startsAt(), false)),
            $schedule['starts_in_minutes'],
        );
    }

    #[Test]
    public function test_a_slower_truck_ahead_pushes_the_announced_start_later(): void
    {
        $this->factory->update(['loading_lines' => 1]);

        TruckType::query()->update(['loading_minutes' => 60]);

        $ahead = $this->book('09120000001', '11');
        $mine = $this->book('09120000002', '22');

        $schedule = app(QueueService::class)->plannedSchedule($mine->refresh());

        // یک کامیون ۶۰ دقیقه‌ای جلوتر روی همان لاین: نوبت من دقیقاً از
        // لحظه‌ای شروع می‌شود که کار او تمام می‌شود.
        $this->assertSame($ahead->end_time, $mine->start_time);
        $this->assertSame(
            $ahead->startsAt()->addMinutes(60)->format('H:i'),
            $schedule['starts_at'],
        );
    }

    #[Test]
    public function test_the_driver_is_told_the_time_for_a_future_appointment_too(): void
    {
        // مهلت رسیدنِ بلند، نوبت را از امروز بیرون می‌برد
        $this->factory->update(['booking_lead_minutes' => 60 * 30]);

        $appointment = $this->book('09120000001', '11');

        $this->assertFalse($appointment->date->isToday());

        $driver = $appointment->driver;

        $this->actingAs($driver, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->assertInertia(fn ($page) => $page
                ->where('appointment.is_today', false)
                ->whereNot('appointment.schedule', null)
                ->has('appointment.schedule.starts_at')
                ->has('appointment.schedule.ends_at'));
    }

    private function operator(): User
    {
        return $this->staff(Roles::OPERATOR, 'op@test.local');
    }

    private function staff(string $role, string $email): User
    {
        $user = User::create([
            'name' => 'کاربر',
            'email' => $email,
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role));

        return $user->fresh();
    }
}
