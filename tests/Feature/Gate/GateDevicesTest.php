<?php

declare(strict_types=1);

namespace Tests\Feature\Gate;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Gate\GateDevices;
use App\Domain\Gate\PlateCapture;
use App\Domain\Gate\PlateVerdict;
use App\Domain\Gate\ScanTicket;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\PlateReading;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * بارکدخوان و دوربین پلاک‌خوان در گیت.
 *
 * دو دستگاه اضافه شده‌اند و هیچ‌کدام نباید قانون قبلی را شل کرده باشند:
 * بدون QR ورود ممنوع است، و پلاکِ مغایر یعنی راهبند بسته. آنچه عوض شده
 * فقط این است که حالا می‌شود ثابت کرد *چه چیزی* تأیید کرده.
 */
final class GateDevicesTest extends TestCase
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
        Storage::fake(PlateCapture::DISK);
    }

    private function guard(string $role = Roles::GATE, string $email = 'gate@test.local'): User
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

    private function freshToken(Appointment $appointment): string
    {
        $issued = QrToken::issue($appointment);
        $appointment->forceFill(['qr_token_hash' => $issued['hash']])->save();

        return $issued['token'];
    }

    /** خواندنی که انگار همین حالا از دوربین رسیده */
    private function reading(?string $plate, array $overrides = []): PlateReading
    {
        return PlateReading::create(array_merge([
            'factory_id' => $this->factory->id,
            'source' => PlateReading::SOURCE_ANPR,
            'raw_plate' => $plate,
            'plate_key' => $plate,
            'confidence' => 95,
            'captured_at' => now(),
        ], $overrides));
    }

    // ------------------------------------------------------------ بارکدخوان

    #[Test]
    public function test_a_barcode_reader_opens_the_gate_and_the_history_says_so(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        $this->actingAs($guard)->post(route('staff.gate.scan'), [
            'token' => $this->freshToken($appointment),
            'source' => 'barcode',
        ])->assertOk();

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
        $this->assertSame('qr', $appointment->gate_entry_method);
        // همان چیزی که قبلاً در سابقه گم می‌شد
        $this->assertSame(ScanTicket::SOURCE_BARCODE, $appointment->gate_scan_source);
        $this->assertSame(PlateVerdict::BY_GUARD, $appointment->gate_plate_source);
    }

    #[Test]
    public function test_an_unknown_scan_source_falls_back_to_the_camera(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        // دستگاهی که چیز عجیبی بفرستد نباید ستون را با مقدار دلخواهش پر کند
        $this->actingAs($guard)->post(route('staff.gate.scan'), [
            'token' => $this->freshToken($appointment),
            'source' => 'something-made-up',
        ])->assertOk();

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('success');

        $this->assertSame(ScanTicket::SOURCE_CAMERA, $appointment->refresh()->gate_scan_source);
    }

    #[Test]
    public function test_a_barcode_that_is_not_a_valid_ticket_does_not_open_anything(): void
    {
        $guard = $this->guard();

        $this->actingAs($guard)->post(route('staff.gate.scan'), [
            'token' => 'whatever-the-reader-picked-up',
            'source' => 'barcode',
        ])->assertOk();

        $this->assertDatabaseHas('security_logs', ['event' => SecurityLogger::QR_INVALID]);
    }

    // ------------------------------------------------- دوربین ایستگاه نگهبانی

    #[Test]
    public function test_a_guard_can_photograph_the_plate_and_the_photo_is_kept(): void
    {
        $guard = $this->guard();

        $response = $this->actingAs($guard)->post(route('staff.gate.capture'), [
            'image' => UploadedFile::fake()->image('plate.jpg'),
            'plate' => '۱۲ب۳۴۵ایران۱۱',
        ]);

        $response->assertOk()->assertJson(['recognised' => true, 'plate_key' => '12-ب-345-11']);

        $reading = PlateReading::firstOrFail();

        $this->assertSame(PlateReading::SOURCE_STATION, $reading->source);
        $this->assertSame($guard->id, $reading->captured_by_user_id);
        Storage::disk(PlateCapture::DISK)->assertExists($reading->image_path);
    }

    #[Test]
    public function test_the_photo_is_linked_to_the_appointment_it_let_in(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();
        $reading = $this->reading('12-ب-345-11', ['source' => PlateReading::SOURCE_STATION]);

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_reading_id' => $reading->id])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(PlateVerdict::BY_STATION, $appointment->gate_plate_source);
        $this->assertSame($reading->id, $appointment->gate_plate_reading_id);
        // از طرف دیگر هم پیدا می‌شود: عکس در سابقه‌ی همان نوبت می‌نشیند
        $this->assertSame($appointment->id, $reading->refresh()->appointment_id);
    }

    #[Test]
    public function test_a_photo_taken_during_a_refused_entry_is_still_kept_on_the_appointment(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        $this->actingAs($guard)->post(route('staff.gate.capture'), [
            'image' => UploadedFile::fake()->image('plate.jpg'),
            'plate' => '99ب88822',
            'appointment' => $appointment->ulid,
        ])->assertOk();

        $reading = PlateReading::firstOrFail();

        // راهبند باز نشد، ولی عکسِ پلاکِ مغایر در سابقه‌ی همان نوبت ماند
        $this->assertSame($appointment->id, $reading->appointment_id);
        $this->assertSame('99-ب-888-22', $reading->plate_key);
        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_photo_cannot_be_pinned_to_another_factorys_appointment(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $appointment->forceFill(['factory_id' => $other->id])->save();

        $this->actingAs($guard)->post(route('staff.gate.capture'), [
            'image' => UploadedFile::fake()->image('plate.jpg'),
            'appointment' => $appointment->ulid,
        ])->assertOk();

        $this->assertNull(PlateReading::firstOrFail()->appointment_id);
    }

    #[Test]
    public function test_a_photo_alone_never_replaces_the_qr_scan(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();
        $reading = $this->reading('12-ب-345-11', ['source' => PlateReading::SOURCE_STATION]);

        // پلاک درست است، ولی حواله‌ای اسکن نشده
        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_reading_id' => $reading->id])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    // ------------------------------------------------- حرفِ دوربین بالاتر است

    #[Test]
    public function test_the_camera_overrules_a_guard_who_confirms_the_wrong_truck(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();
        $reading = $this->reading('99-ب-888-22');

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_match' => true,          // نگهبان می‌گوید مطابق است
                'plate_reading_id' => $reading->id,
            ])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityLogger::GATE_PLATE_MISMATCH,
            'identifier' => $appointment->ulid,
        ]);
    }

    #[Test]
    public function test_a_stale_reading_does_not_open_the_gate(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        // پلاکِ درست، ولی خواندنش مالِ نیم ساعت پیش است
        $reading = $this->reading('12-ب-345-11', [
            'captured_at' => now()->subSeconds(PlateReading::FRESH_SECONDS + 60),
        ]);

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_reading_id' => $reading->id])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_reading_from_another_factory_is_refused(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $reading = $this->reading('12-ب-345-11', ['factory_id' => $other->id]);

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_reading_id' => $reading->id])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Booked, $appointment->refresh()->status);
    }

    #[Test]
    public function test_a_camera_that_could_not_read_hands_the_decision_back_to_the_guard(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        // شب، باران، پلاک گِلی: عکس هست ولی پلاکی خوانده نشده
        $reading = $this->reading(null, ['raw_plate' => '???', 'plate_key' => null, 'confidence' => null]);

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_reading_id' => $reading->id,
                'plate_match' => true,
            ])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
        // تصمیم با نگهبان بوده و سابقه هم همین را می‌گوید
        $this->assertSame(PlateVerdict::BY_GUARD, $appointment->gate_plate_source);
    }

    #[Test]
    public function test_a_low_confidence_reading_does_not_decide_on_its_own(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();

        Setting::putMany(['gate_anpr_min_confidence' => '80']);

        // دوربین پلاکِ دیگری خوانده ولی خودش هم مطمئن نیست
        $reading = $this->reading('99-ب-888-22', ['confidence' => 40]);

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_reading_id' => $reading->id,
                'plate_match' => true,
            ])
            ->assertSessionHas('success');

        $this->assertSame(PlateVerdict::BY_GUARD, $appointment->refresh()->gate_plate_source);
    }

    #[Test]
    public function test_a_confident_camera_opens_the_gate_without_the_guards_tick(): void
    {
        $appointment = $this->book();
        $guard = $this->guard();
        $reading = $this->reading('12-ب-345-11');

        $this->actingAs($guard)->post(route('staff.gate.scan'), ['token' => $this->freshToken($appointment)]);

        // plate_match اصلاً فرستاده نشده — دوربین کافی است
        $this->actingAs($guard)
            ->post(route('staff.gate.check-in', $appointment), ['plate_reading_id' => $reading->id])
            ->assertSessionHas('success');

        $appointment->refresh();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
        $this->assertSame(PlateVerdict::BY_ANPR, $appointment->gate_plate_source);
        $this->assertSame('12-ب-345-11', $appointment->gate_observed_plate);
    }

    // ------------------------------------------------------------- عکس و سابقه

    #[Test]
    public function test_the_plate_photo_is_not_public(): void
    {
        $guard = $this->guard();

        $this->actingAs($guard)->post(route('staff.gate.capture'), [
            'image' => UploadedFile::fake()->image('plate.jpg'),
        ])->assertOk();

        $reading = PlateReading::firstOrFail();

        // بدون ورود، حتی با دانستنِ آدرس
        $this->post(route('staff.logout'));
        $this->get(route('staff.gate.reading-image', $reading))->assertRedirect(route('staff.login'));

        // با ورود و دسترسی گیت
        $this->actingAs($guard)->get(route('staff.gate.reading-image', $reading))->assertOk();
    }

    #[Test]
    public function test_the_appointment_page_shows_how_the_truck_got_in(): void
    {
        $appointment = $this->book();
        $manager = $this->guard(Roles::FACTORY_MANAGER, 'manager@test.local');
        $reading = $this->reading('12-ب-345-11', ['source' => PlateReading::SOURCE_STATION]);

        $this->actingAs($manager)->post(route('staff.gate.scan'), [
            'token' => $this->freshToken($appointment),
            'source' => 'barcode',
        ]);

        $this->actingAs($manager)
            ->post(route('staff.gate.check-in', $appointment), ['plate_reading_id' => $reading->id])
            ->assertSessionHas('success');

        $this->actingAs($manager)
            ->get(route('staff.queue.show', $appointment))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('appointment.gate.scan_source', ScanTicket::SOURCE_BARCODE)
                ->where('appointment.gate.plate_source', PlateVerdict::BY_STATION)
                ->where('appointment.gate.reading.id', $reading->id)
                ->where('appointment.gate.reading.recognised', true),
            );
    }

    // ------------------------------------------------------------- تنظیمات

    #[Test]
    public function test_only_a_settings_manager_reaches_the_device_page(): void
    {
        $this->actingAs($this->guard())->get(route('staff.settings.devices'))->assertForbidden();

        $this->actingAs($this->guard(Roles::FACTORY_MANAGER, 'manager@test.local'))
            ->get(route('staff.settings.devices'))
            ->assertOk();
    }

    #[Test]
    public function test_rotating_the_token_invalidates_the_old_one(): void
    {
        $manager = $this->guard(Roles::FACTORY_MANAGER, 'manager@test.local');

        Setting::putMany([
            'gate_anpr_enabled' => '1',
            GateDevices::TOKEN_SETTING => 'the-old-token',
        ]);

        $this->actingAs($manager)
            ->post(route('staff.settings.devices.token'))
            ->assertRedirect();

        Setting::forget();

        $this->assertNotSame('the-old-token', app(GateDevices::class)->token());
        $this->assertFalse(app(GateDevices::class)->tokenMatches('the-old-token'));
    }
}
