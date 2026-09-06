<?php

declare(strict_types=1);

namespace App\Domain\Auth\Exceptions;

use RuntimeException;

class OtpException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function tooManyRequests(int $seconds): self
    {
        return new self(
            'too_many_requests',
            'تعداد درخواست‌ها زیاد بود. لطفاً کمی بعد دوباره تلاش کنید.',
            $seconds,
        );
    }

    public static function resendTooSoon(int $seconds): self
    {
        return new self('resend_too_soon', "تا {$seconds} ثانیه‌ی دیگر امکان ارسال مجدد نیست.", $seconds);
    }

    public static function invalidMobile(): self
    {
        return new self('invalid_mobile', 'شماره موبایل معتبر نیست.');
    }

    public static function notFound(): self
    {
        return new self('not_found', 'کدی برای این شماره صادر نشده است. دوباره درخواست کد بدهید.');
    }

    public static function expired(): self
    {
        return new self('expired', 'کد منقضی شده است. لطفاً کد جدید بگیرید.');
    }

    public static function wrongCode(int $remaining): self
    {
        return new self('wrong_code', "کد وارد‌شده درست نیست. {$remaining} تلاش باقی مانده است.");
    }

    public static function tooManyAttempts(): self
    {
        return new self('too_many_attempts', 'تعداد تلاش‌های ناموفق زیاد بود. لطفاً کد جدید بگیرید.');
    }

    public static function driverBlocked(?string $reason): self
    {
        return new self('driver_blocked', $reason ?: 'دسترسی این شماره مسدود شده است.');
    }
}
