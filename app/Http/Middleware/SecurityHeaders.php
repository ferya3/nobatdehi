<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * هدرهای امنیتی روی هر پاسخ.
 *
 * عمداً در PHP و نه در Nginx: پیکربندی Nginx با هر بار نصب دوباره نوشته
 * می‌شود، پشت Cloudflare یا در محیط توسعه اصلاً وجود ندارد، و کسی که فایل
 * را دستی عوض کند خبر ندارد چه چیزی را برداشته. اینجا با کد می‌آید و با
 * تست قفل می‌شود.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /*
         * nonce قبل از رندر شدن view ساخته می‌شود.
         *
         * دو اسکریپت درون‌خطی داریم که نمی‌شود حذفشان کرد: مسیرهای Ziggy و
         * تگ‌های Vite. بدون nonce یا باید 'unsafe-inline' بدهیم — که CSP را
         * تقریباً بی‌اثر می‌کند — یا صفحه اصلاً بالا نمی‌آید.
         */
        Vite::useCspNonce();

        $response = $next($request);

        foreach ($this->headers($request) as $name => $value) {
            // Nginx یا هر لایه‌ی جلوتر اگر خودش گذاشته، دوباره نمی‌نویسیم
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',

            // این سامانه نه پرداخت دارد نه موقعیت مکانی؛ فقط دوربین لازم است
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(), payment=(), usb=()',

            'Content-Security-Policy' => $this->csp(),
        ];

        // HSTS فقط روی https معنی دارد. فرستادنش روی http هیچ اثری ندارد
        // جز اینکه نصب‌های بدون گواهی را گیج کند.
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        return $headers;
    }

    /**
     * تمام دارایی‌ها از همین دامنه سرو می‌شوند (Vite build، فونت محلی).
     *
     * 'unsafe-inline' برای style لازم است چون Vue استایل‌ها را درون‌خطی
     * تزریق می‌کند؛ برای script لازم نیست و عمداً نیامده. connect-src باید
     * WebSocket را هم بپذیرد وگرنه صف زنده کار نمی‌کند.
     */
    private function csp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-".Vite::cspNonce()."'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self' ws: wss:",
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
