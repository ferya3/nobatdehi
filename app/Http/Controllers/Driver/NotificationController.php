<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\DriverNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * دو Endpoint که برنامه‌ی اندروید صدا می‌زند.
 *
 * اینجا Inertia نیست و JSON خام برمی‌گردد: صدازننده یک Worker در پس‌زمینه‌ی
 * برنامه است، نه یک صفحه. احراز هویتش همان کوکیِ نشستِ WebView است، پس
 * راننده‌ای که از برنامه خارج شده اعلان نمی‌گیرد و راننده‌ای هم نمی‌تواند
 * اعلان دیگری را ببیند.
 */
class NotificationController extends Controller
{
    /**
     * اعلان‌های نرسیده.
     *
     * توکن CSRF هم برمی‌گردد چون Worker پس‌زمینه به صفحه‌ای دسترسی ندارد که
     * از آن توکن بردارد، و بدون توکن نمی‌تواند رسید بزند. همان نشست است، پس
     * توکن هم همان توکن است.
     */
    public function index(Request $request): JsonResponse
    {
        $driver = $request->user('driver');

        $pending = DriverNotification::where('driver_id', $driver->id)
            ->whereNull('delivered_at')
            ->orderBy('id')
            // یک راننده‌ی غایب نباید بعد از یک هفته با ۵۰ اعلان روبه‌رو شود
            ->limit(20)
            ->get(['id', 'title', 'body', 'path', 'kind', 'created_at']);

        return response()->json([
            'csrf' => $request->session()->token(),
            'notifications' => $pending->map(fn (DriverNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'path' => $n->path,
                'kind' => $n->kind,
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * رسید: این‌ها روی گوشی نشان داده شدند.
     *
     * جدا از خواندن است تا اعلانی که به‌خاطر بسته‌شدن ناگهانی برنامه نمایش
     * داده نشد، دفعه‌ی بعد دوباره بیاید.
     */
    public function acknowledge(Request $request): JsonResponse
    {
        $driver = $request->user('driver');

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:50'],
            'ids.*' => ['integer'],
        ]);

        $marked = DriverNotification::where('driver_id', $driver->id)
            ->whereIn('id', $data['ids'])
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        return response()->json(['delivered' => $marked]);
    }
}
