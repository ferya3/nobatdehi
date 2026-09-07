<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

use App\Domain\Devices\DeviceTokens;
use App\Models\Setting;

/**
 * تنظیمات پلِ نشان‌دهنده‌ی باسکول.
 *
 * مثل تنظیمات پیامک و دوربین در دیتابیس است و نه در .env: عوض‌کردن توکن یا
 * خاموش‌کردن پل نباید نیاز به SSH و ری‌استارت سرویس داشته باشد.
 */
final class ScaleDevices
{
    public function __construct(private readonly DeviceTokens $tokens) {}

    public function enabled(): bool
    {
        return Setting::get('scale_device_enabled', '0') === '1';
    }

    /**
     * آیا وزنِ ناپایدار قابل ثبت است؟
     *
     * پیش‌فرض نه. ثبتِ وزن وقتی عقربه هنوز نوسان دارد یکی از منابع واقعی
     * خطاست و برخلاف اشتباه تایپی، هیچ نشانه‌ای از خودش باقی نمی‌گذارد.
     *
     * قابل خاموش‌کردن است چون همه‌ی نشان‌دهنده‌ها این پرچم را نمی‌فرستند؛
     * روی دستگاهی که نمی‌فرستد، اجبارِ پایداری یعنی هیچ وزنی ثبت نمی‌شود.
     */
    public function requireStable(): bool
    {
        return Setting::get('scale_require_stable', '1') === '1';
    }

    public function retentionDays(): int
    {
        return max(1, (int) Setting::get('scale_reading_retention_days', '30'));
    }

    public function token(): string
    {
        return $this->tokens->token(DeviceTokens::SCALE);
    }

    public function hasToken(): bool
    {
        return $this->tokens->hasToken(DeviceTokens::SCALE);
    }

    public function rotateToken(): string
    {
        return $this->tokens->rotate(DeviceTokens::SCALE);
    }
}
