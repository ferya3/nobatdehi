<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Devices\DeviceTokens;
use App\Domain\Weighbridge\WeightSource;
use App\Http\Middleware\VerifyDeviceToken;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\ScaleReading;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * اتصال نشان‌دهنده‌ی باسکول.
 *
 * چیزی که این کار عوض می‌کند: «مستقیم از باسکول» قبلاً یک تیک در فرم بود و
 * عدد را همان اپراتور تایپ می‌کرد. حالا یا ردیفی در scale_readings پشتش
 * هست، یا اصلاً device نیست. این تست‌ها همان مرز را نگه می‌دارند.
 */
final class ScaleBridgeTest extends TestCase
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

    private function enableBridge(string $token = 'scale-token-for-tests'): void
    {
        Setting::putMany([
            'scale_device_enabled' => '1',
            'scale_device_token' => $token,
        ]);
    }

    private function operator(): User
    {
        $user = User::create([
            'name' => 'باسکول‌بان',
            'email' => 'scale@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(Roles::WEIGHBRIDGE));

        return $user->fresh();
    }

    /** کامیونی که وارد محوطه شده و منتظر توزین خالی است */
    private function checkedIn(): Appointment
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->driver,
            $this->makeTruck('12', 'ب', '345', '11'),
        ));

        $appointment->forceFill([
            'status' => AppointmentStatus::CheckedIn->value,
            'checked_in_at' => now(),
        ])->save();

        return $appointment->fresh();
    }

    private function reading(float $kg, array $overrides = []): ScaleReading
    {
        return ScaleReading::create(array_merge([
            'factory_id' => $this->factory->id,
            'scale_name' => 'ورودی',
            'weight_kg' => $kg,
            'is_stable' => true,
            'read_at' => now(),
        ], $overrides));
    }

    // -------------------------------------------------------------- ورودیِ پل

    #[Test]
    public function test_the_endpoint_does_not_exist_until_the_bridge_is_switched_on(): void
    {
        Setting::putMany(['scale_device_token' => 'scale-token-for-tests']);

        $this->postJson(route('api.weighbridge.reading'), ['scale' => 'ورودی', 'weight_kg' => 14500])
            ->assertNotFound();

        $this->assertSame(0, ScaleReading::count());
    }

    #[Test]
    public function test_a_wrong_token_is_rejected_and_logged(): void
    {
        $this->enableBridge();

        $this->postJson(route('api.weighbridge.reading'), ['scale' => 'ورودی', 'weight_kg' => 14500], [
            VerifyDeviceToken::HEADER => 'not-the-token',
        ])->assertUnauthorized();

        $this->assertSame(0, ScaleReading::count());

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityLogger::PERMISSION_DENIED,
            'identifier' => 'device.'.DeviceTokens::SCALE,
        ]);
    }

    #[Test]
    public function test_the_gate_token_does_not_open_the_scale_endpoint(): void
    {
        $this->enableBridge();

        // توکن دوربین ساخته شده و درست است — ولی برای دستگاه دیگری
        Setting::putMany(['gate_anpr_enabled' => '1', 'gate_anpr_token' => 'the-camera-token']);

        $this->postJson(route('api.weighbridge.reading'), ['scale' => 'ورودی', 'weight_kg' => 14500], [
            VerifyDeviceToken::HEADER => 'the-camera-token',
        ])->assertUnauthorized();

        $this->assertSame(0, ScaleReading::count());
    }

    #[Test]
    public function test_a_reading_is_stored_with_its_raw_frame(): void
    {
        $this->enableBridge();

        $this->postJson(route('api.weighbridge.reading'), [
            'scale' => 'ورودی',
            'weight_kg' => 14500.5,
            'stable' => true,
            'device' => 'weighbridge-pc',
            'raw' => 'ST,GS,+  14500.5 kg',
        ], [VerifyDeviceToken::HEADER => 'scale-token-for-tests'])->assertCreated();

        $reading = ScaleReading::firstOrFail();

        $this->assertSame('ورودی', $reading->scale_name);
        $this->assertSame('14500.50', $reading->weight_kg);
        $this->assertTrue($reading->is_stable);
        // فریم خام می‌ماند تا اگر پارسر اشتباه بخواند، بشود فهمید
        $this->assertSame('ST,GS,+  14500.5 kg', $reading->raw_frame);
    }

    #[Test]
    public function test_an_unstable_or_zero_reading_is_still_stored(): void
    {
        $this->enableBridge();

        foreach ([['weight_kg' => 0, 'stable' => false], ['weight_kg' => -12, 'stable' => false]] as $payload) {
            $this->postJson(
                route('api.weighbridge.reading'),
                array_merge(['scale' => 'ورودی'], $payload),
                [VerifyDeviceToken::HEADER => 'scale-token-for-tests'],
            )->assertCreated();
        }

        // عددِ عجیب همان چیزی است که نشان می‌دهد باسکول مشکل دارد
        $this->assertSame(2, ScaleReading::count());
    }

    // ------------------------------------------------------ ثبت وزن از باسکول

    #[Test]
    public function test_a_device_reading_sets_the_weight_and_the_form_number_is_ignored(): void
    {
        $this->enableBridge();
        $appointment = $this->checkedIn();
        $reading = $this->reading(14500);

        $this->actingAs($this->operator())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'reading_id' => $reading->id,
                // اپراتور عدد دیگری هم بفرستد، خوانده نمی‌شود
                'weight_kg' => 9999,
            ])
            ->assertSessionHas('success');

        $record = LoadingRecord::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertSame('14500.00', $record->empty_weight_kg);
        $this->assertSame(WeightSource::DEVICE, $record->tare_source);
        $this->assertSame($reading->id, $record->tare_reading_id);
        // خواندن به همان حواله گره می‌خورد تا در سابقه پیدا شود
        $this->assertSame($appointment->id, $reading->refresh()->appointment_id);
    }

    #[Test]
    public function test_an_unstable_reading_is_refused(): void
    {
        $this->enableBridge();
        $appointment = $this->checkedIn();
        $reading = $this->reading(14500, ['is_stable' => false]);

        $this->actingAs($this->operator())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'reading_id' => $reading->id,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('loading_records', 0);
    }

    #[Test]
    public function test_an_unstable_reading_is_accepted_when_the_indicator_has_no_stability_flag(): void
    {
        $this->enableBridge();
        // نشان‌دهنده‌ای که پرچم پایداری نمی‌فرستد، وگرنه هیچ وزنی ثبت نمی‌شود
        Setting::putMany(['scale_require_stable' => '0']);

        $appointment = $this->checkedIn();
        $reading = $this->reading(14500, ['is_stable' => false]);

        $this->actingAs($this->operator())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'reading_id' => $reading->id,
            ])
            ->assertSessionHas('success');
    }

    #[Test]
    public function test_a_stale_reading_is_refused(): void
    {
        $this->enableBridge();
        $appointment = $this->checkedIn();

        // عددِ دو دقیقه پیش، وزنِ کامیون بعدی است
        $reading = $this->reading(14500, [
            'read_at' => now()->subSeconds(ScaleReading::FRESH_SECONDS + 10),
        ]);

        $this->actingAs($this->operator())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'reading_id' => $reading->id,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('loading_records', 0);
    }

    #[Test]
    public function test_a_reading_from_another_factory_is_refused(): void
    {
        $this->enableBridge();
        $appointment = $this->checkedIn();

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $reading = $this->reading(14500, ['factory_id' => $other->id]);

        $this->actingAs($this->operator())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'reading_id' => $reading->id,
            ])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('loading_records', 0);
    }

    // ------------------------------------------------------------- ورود دستی

    #[Test]
    public function test_manual_entry_needs_a_written_reason_once_a_scale_is_connected(): void
    {
        $this->enableBridge();
        $appointment = $this->checkedIn();
        $operator = $this->operator();

        $this->actingAs($operator)
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'weight_kg' => 14500,
            ])
            ->assertSessionHasErrors('manual_reason');

        $this->actingAs($operator)
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'weight_kg' => 14500,
                'manual_reason' => 'کابل باسکول قطع بود و عدد از روی نشان‌دهنده خوانده شد',
            ])
            ->assertSessionHas('success');

        $record = LoadingRecord::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertSame(WeightSource::MANUAL, $record->tare_source);
        $this->assertNull($record->tare_reading_id);
        $this->assertNotNull($record->tare_manual_reason);

        // دور زدنِ باسکولِ سالم باید جایی ثبت شود که بعداً دیده شود
        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityLogger::ADMIN_ACTION,
            'identifier' => $appointment->ulid,
        ]);
    }

    #[Test]
    public function test_manual_entry_needs_no_reason_when_no_scale_is_connected(): void
    {
        // پل خاموش است: تایپ‌کردن تنها راه است و پرسیدنِ «چرا» فقط فیلدی
        // می‌سازد که با هر چیزی پر می‌شود.
        $appointment = $this->checkedIn();

        $this->actingAs($this->operator())
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'weight_kg' => 14500,
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            WeightSource::MANUAL,
            LoadingRecord::where('appointment_id', $appointment->id)->firstOrFail()->tare_source,
        );
    }

    // ---------------------------------------------------------- صفحه و تنظیمات

    #[Test]
    public function test_the_weighbridge_page_shows_the_live_number_per_scale(): void
    {
        $this->enableBridge();

        $this->reading(14500, ['scale_name' => 'ورودی']);
        $this->reading(22300, ['scale_name' => 'خروجی']);
        // عددِ قدیمیِ همان باسکول نباید جای تازه را بگیرد
        $this->reading(999, ['scale_name' => 'ورودی', 'read_at' => now()->subMinutes(10)]);

        $response = $this->actingAs($this->operator())->getJson(route('staff.weighbridge.readings'));

        $response->assertOk();

        $scales = collect($response->json('scales'))->keyBy('scale');

        $this->assertCount(2, $scales);
        $this->assertEqualsWithDelta(14500, $scales['ورودی']['weight_kg'], 0.001);
        $this->assertEqualsWithDelta(22300, $scales['خروجی']['weight_kg'], 0.001);
    }

    #[Test]
    public function test_rotating_the_scale_token_leaves_the_camera_token_alone(): void
    {
        $manager = User::create([
            'name' => 'مدیر',
            'email' => 'manager@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);
        $manager->assignRole(Role::findByName(Roles::FACTORY_MANAGER));

        Setting::putMany(['gate_anpr_token' => 'the-camera-token']);

        $this->actingAs($manager->fresh())
            ->post(route('staff.settings.devices.token'), ['kind' => 'scale'])
            ->assertRedirect();

        Setting::forget();

        $tokens = app(DeviceTokens::class);

        $this->assertNotSame('', $tokens->token(DeviceTokens::SCALE));
        // چرخاندنِ توکن یک دستگاه نباید دستگاه دیگر را بخواباند
        $this->assertSame('the-camera-token', $tokens->token(DeviceTokens::GATE));
    }
}
