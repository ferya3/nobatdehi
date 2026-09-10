<?php

declare(strict_types=1);

namespace Tests\Feature\Gate;

use App\Domain\Access\Permissions;
use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\SecurityLogger;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * «بدون QR ورود ممنوع» و «مغایرت پلاک یعنی راهبند بسته» دو قانون‌اند که
 * اگر فقط در UI باشند، یک درخواست POST خام دورشان می‌زند.
 */
final class GateSecurityTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->driver = $this->makeDriver('09123456789')->refresh();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function staff(string $role, string $email = 'gate@test.local'): User
    {
        $user = User::create([
            'name' => 'نگهبان',
            'email' => $email,
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role));

        return $user->fresh();
    }

    private function book(): Appointment
    {
        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->driver,
            $this->makeTruck('12', 'ب', '345', '11'),
        ));
    }

    /** QR تازه صادر می‌کند و hash را روی نوبت می‌نشاند */
    private function freshToken(Appointment $appointment): string
    {
        $issued = QrToken::issue($appointment);
        $appointment->forceFill(['qr_token_hash' => $issued['hash']])->save();

        return $issued['token'];
    }

    #[Test]
    public function test_a_guard_cannot_check_a_truck_in_without_scanning(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityLogger::GATE_NO_QR,
            'identifier' => $appointment->ulid,
        ]);
    }

    #[Test]
    public function test_a_plate_lookup_alone_does_not_open_the_gate(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);

        // نگهبان نوبت را پیدا می‌کند...
        $this->actingAs($guard)->followingRedirects()->post(route('staff.gate.lookup'), [
            'plate_two' => '12', 'plate_letter' => 'ب', 'plate_three' => '345', 'plate_iran' => '11',
        ])->assertOk();

        // ...ولی همان جستجو حق ورود نمی‌دهد
        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_scan_followed_by_a_plate_confirmation_opens_the_gate(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);
        $token = $this->freshToken($appointment);

        $this->actingAs($guard)->followingRedirects()->post(route('staff.gate.scan'), ['token' => $token])->assertOk();

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
        $this->assertSame('qr', $appointment->gate_entry_method);
    }

    #[Test]
    public function test_a_guard_who_reports_a_plate_mismatch_does_not_open_the_gate(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);

        $this->actingAs($guard)->followingRedirects()->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => false])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityLogger::GATE_PLATE_MISMATCH,
            'identifier' => $appointment->ulid,
        ]);
    }

    #[Test]
    public function test_a_plate_reader_reading_the_wrong_truck_beats_the_guards_confirmation(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);

        $this->actingAs($guard)->followingRedirects()->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        // نگهبان «مطابق است» می‌زند، ولی دوربین پلاک دیگری خوانده
        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_match' => true,
                'observed_plate' => '99 ب 888 ایران 22',
            ])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_plate_reader_reading_the_right_truck_in_any_format_opens_the_gate(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);

        $this->actingAs($guard)->followingRedirects()->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_match' => false,          // دستگاه حرف آخر را می‌زند، نه نگهبان
                'observed_plate' => '۱۲ب۳۴۵ایران۱۱',
            ])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_manager_may_override_but_only_with_a_written_reason(): void
    {
        $appointment = $this->book();
        $manager = $this->staff(Roles::FACTORY_MANAGER, 'manager@test.local');

        $this->assertTrue($manager->can(Permissions::GATE_MANUAL_OVERRIDE));

        // بدون دلیل: رد
        $this->actingAs($manager)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);

        // با دلیل: ثبت می‌شود و به‌عنوان «دستی» علامت می‌خورد
        $this->actingAs($manager)
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_match' => true,
                'override_reason' => 'گوشی راننده خاموش بود و با کارت ملی تطبیق داده شد',
            ])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
        $this->assertSame('manual', $appointment->gate_entry_method);
        $this->assertSame($manager->id, $appointment->gate_override_by_user_id);
        $this->assertNotNull($appointment->gate_override_reason);
    }

    #[Test]
    public function test_a_guard_has_no_override_permission_at_all(): void
    {
        $guard = $this->staff(Roles::GATE);

        $this->assertFalse($guard->can(Permissions::GATE_MANUAL_OVERRIDE));
    }

    #[Test]
    public function test_one_scan_lets_exactly_one_truck_in(): void
    {
        $appointment = $this->book();
        $guard = $this->staff(Roles::GATE);

        $this->actingAs($guard)->followingRedirects()->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('success');

        // برگرداندن وضعیت و تلاش دوباره با همان session: بلیط مصرف شده است
        $appointment->forceFill(['status' => AppointmentStatus::Booked->value])->save();

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('error');
    }
}
