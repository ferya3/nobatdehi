<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Devices\DeviceTokens;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * توکن دستگاه فقط در هدر — هرگز در URL.
 */
final class DeviceTokenTransportTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private const TOKEN = 'test-anpr-token-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();

        Setting::putMany([
            'gate_anpr_enabled' => '1',
            'gate_anpr_token' => self::TOKEN,
        ]);
    }

    #[Test]
    public function test_a_token_in_the_query_string_does_not_authenticate(): void
    {
        // در لاگ دسترسی Nginx، در history مرورگر و در هدر Referer می‌ماند
        $this->postJson('/api/gate/anpr?token='.self::TOKEN, ['plate' => '12ب34511'])
            ->assertNotFound();
    }

    #[Test]
    public function test_the_header_still_works(): void
    {
        $this->postJson('/api/gate/anpr', ['plate' => '12ب34511'], [
            'X-Device-Token' => self::TOKEN,
        ])->assertSuccessful();
    }

    #[Test]
    public function test_a_bearer_token_still_works(): void
    {
        $this->postJson('/api/gate/anpr', ['plate' => '12ب34511'], [
            'Authorization' => 'Bearer '.self::TOKEN,
        ])->assertSuccessful();
    }

    #[Test]
    public function test_a_disabled_device_and_a_wrong_token_look_identical(): void
    {
        $wrongToken = $this->postJson('/api/gate/anpr', ['plate' => '12ب34511'], [
            'X-Device-Token' => 'not-the-token',
        ]);

        Setting::putMany(['gate_anpr_enabled' => '0']);

        $disabled = $this->postJson('/api/gate/anpr', ['plate' => '12ب34511'], [
            'X-Device-Token' => self::TOKEN,
        ]);

        // تفاوت این دو به کسی که از بیرون امتحان می‌کند می‌گوید کدام
        // دستگاه روشن است؛ دلیل واقعی فقط در لاگ امنیتی می‌ماند
        $this->assertSame($wrongToken->status(), $disabled->status());
        $this->assertSame($wrongToken->json(), $disabled->json());

        $this->assertDatabaseHas('security_logs', ['identifier' => 'device.'.DeviceTokens::GATE]);
    }
}
