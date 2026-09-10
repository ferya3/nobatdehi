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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * «منتظر توزین» یعنی کاری مانده، نه اینکه در فلان وضعیت است.
 *
 * باسکول‌بان با همین دو عدد تصمیم می‌گیرد کجا بایستد. اگر عدد با صفِ جلوی
 * چشمش نخواند، بار دوم دیگر نگاهش نمی‌کند.
 */
final class PendingCountersTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
        $this->factory->update(['max_active_per_plate' => 5]);

        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();
    }

    private function scaleman(): User
    {
        $user = User::create([
            'name' => 'باسکول‌بان',
            'email' => 'scale@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(Roles::WEIGHBRIDGE, 'web'));

        return $user->fresh();
    }

    private function truckAt(AppointmentStatus $status, ?array $record = null): Appointment
    {
        $this->seq++;

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('0912000000'.$this->seq),
            $this->makeTruck('1'.$this->seq, 'ب', '34'.$this->seq, '11'),
            $this->product,
        ));

        $move = app(TransitionAppointment::class);
        $appointment = $move($appointment, AppointmentStatus::CheckedIn, Actor::system());

        if ($record !== null) {
            LoadingRecord::create(array_merge(['appointment_id' => $appointment->id], $record));
        }

        if ($status === AppointmentStatus::CheckedIn) {
            return $appointment;
        }

        $appointment = $move($appointment, AppointmentStatus::Loading, Actor::system());

        return $move($appointment, AppointmentStatus::Loaded, Actor::system());
    }

    /** @return array{tare: int, gross: int} */
    private function counters(): array
    {
        $seen = [];

        $this->actingAs($this->scaleman(), 'web')
            ->get(route('staff.weighbridge.index'))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$seen) {
                $seen = $page->toArray()['props']['pending'];
            });

        return $seen;
    }

    #[Test]
    public function a_truck_already_weighed_empty_is_no_longer_waiting_for_the_first_scale(): void
    {
        // منتظرِ واقعی
        $this->truckAt(AppointmentStatus::CheckedIn);

        // وزن خالی‌اش گرفته شده و در نوبتِ لاین ایستاده — کارِ باسکول نیست
        $this->truckAt(AppointmentStatus::CheckedIn, [
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $this->assertSame(1, $this->counters()['tare']);
    }

    #[Test]
    public function a_truck_that_already_has_its_exit_permit_is_no_longer_waiting_for_the_second_scale(): void
    {
        // منتظرِ واقعی
        $this->truckAt(AppointmentStatus::Loaded, [
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        // توزین پرش تمام شده و برگه گرفته؛ فقط منتظر ثبت خروج است
        $this->truckAt(AppointmentStatus::Loaded, [
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
            'loaded_weight_kg' => 44000,
            'net_weight_kg' => 30000,
            'exit_permit_number' => 'EX-1',
            'exit_permit_issued_at' => now(),
        ]);

        $this->assertSame(1, $this->counters()['gross']);
    }

    #[Test]
    public function a_rejected_weighing_still_counts_as_waiting(): void
    {
        // اضافه‌بار ثبت شده ولی برگه‌ای صادر نشده: کامیون هنوز کارِ باسکول است
        $this->truckAt(AppointmentStatus::Loaded, [
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
            'loaded_weight_kg' => 60000,
            'net_weight_kg' => 46000,
            'is_overload' => true,
            'discrepancy_kind' => 'overload',
        ]);

        $this->assertSame(1, $this->counters()['gross']);
    }
}
