<?php

declare(strict_types=1);

namespace Tests\Feature\Loading;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * «بارگیری فقط با حواله‌ی مجاز» یعنی بدون اسکن، دکمه‌ای وجود ندارد — و
 * «هشدار تأخیر» یعنی کسی خبردار می‌شود، نه اینکه فقط رنگ سطر عوض شود.
 */
final class LoadingStationTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private ?User $warehouse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function warehouse(): User
    {
        return $this->warehouse ??= tap(User::create([
            'name' => 'مسئول بارگیری',
            'email' => 'warehouse@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]), fn (User $u) => $u->assignRole(Role::findByName(Roles::WAREHOUSE)))->fresh();
    }

    private function readyToLoad(bool $withTare = true): Appointment
    {
        // بدون نوع کامیون، expectedLoadingMinutes() به محصول/کارخانه می‌افتد
        // و تستِ تأخیر عملاً چیز دیگری را می‌سنجد
        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->update(['truck_type_id' => TruckType::orderBy('id')->value('id')]);

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $truck->refresh(),
        ));

        $appointment = app(TransitionAppointment::class)($appointment, AppointmentStatus::CheckedIn, Actor::system());

        if ($withTare) {
            $this->recordTare($appointment);
        }

        return $appointment->refresh();
    }

    private function tokenFor(Appointment $appointment): string
    {
        $issued = QrToken::issue($appointment);
        $appointment->forceFill(['qr_token_hash' => $issued['hash']])->save();

        return $issued['token'];
    }

    #[Test]
    public function test_the_same_waybill_qr_still_works_at_the_loading_line(): void
    {
        $appointment = $this->readyToLoad();

        $this->actingAs($this->warehouse())
            ->followingRedirects()->post(route('staff.loading.scan'), ['token' => $this->tokenFor($appointment)])
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('result.appointment.action', 'start')
                ->where('result.appointment.has_tare', true));
    }

    #[Test]
    public function test_a_forged_code_finds_nothing(): void
    {
        $this->readyToLoad();

        $this->actingAs($this->warehouse())
            ->followingRedirects()->post(route('staff.loading.scan'), ['token' => 'v1.NOPE.x.9999999999.deadbeef'])
            ->assertInertia(fn ($page) => $page
                ->where('result.appointment', null)
                ->whereNot('result.error', null));
    }

    #[Test]
    public function test_loading_cannot_start_without_the_first_weighbridge(): void
    {
        $appointment = $this->readyToLoad(withTare: false);
        $line = LoadingPoint::where('factory_id', $this->factory->id)->firstOrFail();

        $this->actingAs($this->warehouse())
            ->post(route('staff.loading.transition', $appointment), [
                'to' => AppointmentStatus::Loading->value,
                'loading_point_id' => $line->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
    }

    #[Test]
    public function test_the_station_walks_a_truck_from_start_to_finish(): void
    {
        $appointment = $this->readyToLoad();
        $line = LoadingPoint::where('factory_id', $this->factory->id)->firstOrFail();

        $this->actingAs($this->warehouse())
            ->post(route('staff.loading.transition', $appointment), [
                'to' => AppointmentStatus::Loading->value,
                'loading_point_id' => $line->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Loading, $appointment->refresh()->status);
        $this->assertSame($line->id, $appointment->loading_point_id);

        $this->actingAs($this->warehouse())
            ->post(route('staff.loading.transition', $appointment), ['to' => AppointmentStatus::Loaded->value])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Loaded, $appointment->refresh()->status);
    }

    #[Test]
    public function test_the_loading_station_refuses_transitions_that_are_not_its_job(): void
    {
        $appointment = $this->readyToLoad();

        $this->actingAs($this->warehouse())
            ->post(route('staff.loading.transition', $appointment), ['to' => AppointmentStatus::Completed->value])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_truck_inside_its_expected_time_is_not_late(): void
    {
        $appointment = $this->startLoading();

        $this->travelTo(now()->addMinutes(10));

        $this->assertFalse($appointment->refresh()->isLoadingLate());

        $this->artisan('loading:alert-delays')->expectsOutputToContain('بارگیری با تأخیری نبود.');
    }

    #[Test]
    public function test_a_truck_past_its_expected_time_raises_one_alert_per_appointment(): void
    {
        Setting::putMany([
            'sms_manager_recipients' => '09120000001,09120000002',
            'sms_enabled' => '1',
        ]);

        $appointment = $this->startLoading(expectedMinutes: 20);

        // ۳۵ دقیقه روی لاینِ ۲۰ دقیقه‌ای — بیشتر از آستانه‌ی ۱.۵ برابر
        $this->travelTo(now()->addMinutes(35));

        $this->assertTrue($appointment->refresh()->isLoadingLate());

        $this->artisan('loading:alert-delays')->assertSuccessful();

        $this->assertSame(2, SmsMessage::count());

        // اجرای دوباره نباید همان هشدار را تکرار کند
        $this->artisan('loading:alert-delays')->assertSuccessful();

        $this->assertSame(2, SmsMessage::count());
    }

    /** نوبتی که روی لاین است، با مدت بارگیریِ مشخص */
    private function startLoading(int $expectedMinutes = 20): Appointment
    {
        TruckType::query()->update(['loading_minutes' => $expectedMinutes]);

        $appointment = $this->readyToLoad();

        return app(TransitionAppointment::class)($appointment, AppointmentStatus::Loading, Actor::system());
    }
}
