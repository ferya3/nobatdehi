<?php

declare(strict_types=1);

namespace Tests\Feature\Loading;

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
 * پشتیبانِ اسکن روی لاین بارگیری.
 *
 * کاغذِ حواله در محوطه‌ی بارگیری خیس و پاره می‌شود و بارکدخوان خراب. بدون
 * راهِ پلاک، لاین در همان لحظه می‌ایستد — همان چیزی که در باسکول هم بود.
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

        $this->product = Product::where('factory_id', $this->factory->id)->firstOrFail();
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

    private ?User $loader = null;

    private function loader(): User
    {
        return $this->loader ??= $this->staff(Roles::WAREHOUSE, 'loader@test.local');
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

    private function book(string $mobile = '09123456789'): Appointment
    {
        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver($mobile),
            $this->truck->refresh(),
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

    private function weighTare(Appointment $appointment): void
    {
        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);
    }

    #[Test]
    public function the_plate_finds_the_waybill_when_no_scanner_works(): void
    {
        $appointment = $this->checkIn($this->book());
        $this->weighTare($appointment);

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Loading/Index')
                ->where('result.appointment.number', $appointment->number)
                // توزین خالی انجام شده: نوبتِ شروع بارگیری است
                ->where('result.appointment.action', 'start')
                ->where('result.appointment.has_tare', true));
    }

    #[Test]
    public function loading_started_after_a_plate_lookup_is_a_normal_start(): void
    {
        $appointment = $this->checkIn($this->book());
        $this->weighTare($appointment);

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
            ->assertOk();

        // پیدا کردن از راه پلاک هیچ اختیار تازه‌ای نمی‌دهد
        $this->actingAs($this->loader())
            ->post(route('staff.loading.transition', $appointment), ['to' => 'LOADING'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Loading, $appointment->refresh()->status);
    }

    #[Test]
    public function the_plate_never_lets_loading_start_before_the_tare_weighing(): void
    {
        // همان قاعده‌ی همیشگی: بدون وزن خالی، وزن خالص قابل اثبات نیست
        $appointment = $this->checkIn($this->book());

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment.has_tare', false));

        $this->actingAs($this->loader())
            ->from(route('staff.loading.index'))
            ->post(route('staff.loading.transition', $appointment), ['to' => 'LOADING'])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
    }

    #[Test]
    public function it_prefers_the_waybill_that_actually_has_work_on_the_line(): void
    {
        // همان کامیون، دو حواله‌ی امروز: اولی بارگیری‌شده و رفته، دومی منتظر
        $this->factory->update(['max_active_per_plate' => 5]);

        $done = $this->checkIn($this->book('09120000001'));
        $this->weighTare($done);
        app(TransitionAppointment::class)($done, AppointmentStatus::Loading, Actor::system());
        app(TransitionAppointment::class)($done->refresh(), AppointmentStatus::Loaded, Actor::system());

        $waiting = $this->checkIn($this->book('09120000002'));
        $this->weighTare($waiting);

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // بدون این، مسئول لاین حواله‌ی تمام‌شده را می‌بیند و فکر
                // می‌کند سامانه گم کرده است
                ->where('result.appointment.number', $waiting->number)
                ->where('result.appointment.action', 'start'));
    }

    #[Test]
    public function a_waybill_with_nothing_to_do_is_still_shown_so_the_reason_is_visible(): void
    {
        // ثبت شده ولی هنوز وارد محوطه نشده: کارِ لاین نیست
        $appointment = $this->book();

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment.number', $appointment->number)
                // صفحه خودش می‌گوید چرا کاری نیست
                ->where('result.appointment.action', null)
                ->where('result.error', null));
    }

    #[Test]
    public function an_unknown_plate_says_so_plainly(): void
    {
        $this->checkIn($this->book());

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate('99', '888'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment', null)
                ->where('result.error', 'برای این پلاک امروز حواله‌ای ثبت نشده است.'));
    }

    #[Test]
    public function a_malformed_plate_is_refused_by_validation(): void
    {
        $this->actingAs($this->loader())
            ->from(route('staff.loading.index'))
            ->post(route('staff.loading.lookup'), array_merge($this->plate(), ['plate_letter' => 'Z']))
            ->assertSessionHasErrors('plate_letter');
    }

    #[Test]
    public function a_scaleman_cannot_look_up_plates_on_the_loading_line(): void
    {
        // باسکول‌بان جستجوی پلاکِ خودش را دارد، ولی لاین صفحه‌ی او نیست
        $this->actingAs($this->staff(Roles::WEIGHBRIDGE, 'scale@test.local'))
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
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

        $this->actingAs($this->loader())
            ->followingRedirects()->post(route('staff.loading.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('result.appointment', null));
    }
}
