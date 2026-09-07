<?php

use App\Http\Controllers\Api\AnprController;
use App\Http\Controllers\Api\ScaleController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

/*
|--------------------------------------------------------------------------
| دستگاه‌های گیت
|--------------------------------------------------------------------------
|
| دوربین پلاک‌خوان با توکن دستگاه احراز می‌شود و نه با session — چون کاربر
| نیست. مسیر تا وقتی در تنظیمات فعال نشده باشد اصلاً وجود ندارد (۴۰۴)، و
| هر تلاش ناموفق در لاگ امنیتی می‌نشیند.
|
*/

Route::post('/gate/anpr', [AnprController::class, 'store'])
    ->middleware(['device:gate', 'throttle:gate-anpr'])
    ->name('api.gate.anpr');

/*
| پلِ نشان‌دهنده‌ی باسکول
|
| روی کامپیوترِ باسکول اجرا می‌شود، پورت COM را می‌خواند و هر عدد تازه را
| اینجا می‌فرستد. نرخ ارسالش از دوربین بیشتر است چون وزن مدام عوض می‌شود.
*/

Route::post('/weighbridge/reading', [ScaleController::class, 'store'])
    ->middleware(['device:scale', 'throttle:scale-reading'])
    ->name('api.weighbridge.reading');
