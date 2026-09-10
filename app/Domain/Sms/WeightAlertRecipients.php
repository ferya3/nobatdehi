<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use App\Models\Setting;

/**
 * شماره‌هایی که اخطارِ مغایرت وزن را می‌گیرند.
 *
 * جدا از فهرست مدیران است چون مخاطبش فرق دارد: خبرِ «نوبت جدید» کارِ
 * مسئول شیفت است و «بار با حواله نمی‌خواند» کارِ کسی که درباره‌اش تصمیم
 * می‌گیرد. ولی خالی که باشد به همان فهرست مدیران برمی‌گردد — وگرنه یک
 * فیلدِ پرنشده یعنی اخطاری که هیچ‌وقت به دست کسی نمی‌رسد.
 */
final class WeightAlertRecipients
{
    /** @return array<int, string> */
    public static function all(): array
    {
        $own = self::split(Setting::get('sms_weight_alert_recipients'));

        return $own !== [] ? $own : ManagerRecipients::all();
    }

    /** @return array<int, string> */
    private static function split(string $raw): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)))));
    }
}
