<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * چیزهایی که برنامه‌ی اندروید باید بداند تا اتصال دائمش را برقرار کند.
 *
 * هیچ‌کدام در APK پخته نشده‌اند و دلیلش همان درسی است که سمت وب گرفتیم:
 * آدرسی که در زمان build داخل فایل می‌نشیند، روزی که دامنه عوض شود بی‌صدا
 * می‌شکند. آنجا لااقل یک build دوباره کافی بود؛ اینجا باید APK تازه به
 * دستِ تک‌تکِ راننده‌ها برسد. پس سرور می‌گوید، برنامه می‌پرسد.
 */
class AppConfigController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $driver = $request->user('driver');

        // «برنامه هست و حرف می‌زند» — تنها راهِ فهمیدنِ اینکه اعلانِ نرسیده
        // تقصیرِ سرور است یا گوشی
        $driver->forceFill(['app_last_seen_at' => now()])->saveQuietly();

        return response()->json([
            'driver' => ['id' => $driver->id],
            'csrf' => $request->session()->token(),

            /*
             * اتصال همیشه برقرار است، چه راننده نوبت داشته باشد چه نه.
             *
             * اول این را به داشتنِ نوبتِ فعال گره زده بودم تا باتری نسوزد.
             * غلط بود: اطلاعیه‌ی کارخانه («فردا تعطیل است») دقیقاً به دستِ
             * کسی باید برسد که هنوز نوبت نگرفته — همان کسی که آن دروازه
             * کنارش می‌گذاشت.
             */
            'realtime' => true,

            'reverb' => [
                'key' => (string) config('broadcasting.connections.reverb.key'),
                'host' => $this->host($request),
                'port' => $this->port($request),
                'tls' => $request->isSecure(),
            ],
        ]);
    }

    /**
     * میزبان از روی همان درخواستی که رسیده.
     *
     * Reverb همیشه از پشت همان Nginx و روی همان دامنه سرو می‌شود. تنظیمات
     * فقط وقتی حرف آخر را می‌زند که عمداً روی میزبان دیگری گذاشته شده باشد.
     */
    private function host(Request $request): string
    {
        $configured = (string) config('broadcasting.connections.reverb.options.host');

        return in_array($configured, ['', 'localhost', '127.0.0.1', '0.0.0.0'], true)
            ? $request->getHost()
            : $configured;
    }

    private function port(Request $request): int
    {
        $configured = (string) config('broadcasting.connections.reverb.options.host');

        return in_array($configured, ['', 'localhost', '127.0.0.1', '0.0.0.0'], true)
            ? (int) $request->getPort()
            : (int) config('broadcasting.connections.reverb.options.port', 443);
    }
}
