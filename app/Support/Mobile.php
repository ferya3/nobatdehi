<?php

declare(strict_types=1);

namespace App\Support;

/**
 * نرمال‌سازی شماره موبایل ایران به قالب یکتای 09xxxxxxxxx.
 *
 * ورودی‌های پذیرفته‌شده: 09123456789، ۰۹۱۲۳۴۵۶۷۸۹، 9123456789،
 * +989123456789، 00989123456789، 989123456789 و نسخه‌های دارای فاصله یا خط تیره.
 */
final class Mobile
{
    public static function normalize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', Digits::toLatin($value ?? '')) ?? '';

        $digits = match (true) {
            str_starts_with($digits, '0098') => substr($digits, 4),
            str_starts_with($digits, '098') => substr($digits, 3),
            str_starts_with($digits, '98') && strlen($digits) === 12 => substr($digits, 2),
            str_starts_with($digits, '0') => substr($digits, 1),
            default => $digits,
        };

        if (! preg_match('/^9\d{9}$/', $digits)) {
            return null;
        }

        return '0'.$digits;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /** برای نمایش: 0912 345 6789 */
    public static function pretty(string $mobile): string
    {
        return trim(substr($mobile, 0, 4).' '.substr($mobile, 4, 3).' '.substr($mobile, 7));
    }
}
