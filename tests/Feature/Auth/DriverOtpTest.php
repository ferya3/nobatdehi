<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Auth\Exceptions\OtpException;
use App\Domain\Auth\OtpService;
use App\Models\Driver;
use App\Models\OtpRequest;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class DriverOtpTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('otp:req:mobile:09123456789');
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        $this->otp = app(OtpService::class);
    }

    /** کد خام را از تراکنش تست بیرون می‌کشیم — در برنامه‌ی واقعی فقط پیامک می‌شود */
    private function issueCode(string $mobile = '09123456789'): string
    {
        config()->set('otp.expose_in_response', true);

        return (string) $this->otp->request($mobile)['code'];
    }

    #[Test]
    public function it_never_stores_the_raw_code(): void
    {
        $code = $this->issueCode();

        $stored = OtpRequest::firstOrFail();

        $this->assertNotSame($code, $stored->code_hash);
        $this->assertTrue(Hash::check($code, $stored->code_hash));
        $this->assertNotContains('code', array_keys($stored->toArray()));
    }

    #[Test]
    public function it_queues_the_code_as_an_sms_instead_of_sending_it_inline(): void
    {
        Queue::fake();

        $this->issueCode();

        Queue::assertPushed(\App\Jobs\SendSmsMessage::class);
        $this->assertSame('QUEUED', SmsMessage::firstOrFail()->status);
    }

    #[Test]
    public function a_valid_code_logs_the_driver_in_and_creates_them_on_first_use(): void
    {
        $code = $this->issueCode();

        $this->assertSame(0, Driver::count());

        $driver = $this->otp->verify('09123456789', $code);

        $this->assertSame('09123456789', $driver->mobile);
        $this->assertNotNull($driver->mobile_verified_at);
        $this->assertSame(1, Driver::count());
    }

    #[Test]
    public function a_code_can_only_be_used_once(): void
    {
        $code = $this->issueCode();
        $this->otp->verify('09123456789', $code);

        $this->expectException(OtpException::class);
        $this->otp->verify('09123456789', $code);
    }

    #[Test]
    public function an_expired_code_is_rejected(): void
    {
        $code = $this->issueCode();

        $this->travel((int) config('otp.ttl_minutes') + 1)->minutes();

        $this->expectExceptionMessageMatches('/منقضی/');
        $this->otp->verify('09123456789', $code);
    }

    #[Test]
    public function it_locks_out_after_the_configured_number_of_wrong_attempts(): void
    {
        $this->issueCode();
        $max = (int) config('otp.max_attempts');

        for ($i = 1; $i < $max; $i++) {
            try {
                $this->otp->verify('09123456789', '00000');
            } catch (OtpException $e) {
                $this->assertSame('wrong_code', $e->reason);
            }
        }

        try {
            $this->otp->verify('09123456789', '00000');
            $this->fail('باید بعد از حداکثر تلاش قفل می‌شد.');
        } catch (OtpException $e) {
            $this->assertSame('too_many_attempts', $e->reason);
        }

        $this->assertNotNull(OtpRequest::firstOrFail()->invalidated_at);
    }

    #[Test]
    public function requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->issueCode();

        $this->travel((int) config('otp.resend_seconds') + 1)->seconds();
        $second = $this->issueCode();

        $this->assertNotSame($first, $second);

        try {
            $this->otp->verify('09123456789', $first);
            $this->fail('کد قبلی نباید کار کند.');
        } catch (OtpException) {
            // انتظار همین است
        }

        $driver = $this->otp->verify('09123456789', $second);
        $this->assertSame('09123456789', $driver->mobile);
    }

    #[Test]
    public function it_refuses_to_resend_before_the_cooldown(): void
    {
        $this->issueCode();

        try {
            $this->otp->request('09123456789');
            $this->fail('ارسال مجدد زودهنگام باید رد شود.');
        } catch (OtpException $e) {
            $this->assertSame('resend_too_soon', $e->reason);
            $this->assertGreaterThan(0, $e->retryAfter);
        }
    }

    #[Test]
    public function a_blocked_driver_cannot_request_a_code(): void
    {
        Driver::create(['mobile' => '09123456789', 'is_blocked' => true, 'blocked_reason' => 'تخلف مکرر']);

        $this->expectExceptionMessage('تخلف مکرر');
        $this->otp->request('09123456789');
    }

    #[Test]
    public function it_rejects_an_invalid_mobile_number(): void
    {
        $this->expectExceptionMessageMatches('/معتبر نیست/');
        $this->otp->request('0211234567');
    }

    #[Test]
    public function it_writes_a_security_log_for_every_attempt(): void
    {
        $code = $this->issueCode();

        try {
            $this->otp->verify('09123456789', '11111');
        } catch (OtpException) {
        }

        $this->otp->verify('09123456789', $code);

        $this->assertDatabaseHas('security_logs', ['event' => 'otp_requested']);
        $this->assertDatabaseHas('security_logs', ['event' => 'otp_failed']);
        $this->assertDatabaseHas('security_logs', ['event' => 'otp_verified']);
    }
}
