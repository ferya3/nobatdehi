<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Devices\DeviceTokens;
use App\Domain\Gate\GateDevices;
use App\Domain\Gate\PlateVerdict;
use App\Domain\Gate\PlateVerifier;
use App\Models\Appointment;
use App\Models\PlateReading;
use App\Models\Setting;
use App\Models\Truck;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ترتیب مراجع در تطبیق پلاک.
 *
 * این کلاس تصمیم می‌گیرد راهبند باز شود یا نه، پس بدون دیتابیس و بدون
 * HTTP هم باید بشود ثابت کرد که ترتیبش را رعایت می‌کند.
 */
final class PlateVerifierTest extends TestCase
{
    /**
     * تنظیمات را در کش می‌نشانیم تا این تست به دیتابیس نخورد.
     *
     * Setting::values() اول سراغ کش می‌رود؛ وقتی کلید از قبل آنجا باشد،
     * هیچ کوئری‌ای اجرا نمی‌شود.
     */
    private function verifier(int $minConfidence = 70): PlateVerifier
    {
        Cache::put(
            'settings.all',
            array_merge(Setting::DEFAULTS, ['gate_anpr_min_confidence' => (string) $minConfidence]),
            60,
        );

        return new PlateVerifier(new GateDevices(new DeviceTokens()));
    }

    private function appointment(string $plateKey = '12-ب-345-11'): Appointment
    {
        $truck = new Truck(['plate_key' => $plateKey]);

        $appointment = new Appointment(['factory_id' => 1]);
        $appointment->setRelation('truck', $truck);

        return $appointment;
    }

    private function reading(?string $plateKey, array $overrides = []): PlateReading
    {
        return new PlateReading(array_merge([
            'factory_id' => 1,
            'source' => PlateReading::SOURCE_ANPR,
            'plate_key' => $plateKey,
            'confidence' => 95,
            'captured_at' => now(),
        ], $overrides));
    }

    #[Test]
    public function test_a_matching_camera_reading_passes_without_the_guard(): void
    {
        $verdict = $this->verifier()->verify(
            $this->appointment(),
            $this->reading('12-ب-345-11'),
            null,
            guardConfirmed: false,
        );

        $this->assertTrue($verdict->passed);
        $this->assertSame(PlateVerdict::BY_ANPR, $verdict->source);
    }

    #[Test]
    public function test_a_mismatching_camera_reading_beats_a_confirming_guard(): void
    {
        $verdict = $this->verifier()->verify(
            $this->appointment(),
            $this->reading('99-ب-888-22'),
            null,
            guardConfirmed: true,
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame('99-ب-888-22', $verdict->observed);
    }

    #[Test]
    public function test_an_unreadable_camera_falls_through_to_the_guard(): void
    {
        $verifier = $this->verifier();
        $appointment = $this->appointment();
        $blind = $this->reading(null, ['confidence' => null]);

        $this->assertTrue($verifier->verify($appointment, $blind, null, true)->passed);
        $this->assertFalse($verifier->verify($appointment, $blind, null, false)->passed);
    }

    #[Test]
    public function test_a_low_confidence_reading_falls_through_to_the_guard(): void
    {
        $verifier = $this->verifier(minConfidence: 80);
        $appointment = $this->appointment();

        // پلاکِ غلط ولی با اطمینان پایین: «مطمئن نیستم»، نه «غلط است»
        $unsure = $this->reading('99-ب-888-22', ['confidence' => 40]);

        $verdict = $verifier->verify($appointment, $unsure, null, guardConfirmed: true);

        $this->assertTrue($verdict->passed);
        $this->assertSame(PlateVerdict::BY_GUARD, $verdict->source);
    }

    #[Test]
    public function test_a_low_confidence_reading_from_the_station_camera_still_decides(): void
    {
        // آستانه‌ی اطمینان فقط برای دوربین شبکه‌ای است؛ عکسِ ایستگاه را
        // خودِ نگهبان همان لحظه گرفته و «اطمینان» برایش معنا ندارد.
        $verdict = $this->verifier(minConfidence: 80)->verify(
            $this->appointment(),
            $this->reading('99-ب-888-22', [
                'source' => PlateReading::SOURCE_STATION,
                'confidence' => null,
            ]),
            null,
            guardConfirmed: true,
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame(PlateVerdict::BY_STATION, $verdict->source);
    }

    #[Test]
    public function test_a_stale_reading_fails_outright_rather_than_falling_through(): void
    {
        // اگر می‌افتاد روی نگهبان، خواندنِ کهنه بی‌اثر می‌شد و کسی نمی‌فهمید
        $verdict = $this->verifier()->verify(
            $this->appointment(),
            $this->reading('12-ب-345-11', [
                'captured_at' => now()->subSeconds(PlateReading::FRESH_SECONDS + 1),
            ]),
            null,
            guardConfirmed: true,
        );

        $this->assertFalse($verdict->passed);
    }

    #[Test]
    public function test_a_device_string_is_normalised_before_comparing(): void
    {
        $verifier = $this->verifier();
        $appointment = $this->appointment();

        foreach (['۱۲ب۳۴۵ایران۱۱', '12 ب 345 - 11', '12ب34511'] as $written) {
            $this->assertTrue(
                $verifier->verify($appointment, null, $written, guardConfirmed: false)->passed,
                "شکل «{$written}» باید همان پلاک شناخته شود",
            );
        }
    }

    #[Test]
    public function test_a_string_that_is_not_a_plate_is_a_mismatch(): void
    {
        $verdict = $this->verifier()->verify(
            $this->appointment(),
            null,
            'AB-1234',
            guardConfirmed: true,
        );

        $this->assertFalse($verdict->passed);
        $this->assertSame(PlateVerdict::BY_DEVICE, $verdict->source);
    }

    #[Test]
    public function test_with_no_device_at_all_the_guard_decides(): void
    {
        $verifier = $this->verifier();
        $appointment = $this->appointment();

        $this->assertTrue($verifier->verify($appointment, null, null, true)->passed);
        $this->assertFalse($verifier->verify($appointment, null, null, false)->passed);
    }
}
