<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * برگه‌ی خروج — همان کاغذی که راننده دمِ راهبند نشان می‌دهد.
 *
 * تا پیش از این شماره‌ی برگه فقط یک رشته در دیتابیس بود و کارخانه‌ای که
 * راننده‌اش باید چیزی در دست داشته باشد، آن را دستی روی دفتر می‌نوشت —
 * یعنی همان جایی که عددها عوض می‌شوند.
 */
final class ExitPermitPageTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function person(string $role): User
    {
        if (isset($this->people[$role])) {
            return $this->people[$role];
        }

        $user = User::create([
            'name' => 'کاربر',
            'email' => str_replace('-', '', $role).'@t.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role, 'web'));

        return $this->people[$role] = $user->fresh();
    }

    /** کامیونی که تا ته مسیر رفته و برگه گرفته */
    private function weighed(): Appointment
    {
        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->forceFill(['truck_type_id' => TruckType::where('code', 'teriler')->value('id')])->save();

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
            Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail(),
        ));

        $move = app(TransitionAppointment::class);
        $appointment = $move($appointment, AppointmentStatus::CheckedIn, Actor::system());

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 13750,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $appointment = $move($appointment, AppointmentStatus::Loading, Actor::system());
        $appointment = $move($appointment, AppointmentStatus::Loaded, Actor::system());

        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'gross', 'weight_kg' => 41500])
            ->assertSessionHas('success');

        return $appointment->refresh();
    }

    #[Test]
    public function the_permit_carries_every_number_the_scale_produced(): void
    {
        $appointment = $this->weighed();
        $permit = LoadingRecord::firstOrFail()->exit_permit_number;

        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->get(route('staff.queue.exit-permit', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/ExitPermit')
                ->where('permit.number', $permit)
                ->where('permit.weights.tare_kg', '13750.00')
                ->where('permit.weights.gross_kg', '41500.00')
                ->where('permit.weights.net_kg', '27750.00')
                // کاغذ باید بگوید کدام کامیون و کدام راننده
                ->where('permit.truck.plate.two', '12')
                ->where('permit.driver.mobile', '09123456789')
                ->where('permit.factory.name', $this->factory->name)
                ->whereNot('permit.issued_at', null));
    }

    #[Test]
    public function a_waybill_with_no_permit_has_no_page(): void
    {
        // کاغذی که شبیه مجوز باشد و مجوز نباشد، از خودِ نبودنش بدتر است
        $truck = $this->makeTruck('12', 'ب', '345', '11');

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
            Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail(),
        ));

        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->get(route('staff.queue.exit-permit', $appointment))
            ->assertNotFound();
    }

    #[Test]
    public function an_accepted_overload_stays_on_the_paper(): void
    {
        // پنهان کردنش یعنی کاغذی که با دفترِ سامانه نمی‌خواند
        $appointment = $this->weighed();

        LoadingRecord::firstOrFail()->forceFill([
            'discrepancy_kind' => 'overload',
            'variance_kg' => 1200,
            'discrepancy_decision' => 'approved',
            'discrepancy_decision_reason' => 'اضافه‌بار جزئی با هماهنگی مسئول حمل پذیرفته شد',
            'discrepancy_decided_at' => now(),
        ])->save();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->get(route('staff.queue.exit-permit', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('permit.overload.decision', 'approved')
                ->where('permit.overload.kg', '1200.00')
                ->where('permit.overload.reason', 'اضافه‌بار جزئی با هماهنگی مسئول حمل پذیرفته شد'));
    }

    #[Test]
    public function the_guard_at_the_barrier_can_open_it_too(): void
    {
        // کسی که کاغذ را از راننده می‌گیرد، باید بتواند با سامانه بسنجدش
        $appointment = $this->weighed();

        $this->actingAs($this->person(Roles::GATE), 'web')
            ->get(route('staff.queue.exit-permit', $appointment))
            ->assertOk();
    }

    #[Test]
    public function a_permit_from_another_factory_is_not_reachable(): void
    {
        $appointment = $this->weighed();

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $appointment->forceFill(['factory_id' => $other->id])->save();

        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->get(route('staff.queue.exit-permit', $appointment))
            ->assertNotFound();
    }

    #[Test]
    public function someone_outside_the_yard_cannot_open_it(): void
    {
        $appointment = $this->weighed();

        // مدیرعامل عمداً queue.view ندارد
        $this->actingAs($this->person(Roles::CEO), 'web')
            ->get(route('staff.queue.exit-permit', $appointment))
            ->assertForbidden();
    }
}
