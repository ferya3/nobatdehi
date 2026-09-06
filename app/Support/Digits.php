<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ارقام فارسی/عربی را به لاتین تبدیل می‌کند.
 * راننده روی موبایلش با کیبورد فارسی تایپ می‌کند؛ سرور نباید به این حساس باشد.
 */
final class Digits
{
    private const PERSIAN = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    private const ARABIC = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

    private const LATIN = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    public static function toLatin(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace(
            [...self::PERSIAN, ...self::ARABIC],
            [...self::LATIN, ...self::LATIN],
            $value,
        );
    }

    public static function toPersian(string|int $value): string
    {
        return str_replace(self::LATIN, self::PERSIAN, (string) $value);
    }
}
