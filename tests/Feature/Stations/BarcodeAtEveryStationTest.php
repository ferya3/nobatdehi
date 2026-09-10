<?php

declare(strict_types=1);

namespace Tests\Feature\Stations;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * بارکدخوان فقط مالِ گیت نیست.
 *
 * راننده همان کد را در باسکول و لاین بارگیری هم نشان می‌دهد؛ اگر آنجا فقط
 * دوربین تبلت کار کند، اپراتور باید کاری کند که در گیت لازم نبود.
 */
final class BarcodeAtEveryStationTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function staff(string $role, string $email): User
    {
        return tap(User::create([
            'name' => 'کاربر',
            'email' => $email,
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]), fn (User $u) => $u->assignRole(Role::findByName($role)))->fresh();
    }

    private function checkedIn(): Appointment
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '11'),
        ));

        return app(TransitionAppointment::class)($appointment, AppointmentStatus::CheckedIn, Actor::system());
    }

    private function tokenFor(Appointment $appointment): string
    {
        $issued = QrToken::issue($appointment);
        $appointment->forceFill(['qr_token_hash' => $issued['hash']])->save();

        return $issued['token'];
    }

    #[Test]
    public function test_the_weighbridge_offers_the_barcode_reader(): void
    {
        $this->actingAs($this->staff(Roles::WEIGHBRIDGE, 'scale@test.local'))
            ->get(route('staff.weighbridge.index'))
            ->assertInertia(fn ($page) => $page->where('barcodeEnabled', true));
    }

    #[Test]
    public function test_the_loading_line_offers_the_barcode_reader(): void
    {
        $this->actingAs($this->staff(Roles::WAREHOUSE, 'warehouse@test.local'))
            ->get(route('staff.loading.index'))
            ->assertInertia(fn ($page) => $page->where('barcodeEnabled', true));
    }

    #[Test]
    public function test_turning_the_barcode_reader_off_hides_it_everywhere(): void
    {
        Setting::putMany(['gate_barcode_enabled' => '0']);

        $this->actingAs($this->staff(Roles::WEIGHBRIDGE, 'scale@test.local'))
            ->get(route('staff.weighbridge.index'))
            ->assertInertia(fn ($page) => $page->where('barcodeEnabled', false));

        $this->actingAs($this->staff(Roles::WAREHOUSE, 'warehouse@test.local'))
            ->get(route('staff.loading.index'))
            ->assertInertia(fn ($page) => $page->where('barcodeEnabled', false));
    }

    #[Test]
    public function test_a_barcode_payload_finds_the_waybill_at_the_weighbridge(): void
    {
        $appointment = $this->checkedIn();

        // بارکدخوان همان رشته‌ای را می‌فرستد که دوربین می‌خواند
        $this->actingAs($this->staff(Roles::WEIGHBRIDGE, 'scale@test.local'))
            ->followingRedirects()->post(route('staff.weighbridge.scan'), ['token' => $this->tokenFor($appointment)])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('result.appointment.number', $appointment->number));
    }

    #[Test]
    public function test_a_barcode_payload_finds_the_waybill_at_the_loading_line(): void
    {
        $appointment = $this->checkedIn();

        $this->actingAs($this->staff(Roles::WAREHOUSE, 'warehouse@test.local'))
            ->followingRedirects()->post(route('staff.loading.scan'), ['token' => $this->tokenFor($appointment)])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('result.appointment.number', $appointment->number));
    }
}
