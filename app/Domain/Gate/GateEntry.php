<?php

declare(strict_types=1);

namespace App\Domain\Gate;

/**
 * برچسب‌های «این کامیون چطور وارد شد».
 *
 * سه سؤال جدا که در سابقه هرکدام معنی خودشان را دارند:
 *
 *   با چه چیزی حواله تأیید شد؟   QR یا استثنای دستی
 *   کد را چه دستگاهی خواند؟      دوربین مرورگر یا بارکدخوان
 *   پلاک را چه کسی تأیید کرد؟    دوربین پلاک‌خوان، دوربین ایستگاه، یا نگهبان
 *
 * قبلاً فقط سؤال اول جواب داشت. بدون دوتای بعدی، «با QR وارد شد» در ممیزی
 * چیز زیادی نمی‌گوید.
 */
final class GateEntry
{
    public const QR = 'qr';

    public const MANUAL = 'manual';

    /** @return array<string, string> */
    public static function methods(): array
    {
        return [
            self::QR => 'اسکن کد QR',
            self::MANUAL => 'ثبت دستی (استثنا)',
        ];
    }

    /** @return array<string, string> */
    public static function scanSources(): array
    {
        return [
            ScanTicket::SOURCE_CAMERA => 'دوربین مرورگر',
            ScanTicket::SOURCE_BARCODE => 'بارکدخوان',
        ];
    }

    /** @return array<string, string> */
    public static function plateSources(): array
    {
        return [
            PlateVerdict::BY_ANPR => 'دوربین پلاک‌خوان',
            PlateVerdict::BY_STATION => 'دوربین ایستگاه نگهبانی',
            PlateVerdict::BY_DEVICE => 'دستگاه پلاک‌خوان',
            PlateVerdict::BY_GUARD => 'تأیید چشمی نگهبان',
        ];
    }

    public static function methodLabel(?string $value): ?string
    {
        return $value === null ? null : (self::methods()[$value] ?? $value);
    }

    public static function scanSourceLabel(?string $value): ?string
    {
        return $value === null ? null : (self::scanSources()[$value] ?? $value);
    }

    public static function plateSourceLabel(?string $value): ?string
    {
        return $value === null ? null : (self::plateSources()[$value] ?? $value);
    }
}
