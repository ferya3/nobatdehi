<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * اخطارِ وزن باید در پنل صف، جلوی همان کامیون دیده شود.
 *
 * صفحه‌ی باسکول خطا را به باسکول‌بان نشان می‌دهد و بعد رد می‌شود. پنل صف
 * تنها جایی است که همه‌ی کامیون‌های امروز کنار هم‌اند؛ اگر آنجا نشانی
 * نباشد، کامیونِ قفل‌شده فقط «یک ردیف عادی» است و کسی سراغش نمی‌رود.
 */
final class WeightWarningOnQueueTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);

        $this->product = Product::where('factory_id', $this->factory->id)->firstOrFail();
    }

    private function operator(): User
    {
        $user = User::create([
            'name' => 'اپراتور',
            'email' => 'op@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(Roles::OPERATOR));

        return $user->fresh();
    }

    private function bookAndLoad(): Appointment
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '11'),
            $this->product,
        ));

        return app(TransitionAppointment::class)(
            $appointment, AppointmentStatus::CheckedIn, Actor::system(),
        );
    }

    #[Test]
    public function a_truck_with_a_weight_discrepancy_is_marked_on_the_queue(): void
    {
        $appointment = $this->bookAndLoad();

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'loaded_weight_kg' => 50000,
            'net_weight_kg' => 36000,
            'expected_net_kg' => 30000,
            'variance_kg' => 6000,
            'is_overload' => false,
            'discrepancy_kind' => 'variance',
            'discrepancy_alerted_at' => now(),
        ]);

        $this->actingAs($this->operator())
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointments.0.weighing.discrepancy', 'variance')
                ->where('appointments.0.weighing.discrepancy_label', 'مغایرت وزن')
                // عددها هم می‌آیند تا اپراتور بداند اختلاف چقدر است
                ->where('appointments.0.weighing.net_kg', '36000.00')
                ->where('appointments.0.weighing.expected_kg', '30000.00')
                ->where('appointments.0.weighing.exit_permit_number', null));
    }

    #[Test]
    public function an_overload_is_marked_with_its_own_label(): void
    {
        $appointment = $this->bookAndLoad();

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'loaded_weight_kg' => 26000,
            'net_weight_kg' => 12000,
            'is_overload' => true,
            'discrepancy_kind' => 'overload',
            'discrepancy_alerted_at' => now(),
        ]);

        $this->actingAs($this->operator())
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointments.0.weighing.discrepancy_label', 'اضافه‌بار'));
    }

    #[Test]
    public function a_clean_weighing_carries_no_warning(): void
    {
        $appointment = $this->bookAndLoad();

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'loaded_weight_kg' => 44000,
            'net_weight_kg' => 30000,
            'expected_net_kg' => 30000,
            'variance_kg' => 0,
            'exit_permit_number' => 'X-1',
        ]);

        $this->actingAs($this->operator())
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointments.0.weighing.discrepancy_label', null)
                ->where('appointments.0.weighing.exit_permit_number', 'X-1'));
    }

    #[Test]
    public function a_truck_that_never_reached_the_weighbridge_has_no_weighing_block(): void
    {
        $this->bookAndLoad();

        $this->actingAs($this->operator())
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointments.0.weighing', null));
    }
}
