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
use App\Models\Truck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * پشتیبانِ اسکن در باسکول.
 *
 * بارکدخوان خراب می‌شود و QR روی کاغذِ خیس خوانده نمی‌شود. بدون راهِ پلاک،
 * باسکول در همان لحظه می‌ایستد.
 */
final class PlateLookupTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    private Truck $truck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);

        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();
        $this->truck = $this->makeTruck('12', 'ب', '345', '11');
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

    private ?User $scaleman = null;

    private function scaleman(): User
    {
        return $this->scaleman ??= $this->staff(Roles::WEIGHBRIDGE, 'scale@test.local');
    }

    /** @return array<string, string> */
    private function plate(string $two = '12', string $three = '345'): array
    {
        return [
            'plate_two' => $two,
            'plate_letter' => 'ب',
            'plate_three' => $three,
            'plate_iran' => '11',
        ];
    }

    private function book(string $mobile = '09123456789', ?Truck $truck = null): Appointment
    {
        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver($mobile),
            ($truck ?? $this->truck)->refresh(),
            $this->product,
        ));
    }

    private function checkIn(Appointment $appointment): Appointment
    {
        return app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::CheckedIn,
            Actor::system(),
        );
    }

    #[Test]
    public function the_plate_finds_the_waybill_when_no_scanner_works(): void
    {
        $appointment = $this->checkIn($this->book());

        $this->actingAs($this->scaleman())
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Weighbridge/Index')
                ->where('result.appointment.number', $appointment->number)
                // کامیون وارد محوطه شده: نوبتِ باسکول اول است
                ->where('result.appointment.stage', 'tare'));
    }

    #[Test]
    public function a_weight_recorded_after_a_plate_lookup_is_a_normal_weighing(): void
    {
        $appointment = $this->checkIn($this->book());

        $this->actingAs($this->scaleman())
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate())
            ->assertOk();

        // پیدا کردن از راه پلاک هیچ اختیار تازه‌ای نمی‌دهد؛ ثبت وزن مثل همیشه
        $this->actingAs($this->scaleman())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'weight_kg' => 14000,
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            '14000.00',
            LoadingRecord::where('appointment_id', $appointment->id)->value('empty_weight_kg'),
        );
    }

    #[Test]
    public function it_prefers_the_waybill_that_actually_has_weighing_to_do(): void
    {
        // همان کامیون، دو حواله‌ی امروز: اولی توزین‌شده و رفته، دومی منتظر
        $this->factory->update(['max_active_per_plate' => 5]);

        $done = $this->checkIn($this->book('09120000001'));

        LoadingRecord::create([
            'appointment_id' => $done->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $waiting = $this->checkIn($this->book('09120000002'));

        $this->actingAs($this->scaleman())
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // بدون این، اپراتور حواله‌ی توزین‌شده را می‌بیند و فکر
                // می‌کند سامانه اشتباه می‌کند
                ->where('result.appointment.number', $waiting->number)
                ->where('result.appointment.stage', 'tare'));
    }

    #[Test]
    public function a_waybill_with_nothing_to_weigh_is_still_shown_so_the_reason_is_visible(): void
    {
        // ثبت شده ولی هنوز وارد محوطه نشده: کار باسکول نیست
        $appointment = $this->book();

        $this->actingAs($this->scaleman())
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment.number', $appointment->number)
                // صفحه خودش می‌گوید چرا کاری نیست
                ->where('result.appointment.stage', null)
                ->where('result.error', null));
    }

    #[Test]
    public function an_unknown_plate_says_so_plainly(): void
    {
        $this->checkIn($this->book());

        $this->actingAs($this->scaleman())
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate('99', '888'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment', null)
                ->where('result.error', 'برای این پلاک امروز حواله‌ای ثبت نشده است.'));
    }

    #[Test]
    public function a_malformed_plate_is_refused_by_validation(): void
    {
        $this->actingAs($this->scaleman())
            ->from(route('staff.weighbridge.index'))
            ->post(route('staff.weighbridge.lookup'), array_merge($this->plate(), ['plate_letter' => 'Z']))
            ->assertSessionHasErrors('plate_letter');
    }

    #[Test]
    public function a_gate_guard_cannot_look_up_plates_at_the_weighbridge(): void
    {
        // نگهبان جستجوی پلاکِ خودش را دارد، ولی باسکول صفحه‌ی او نیست
        $this->actingAs($this->staff(Roles::GATE, 'gate@test.local'))
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate())
            ->assertForbidden();
    }

    #[Test]
    public function another_factorys_truck_is_not_found(): void
    {
        $appointment = $this->checkIn($this->book());

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $appointment->forceFill(['factory_id' => $other->id])->save();

        $this->actingAs($this->scaleman())
            ->followingRedirects()->post(route('staff.weighbridge.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('result.appointment', null));
    }
}
