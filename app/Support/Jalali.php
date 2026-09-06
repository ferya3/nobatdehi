<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Morilog\Jalali\Jalalian;

/**
 * تبدیل تاریخ میلادی به شمسی. تمام نمایش تاریخ در برنامه از اینجا می‌گذرد.
 */
final class Jalali
{
    public static function of(DateTimeInterface|string $date): Jalalian
    {
        return Jalalian::fromDateTime($date);
    }

    /** 1405/06/16 */
    public static function date(DateTimeInterface|string $date): string
    {
        return self::of($date)->format('Y/m/d');
    }

    /** شنبه ۱۶ شهریور */
    public static function dayLabel(DateTimeInterface|string $date): string
    {
        return self::of($date)->format('l j F');
    }

    /** ۱۶ شهریور ۱۴۰۵ */
    public static function long(DateTimeInterface|string $date): string
    {
        return self::of($date)->format('j F Y');
    }

    /** 1405/06/16 - 10:30 */
    public static function dateTime(DateTimeInterface|string $date): string
    {
        return self::of($date)->format('Y/m/d - H:i');
    }
}
