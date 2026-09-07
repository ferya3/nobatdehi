<?php

use App\Http\Controllers\Api\AnprController;
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
    ->middleware(['gate.device', 'throttle:gate-anpr'])
    ->name('api.gate.anpr');
