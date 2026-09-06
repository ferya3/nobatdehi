<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Audit\SecurityLogger;
use App\Domain\Auth\Exceptions\OtpException;
use App\Domain\Sms\SmsService;
use App\Models\Driver;
use App\Models\OtpRequest;
use App\Support\Digits;
use App\Support\Mobile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ورود راننده با کد یک‌بارمصرف.
 *
 * کد هرگز خام ذخیره نمی‌شود، عمر محدود دارد، تعداد تلاش و ارسال محدود است و
 * محدودیت هم روی شماره و هم روی IP اعمال می‌شود. رمز عبور برای راننده تعریف
 * نمی‌کنیم — رمزی که وجود ندارد قابل لو رفتن هم نیست.
 */
final class OtpService
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly SecurityLogger $security,
    ) {}

    /**
     * صدور و ارسال کد.
     *
     * @return array{mobile: string, expires_in: int, resend_in: int, code: ?string}
     */
    public function request(?string $rawMobile, ?Request $request = null): array
    {
        $mobile = Mobile::normalize($rawMobile);

        if ($mobile === null) {
            throw OtpException::invalidMobile();
        }

        $driver = Driver::where('mobile', $mobile)->first();

        if ($driver?->is_blocked) {
            $this->security->log(SecurityLogger::PERMISSION_DENIED, $mobile, $driver, ['stage' => 'otp_request']);

            throw OtpException::driverBlocked($driver->blocked_reason);
        }

        $this->guardRequestRate($mobile, $request);
        $this->guardResendInterval($mobile);

        $code = $this->generateCode();
        $ttl = (int) config('otp.ttl_minutes');

        DB::transaction(function () use ($mobile, $code, $ttl, $request) {
            // کدهای قبلی همین شماره باطل می‌شوند: همیشه فقط یک کد زنده
            OtpRequest::where('mobile', $mobile)
                ->whereNull('verified_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            OtpRequest::create([
                'mobile' => $mobile,
                'code_hash' => Hash::make($code),
                'purpose' => 'login',
                'expires_at' => now()->addMinutes($ttl),
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            ]);
        });

        $this->sms->queueTemplate('otp.login', $mobile, [
            'code' => $code,
            'minutes' => $ttl,
        ]);

        $this->security->log(SecurityLogger::OTP_REQUESTED, $mobile, $driver, request: $request);

        return [
            'mobile' => $mobile,
            'expires_in' => $ttl * 60,
            'resend_in' => (int) config('otp.resend_seconds'),
            // فقط در محیط توسعه: بدون پنل پیامکی هم بشود جریان را تست کرد
            'code' => config('otp.expose_in_response') ? $code : null,
        ];
    }

    /**
     * تأیید کد و بازگرداندن راننده. اگر راننده وجود نداشته باشد ساخته می‌شود.
     */
    public function verify(?string $rawMobile, ?string $rawCode, ?Request $request = null): Driver
    {
        $mobile = Mobile::normalize($rawMobile);

        if ($mobile === null) {
            throw OtpException::invalidMobile();
        }

        $this->guardVerifyRate($request);

        $code = preg_replace('/\D+/', '', Digits::toLatin($rawCode ?? '')) ?? '';

        /** @var OtpRequest|null $otp */
        $otp = OtpRequest::where('mobile', $mobile)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($otp === null) {
            $this->security->log(SecurityLogger::OTP_FAILED, $mobile, context: ['reason' => 'not_found'], request: $request);

            throw OtpException::notFound();
        }

        if ($otp->expires_at->isPast()) {
            $otp->update(['invalidated_at' => now()]);
            $this->security->log(SecurityLogger::OTP_EXPIRED, $mobile, request: $request);

            throw OtpException::expired();
        }

        $maxAttempts = (int) config('otp.max_attempts');

        if ($otp->attempts >= $maxAttempts) {
            $otp->update(['invalidated_at' => now()]);
            $this->security->log(SecurityLogger::OTP_FAILED, $mobile, context: ['reason' => 'max_attempts'], request: $request);

            throw OtpException::tooManyAttempts();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            $remaining = max(0, $maxAttempts - $otp->attempts);

            $this->security->log(SecurityLogger::OTP_FAILED, $mobile, context: ['remaining' => $remaining], request: $request);

            if ($remaining === 0) {
                $otp->update(['invalidated_at' => now()]);

                throw OtpException::tooManyAttempts();
            }

            throw OtpException::wrongCode($remaining);
        }

        $driver = DB::transaction(function () use ($otp, $mobile) {
            $otp->update(['verified_at' => now()]);

            $driver = Driver::firstOrCreate(['mobile' => $mobile]);

            $driver->forceFill([
                'mobile_verified_at' => $driver->mobile_verified_at ?? now(),
                'last_login_at' => now(),
            ])->save();

            return $driver;
        });

        if ($driver->is_blocked) {
            throw OtpException::driverBlocked($driver->blocked_reason);
        }

        $this->security->log(SecurityLogger::OTP_VERIFIED, $mobile, $driver, request: $request);

        return $driver;
    }

    /** ثانیه‌های باقی‌مانده تا امکان ارسال مجدد */
    public function secondsUntilResend(string $mobile): int
    {
        $last = OtpRequest::where('mobile', $mobile)->latest('id')->first();

        if ($last === null) {
            return 0;
        }

        $elapsed = (int) $last->created_at->diffInSeconds(now());

        return max(0, (int) config('otp.resend_seconds') - $elapsed);
    }

    private function guardResendInterval(string $mobile): void
    {
        $remaining = $this->secondsUntilResend($mobile);

        if ($remaining > 0) {
            throw OtpException::resendTooSoon($remaining);
        }
    }

    private function guardRequestRate(string $mobile, ?Request $request): void
    {
        $perMobile = "otp:req:mobile:{$mobile}";

        if (RateLimiter::tooManyAttempts($perMobile, (int) config('otp.rate_limits.per_mobile_hourly'))) {
            $this->security->log(SecurityLogger::RATE_LIMIT, $mobile, context: ['scope' => 'mobile'], request: $request);

            throw OtpException::tooManyRequests(RateLimiter::availableIn($perMobile));
        }

        $ip = $request?->ip() ?? 'cli';
        $perIp = "otp:req:ip:{$ip}";

        if (RateLimiter::tooManyAttempts($perIp, (int) config('otp.rate_limits.per_ip_hourly'))) {
            $this->security->log(SecurityLogger::RATE_LIMIT, $mobile, context: ['scope' => 'ip'], request: $request);

            throw OtpException::tooManyRequests(RateLimiter::availableIn($perIp));
        }

        RateLimiter::hit($perMobile, 3600);
        RateLimiter::hit($perIp, 3600);
    }

    private function guardVerifyRate(?Request $request): void
    {
        $ip = $request?->ip() ?? 'cli';
        $key = "otp:verify:ip:{$ip}";

        if (RateLimiter::tooManyAttempts($key, (int) config('otp.rate_limits.verify_per_ip_hourly'))) {
            $this->security->log(SecurityLogger::RATE_LIMIT, null, context: ['scope' => 'verify_ip'], request: $request);

            throw OtpException::tooManyRequests(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 3600);
    }

    private function generateCode(): string
    {
        $length = max(4, (int) config('otp.length'));
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= random_int(0, 9);
        }

        return $code;
    }
}
