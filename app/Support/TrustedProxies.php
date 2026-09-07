<?php

declare(strict_types=1);

namespace App\Support;

/**
 * فهرست پروکسی‌های مورد اعتماد.
 *
 * چرا کلاس جدا: مقدار از .env می‌آید و باید هم رشته‌ی خالی را بفهمد، هم
 * چند IP جداشده با کاما را، هم '*' را برای کسی که آگاهانه انتخابش می‌کند.
 * گذاشتن این منطق داخل bootstrap یعنی هیچ‌وقت تست نمی‌شود.
 */
final class TrustedProxies
{
    /**
     * Nginx روی همان ماشین — همان چیزی که نصب‌کننده می‌سازد.
     *
     * @var array<int, string>
     */
    public const LOCAL = ['127.0.0.1', '::1'];

    /**
     * @return array<int, string>|string
     */
    public static function from(?string $raw): array|string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return self::LOCAL;
        }

        // '*' فقط وقتی که صریحاً نوشته شده باشد؛ پیش‌فرض نیست
        if ($value === '*') {
            return '*';
        }

        $proxies = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $proxy) => $proxy !== '',
        ));

        return $proxies === [] ? self::LOCAL : $proxies;
    }
}
