<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use App\Models\Setting;

/**
 * شماره‌های مدیرانی که پیامک اطلاع‌رسانی می‌گیرند.
 *
 * از تنظیمات پنل خوانده می‌شوند تا عوض‌کردنشان به ویرایش .env و ری‌استارت
 * سرویس نیاز نداشته باشد. یک جا نگه داشته می‌شود چون بیش از یک Listener
 * لازمش دارد و دو نسخه‌ی جدا، روزی با هم فرق پیدا می‌کنند.
 */
final class ManagerRecipients
{
    /** @return array<int, string> */
    public static function all(): array
    {
        $raw = Setting::get('sms_manager_recipients');

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }
}
