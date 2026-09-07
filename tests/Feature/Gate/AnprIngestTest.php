<?php

declare(strict_types=1);

namespace Tests\Feature\Gate;

use App\Domain\Gate\GateDevices;
use App\Domain\Gate\PlateCapture;
use App\Domain\Audit\SecurityLogger;
use App\Http\Middleware\VerifyDeviceToken;
use App\Models\Factory;
use App\Models\PlateReading;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * مسیرِ دوربین پلاک‌خوان شبکه‌ای.
 *
 * این تنها مسیرِ بدون session در کل سامانه است، پس هر چیزی که آن را باز
 * بگذارد اینجا باید گیر بیفتد.
 */
final class AnprIngestTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        Storage::fake(PlateCapture::DISK);
    }

    private function enable(string $token = 'device-token-for-tests'): void
    {
        Setting::putMany([
            'gate_anpr_enabled' => '1',
            GateDevices::TOKEN_SETTING => $token,
        ]);
    }

    #[Test]
    public function test_the_endpoint_does_not_exist_until_it_is_switched_on(): void
    {
        Setting::putMany([GateDevices::TOKEN_SETTING => 'device-token-for-tests']);

        // خاموش است: نه ۴۰۱، بلکه ۴۰۴ — وجود مسیر هم اطلاعات است
        $this->postJson(route('api.gate.anpr'), ['plate' => '12ب34511'])
            ->assertNotFound();

        $this->assertSame(0, PlateReading::count());
    }

    #[Test]
    public function test_a_wrong_token_is_rejected_and_logged(): void
    {
        $this->enable();

        $this->postJson(route('api.gate.anpr'), ['plate' => '12ب34511'], [
            VerifyDeviceToken::GATE_HEADER => 'not-the-token',
        ])->assertUnauthorized();

        $this->assertSame(0, PlateReading::count());

        $this->assertDatabaseHas('security_logs', [
            'event' => SecurityLogger::PERMISSION_DENIED,
            'identifier' => 'device.gate',
        ]);
    }

    #[Test]
    public function test_a_camera_reading_is_stored_and_normalised(): void
    {
        $this->enable();

        $this->postJson(route('api.gate.anpr'), [
            'plate' => '۱۲ ب ۳۴۵ ایران ۱۱',
            'confidence' => 93,
            'lane' => 'ورودی شمالی',
            'device' => 'cam-north',
        ], [VerifyDeviceToken::GATE_HEADER => 'device-token-for-tests'])
            ->assertCreated()
            ->assertJson(['recognised' => true]);

        $reading = PlateReading::firstOrFail();

        $this->assertSame(PlateReading::SOURCE_ANPR, $reading->source);
        $this->assertSame('12-ب-345-11', $reading->plate_key);
        // رشته‌ی خامِ دستگاه دست‌نخورده می‌ماند
        $this->assertSame('۱۲ ب ۳۴۵ ایران ۱۱', $reading->raw_plate);
        $this->assertSame(93, $reading->confidence);
        $this->assertSame('cam-north', $reading->device_name);
    }

    #[Test]
    public function test_a_reading_the_server_cannot_parse_is_still_kept(): void
    {
        $this->enable();

        $this->postJson(route('api.gate.anpr'), [
            'plate' => '???',
            'confidence' => 20,
        ], [VerifyDeviceToken::GATE_HEADER => 'device-token-for-tests'])
            ->assertCreated()
            ->assertJson(['recognised' => false]);

        $reading = PlateReading::firstOrFail();

        // «دوربین چیزی دید که ما نفهمیدیم» خودش یک واقعیت است و پاک نمی‌شود
        $this->assertNull($reading->plate_key);
        $this->assertSame('???', $reading->raw_plate);
    }

    #[Test]
    public function test_an_image_arrives_either_as_base64_or_as_a_file(): void
    {
        $this->enable();

        $jpeg = "\xFF\xD8\xFF".str_repeat('x', 64);

        $this->postJson(route('api.gate.anpr'), [
            'plate' => '12ب34511',
            'image' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
        ], [VerifyDeviceToken::GATE_HEADER => 'device-token-for-tests'])->assertCreated();

        $fromJson = PlateReading::firstOrFail();
        $this->assertNotNull($fromJson->image_path);
        Storage::disk(PlateCapture::DISK)->assertExists($fromJson->image_path);

        $this->post(route('api.gate.anpr'), [
            'plate' => '12ب34511',
            'image_file' => UploadedFile::fake()->image('plate.jpg'),
        ], [VerifyDeviceToken::GATE_HEADER => 'device-token-for-tests'])->assertCreated();

        $fromUpload = PlateReading::latest('id')->firstOrFail();
        $this->assertNotNull($fromUpload->image_path);
        Storage::disk(PlateCapture::DISK)->assertExists($fromUpload->image_path);
    }

    #[Test]
    public function test_something_that_is_not_an_image_is_not_written_to_disk(): void
    {
        $this->enable();

        $this->postJson(route('api.gate.anpr'), [
            'plate' => '12ب34511',
            'image' => base64_encode('<?php echo "not an image";'),
        ], [VerifyDeviceToken::GATE_HEADER => 'device-token-for-tests'])->assertCreated();

        // خواندن ثبت می‌شود ولی فایل نه — دوربینی که PHP می‌فرستد دوربین نیست
        $this->assertNull(PlateReading::firstOrFail()->image_path);
    }

    #[Test]
    public function test_a_device_clock_that_is_wrong_does_not_lose_the_reading(): void
    {
        $this->enable();

        $this->postJson(route('api.gate.anpr'), [
            'plate' => '12ب34511',
            'captured_at' => 'دیروز ساعت پنج',
        ], [VerifyDeviceToken::GATE_HEADER => 'device-token-for-tests'])->assertCreated();

        $reading = PlateReading::firstOrFail();

        $this->assertNotNull($reading->captured_at);
        $this->assertTrue($reading->isFresh());
    }
}
