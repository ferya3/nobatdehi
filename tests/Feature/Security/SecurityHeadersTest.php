<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_every_response_carries_the_hardening_headers(): void
    {
        $response = $this->get(route('driver.login'));

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        // اسکریپت درون‌خطی مجاز نیست — اجازه‌اش یعنی CSP تقریباً بی‌اثر
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
    }

    #[Test]
    public function test_the_websocket_is_allowed_or_the_live_queue_dies(): void
    {
        $csp = $this->get(route('driver.login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('connect-src', $csp);
        $this->assertStringContainsString('wss:', $csp);
    }

    #[Test]
    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get(route('driver.login'))->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost'.route('driver.login', absolute: false))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
