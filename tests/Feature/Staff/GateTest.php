<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Appointment\Support\QrToken;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class GateTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private \App\Models\Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->seedStaff();
    }

    private function gate(): User
    {
        return User::role(Roles::GATE)->firstOrFail();
    }

    /** نوبت امروز، همراه با توکن QR صادرشده */
    private function todayAppointmentWithQr(string $plateTwo = '12'): array
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('0912100'.$plateTwo.'00'),
            $this->makeTruck($plateTwo, 'ب', '345', '67'),
            $this->futureSlot($this->factory),
        ));

        $appointment->forceFill(['date' => now()->toDateString()])->save();

        ['token' => $token, 'hash' => $hash] = QrToken::issue($appointment->fresh());
        $appointment->forceFill(['qr_token_hash' => $hash])->save();

        return [$appointment->fresh(), $token];
    }

    #[Test]
    public function the_gate_page_shows_the_on_site_count(): void
    {
        $this->actingAs($this->gate())
            ->get(route('staff.gate.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Gate/Index')
                ->where('onSiteCount', 0));
    }

    #[Test]
    public function scanning_a_valid_code_shows_the_appointment(): void
    {
        [$appointment, $token] = $this->todayAppointmentWithQr();

        $this->actingAs($this->gate())
            ->post(route('staff.gate.scan'), ['token' => $token])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.error', null)
                ->where('result.appointment.number', $appointment->number)
                ->where('result.appointment.can_check_in', true));
    }

    #[Test]
    public function a_forged_or_tampered_code_is_refused_and_logged(): void
    {
        [, $token] = $this->todayAppointmentWithQr();

        $this->actingAs($this->gate())
            ->post(route('staff.gate.scan'), ['token' => substr($token, 0, -1).'x'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment', null)
                ->whereNot('result.error', null));

        $this->assertDatabaseHas('security_logs', ['event' => 'qr_invalid']);
    }

    #[Test]
    public function a_raw_appointment_id_is_not_a_valid_code(): void
    {
        [$appointment] = $this->todayAppointmentWithQr();

        foreach ([(string) $appointment->id, $appointment->ulid] as $guess) {
            $this->actingAs($this->gate())
                ->post(route('staff.gate.scan'), ['token' => $guess])
                ->assertInertia(fn (AssertableInertia $page) => $page->where('result.appointment', null));
        }
    }

    #[Test]
    public function an_old_code_stops_working_once_a_new_one_is_issued(): void
    {
        [$appointment, $first] = $this->todayAppointmentWithQr();

        ['hash' => $hash] = QrToken::issue($appointment);
        $appointment->forceFill(['qr_token_hash' => $hash])->save();

        $this->actingAs($this->gate())
            ->post(route('staff.gate.scan'), ['token' => $first])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->whereNot('result.error', null)
                ->where('result.appointment.can_check_in', false));

        $this->assertDatabaseHas('security_logs', ['event' => 'qr_replay']);
    }

    #[Test]
    public function checking_in_records_the_arrival_and_closes_the_gate_behind_it(): void
    {
        [$appointment, $token] = $this->todayAppointmentWithQr();
        $guard = $this->gate();

        // بدون اسکن، ورود ثبت نمی‌شود — پس اول اسکن
        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $token]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertRedirect(route('staff.gate.index'))
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(S::CheckedIn, $appointment->status);
        $this->assertNotNull($appointment->checked_in_at);
        $this->assertNotNull($appointment->qr_used_at);

        // توکن عمداً زنده می‌ماند: راننده همین کد را در باسکول و لاین بارگیری
        // هم نشان می‌دهد. چیزی که دوباره‌کاری را می‌بندد، وضعیت نوبت است.
        $this->assertNotNull($appointment->qr_token_hash);

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $token]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('error');

        $this->assertSame(1, $appointment->transitions()->where('to_status', S::CheckedIn->value)->count());
    }

    #[Test]
    public function a_plate_lookup_finds_todays_active_appointment(): void
    {
        [$appointment] = $this->todayAppointmentWithQr('34');

        $this->actingAs($this->gate())
            ->post(route('staff.gate.lookup'), [
                'plate_two' => '۳۴',
                'plate_letter' => 'ب',
                'plate_three' => '۳۴۵',
                'plate_iran' => '۶۷',
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment.number', $appointment->number));
    }

    #[Test]
    public function a_plate_with_no_appointment_today_says_so(): void
    {
        $this->actingAs($this->gate())
            ->post(route('staff.gate.lookup'), [
                'plate_two' => '99',
                'plate_letter' => 'ی',
                'plate_three' => '999',
                'plate_iran' => '99',
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment', null)
                ->whereNot('result.error', null));
    }

    #[Test]
    public function a_completed_appointment_cannot_be_checked_in_again(): void
    {
        [$appointment] = $this->todayAppointmentWithQr();
        $appointment->forceFill(['status' => S::Completed])->save();

        $this->actingAs($this->gate())
            ->from(route('staff.gate.index'))
            ->post(route('staff.gate.check-in', $appointment->fresh()), ['plate_match' => true])
            ->assertSessionHas('error');

        $this->assertSame(S::Completed, $appointment->fresh()->status);
    }

    #[Test]
    public function a_role_without_the_checkin_permission_is_refused(): void
    {
        [$appointment, $token] = $this->todayAppointmentWithQr();
        $ceo = User::role(Roles::CEO)->firstOrFail();

        $this->actingAs($ceo)->get(route('staff.gate.index'))->assertForbidden();
        $this->actingAs($ceo)->post(route('staff.gate.scan'), ['token' => $token])->assertForbidden();
        $this->actingAs($ceo)->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])->assertForbidden();
    }

    #[Test]
    public function a_code_for_another_factory_is_not_accepted(): void
    {
        [$appointment, $token] = $this->todayAppointmentWithQr();

        $other = \App\Models\Factory::create([
            'name' => 'کارخانه دوم',
            'slug' => 'second',
        ]);

        $appointment->forceFill(['factory_id' => $other->id])->save();

        $this->actingAs($this->gate())
            ->post(route('staff.gate.scan'), ['token' => $token])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('result.appointment', null));
    }
}
