<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Queue\QueueService;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class TruckTypeManagementTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function manager(): User
    {
        $user = User::create([
            'name' => 'مدیر کارخانه',
            'email' => 'manager@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(Roles::FACTORY_MANAGER));

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'تریلی کفی',
            'code' => 'FLATBED',
            'capacity_tons' => '24',
            'loading_minutes' => 45,
            'grace_minutes' => 90,
            'sort_order' => 8,
            'is_active' => true,
        ], $overrides);
    }

    #[Test]
    public function test_a_manager_can_add_a_truck_type_with_its_timings(): void
    {
        $this->actingAs($this->manager())
            ->post(route('staff.truck-types.store'), $this->payload())
            ->assertRedirect();

        $type = TruckType::where('code', 'FLATBED')->firstOrFail();

        $this->assertSame(45, $type->loading_minutes);
        $this->assertSame(90, $type->grace_minutes);
    }

    #[Test]
    public function test_blank_timings_mean_fall_back_to_the_factory_defaults(): void
    {
        $this->actingAs($this->manager())
            ->post(route('staff.truck-types.store'), $this->payload([
                'loading_minutes' => null,
                'grace_minutes' => null,
            ]))
            ->assertRedirect();

        $type = TruckType::where('code', 'FLATBED')->firstOrFail();
        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->update(['truck_type_id' => $type->id]);

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
        ));

        $appointment->load('truck.truckType', 'product', 'factory');

        $this->assertNull($type->loading_minutes);
        $this->assertSame((int) $this->factory->no_show_grace_minutes, $appointment->graceMinutes());
    }

    #[Test]
    public function test_a_grace_period_under_five_minutes_is_rejected(): void
    {
        $this->actingAs($this->manager())
            ->post(route('staff.truck-types.store'), $this->payload(['grace_minutes' => 1]))
            ->assertSessionHasErrors('grace_minutes');
    }

    #[Test]
    public function test_a_type_attached_to_registered_trucks_is_not_deleted(): void
    {
        $type = TruckType::where('code', 'teriler')->firstOrFail();

        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->update(['truck_type_id' => $type->id]);

        $this->actingAs($this->manager())
            ->delete(route('staff.truck-types.destroy', $type))
            ->assertSessionHas('error');

        $this->assertModelExists($type);
        $this->assertSame($type->id, $truck->refresh()->truck_type_id);
    }

    #[Test]
    public function test_the_truck_type_drives_the_expected_loading_time(): void
    {
        $type = TruckType::where('code', 'teriler')->firstOrFail();
        $type->update(['loading_minutes' => 55]);

        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->update(['truck_type_id' => $type->id]);

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
        ));

        // نوع کامیون بر مدت محصول اولویت دارد
        $this->assertSame(55, $appointment->load('truck.truckType', 'product', 'factory')->expectedLoadingMinutes());
    }

    #[Test]
    public function test_a_trailer_ahead_pushes_the_estimate_further_than_a_pickup(): void
    {
        $trailer = TruckType::where('code', 'teriler')->firstOrFail();
        $pickup = TruckType::where('code', 'khavar')->firstOrFail();

        $trailer->update(['loading_minutes' => 60]);
        $pickup->update(['loading_minutes' => 10]);

        $this->factory->update(['loading_lines' => 1]);

        // راننده‌ها یک‌بار ساخته می‌شوند؛ closure دو بار اجرا می‌شود
        $aheadDriver = $this->makeDriver('09120000001');
        $myDriver = $this->makeDriver('09120000002');

        $estimateWithAhead = function (TruckType $aheadType) use ($aheadDriver, $myDriver): int {
            $this->freezeOnWorkingMorning($this->factory);

            $slots = [$this->todaySlot($this->factory, 0), $this->todaySlot($this->factory, 1)];

            $aheadTruck = $this->makeTruck('11', 'ب', '111', '11');
            $aheadTruck->update(['truck_type_id' => $aheadType->id]);

            app(CreateAppointment::class)($this->booking(
                $this->factory,
                $aheadDriver,
                $aheadTruck->refresh(),
            ));

            $mine = app(CreateAppointment::class)($this->booking(
                $this->factory,
                $myDriver,
                $this->makeTruck('22', 'ب', '222', '22'),
            ));

            // درست پیش از ساعت نوبت: دیگر «تا شروع اسلات» بر تخمین غالب نیست
            // و آنچه می‌ماند، مدت بارگیریِ کامیونِ جلویی است.
            $this->travelTo($mine->startsAt()->subMinutes(5));

            return (int) app(QueueService::class)->estimatedWaitMinutes($mine->refresh());
        };

        $withTrailer = $estimateWithAhead($trailer);

        Appointment::query()->delete();

        $withPickup = $estimateWithAhead($pickup);

        $this->assertGreaterThan($withPickup, $withTrailer);
    }

    #[Test]
    public function test_the_grace_period_frees_the_days_capacity_by_marking_a_no_show(): void
    {
        $type = TruckType::where('code', 'khavar')->firstOrFail();
        $type->update(['grace_minutes' => 30]);

        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->update(['truck_type_id' => $type->id]);

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
        ));

        // ۳۱ دقیقه بعد از ساعت نوبت: یک دقیقه بعد از پایان مهلت حضور
        $this->travelTo($appointment->startsAt()->addMinutes(31));

        $this->artisan('appointments:no-show')->assertSuccessful();

        $this->assertSame(AppointmentStatus::NoShow, $appointment->refresh()->status);

        // ظرفیت روز آزاد می‌شود — نوبتِ عدم‌حضور دیگر فعال شمرده نمی‌شود
        $this->assertSame(0, Appointment::whereIn('status', AppointmentStatus::activeValues())->count());
    }

    #[Test]
    public function test_an_appointment_still_inside_its_grace_window_is_left_alone(): void
    {
        $type = TruckType::where('code', 'khavar')->firstOrFail();
        $type->update(['grace_minutes' => 120]);

        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->update(['truck_type_id' => $type->id]);

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
        ));

        $this->travelTo($appointment->startsAt()->addMinutes(30));

        $this->artisan('appointments:no-show')->assertSuccessful();

        $this->assertSame(AppointmentStatus::Waiting, $appointment->refresh()->status);
    }
}
