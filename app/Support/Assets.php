<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * نشانی دارایی‌هایی که باید زودتر از کشف‌شدن، دانلود شروع شوند.
 */
final class Assets
{
    /** کلیدِ فونت در manifest — همان مسیری که Vite از node_modules می‌شناسد */
    private const FONT = 'node_modules/vazirmatn/fonts/webfonts/Vazirmatn[wght].woff2';

    /**
     * فونت وزیرمتن، برای preload.
     *
     * بدون preload مرورگر سه رفت‌وبرگشت لازم دارد تا به فونت برسد: HTML،
     * بعد CSS، و تازه وقتی CSS را parse کرد می‌فهمد فونتی هم هست. روی
     * اتصالی که هر رفت‌وبرگشتش صدها میلی‌ثانیه است، متن مدتی با فونت
     * جایگزین دیده می‌شود و بعد می‌پرد.
     *
     * نشانی عمداً نسبی می‌شود: Vite آن را از روی APP_URL مطلق می‌سازد و اگر
     * کسی پنل را با IP باز کند، preload به دامنه‌ی دیگری اشاره می‌کرد —
     * مرورگر دورش می‌ریزد و CSP هم (font-src 'self') می‌بنددش.
     *
     * null یعنی هنوز build نشده؛ صفحه باید بدون preload هم بالا بیاید.
     */
    public static function fontPreloadUrl(): ?string
    {
        try {
            $url = Vite::asset(self::FONT);
        } catch (Throwable) {
            return null;
        }

        return '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
    }
}
