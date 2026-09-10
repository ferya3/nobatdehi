<?php

use App\Http\Controllers\Driver\AppointmentController;
use App\Http\Controllers\Driver\BookingController;
use App\Http\Controllers\Driver\OtpController;
use App\Http\Controllers\Staff\Catalog\ProductController;
use App\Http\Controllers\Staff\Catalog\TruckTypeController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\DeviceSettingsController;
use App\Http\Controllers\Staff\GateController;
use App\Http\Controllers\Staff\HomeController;
use App\Http\Controllers\Staff\LoadingController;
use App\Http\Controllers\Staff\LoginController;
use App\Http\Controllers\Staff\PasswordController;
use App\Http\Controllers\Staff\QueueController;
use App\Http\Controllers\Staff\ReportController;
use App\Http\Controllers\Staff\SettingsController;
use App\Http\Controllers\Staff\SmsSettingsController;
use App\Http\Controllers\Staff\WeighbridgeController;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| پنل راننده
|--------------------------------------------------------------------------
*/

Route::prefix('queue')->name('driver.')->group(function () {
    Route::middleware('guest:driver')->group(function () {
        Route::get('/login', [OtpController::class, 'showLogin'])->name('login');

        Route::middleware('throttle:otp-request')->group(function () {
            Route::post('/otp', [OtpController::class, 'request'])->name('otp.request');
            Route::post('/otp/resend', [OtpController::class, 'resend'])->name('otp.resend');
        });

        Route::get('/otp', [OtpController::class, 'showVerify'])->name('otp.verify.show');

        Route::post('/otp/verify', [OtpController::class, 'verify'])
            ->middleware('throttle:otp-verify')
            ->name('otp.verify');
    });

    Route::middleware('auth:driver')->group(function () {
        Route::get('/', [AppointmentController::class, 'home'])->name('home');

        Route::get('/book', [BookingController::class, 'create'])->name('booking.create');
        Route::post('/book', [BookingController::class, 'store'])
            ->middleware('throttle:booking')
            ->name('booking.store');

        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show'])
            ->name('appointments.show');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->name('appointments.cancel');

        Route::post('/logout', [OtpController::class, 'logout'])->name('logout');
    });
});

/*
|--------------------------------------------------------------------------
| پنل کارکنان کارخانه
|--------------------------------------------------------------------------
*/

Route::prefix('panel')->name('staff.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])
            ->middleware('throttle:staff-login')
            ->name('login.store');
    });

    Route::middleware(['auth:web', RequirePasswordChange::class])->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        // تا رمز پیش‌فرض عوض نشود، RequirePasswordChange بقیه را می‌بندد
        Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // نگهبانی / باسکول
        Route::get('/gate', [GateController::class, 'index'])->name('gate.index');
        Route::post('/gate/scan', [GateController::class, 'scan'])->name('gate.scan');
        Route::post('/gate/lookup', [GateController::class, 'lookup'])->name('gate.lookup');
        Route::post('/gate/{appointment}/check-in', [GateController::class, 'checkIn'])->name('gate.check-in');

        // دستگاه‌های گیت: عکس پلاک از ایستگاه، و خواندن‌های دوربین پلاک‌خوان
        Route::post('/gate/capture', [GateController::class, 'capture'])
            ->middleware('throttle:gate-capture')
            ->name('gate.capture');
        Route::get('/gate/readings', [GateController::class, 'readings'])->name('gate.readings');
        Route::get('/gate/readings/{reading}/image', [GateController::class, 'readingImage'])
            ->name('gate.reading-image');

        // لاین بارگیری
        Route::get('/loading', [LoadingController::class, 'index'])->name('loading.index');
        Route::post('/loading/scan', [LoadingController::class, 'scan'])->name('loading.scan');
        Route::post('/loading/lookup', [LoadingController::class, 'lookup'])->name('loading.lookup');
        Route::post('/loading/{appointment}/transition', [LoadingController::class, 'transition'])
            ->name('loading.transition');

        // باسکول اول و دوم
        Route::get('/weighbridge', [WeighbridgeController::class, 'index'])->name('weighbridge.index');
        Route::post('/weighbridge/scan', [WeighbridgeController::class, 'scan'])->name('weighbridge.scan');
        // پشتیبانِ اسکن: وقتی بارکدخوان یا QR از کار افتاده باشد
        Route::post('/weighbridge/lookup', [WeighbridgeController::class, 'lookup'])->name('weighbridge.lookup');
        Route::post('/weighbridge/{appointment}/record', [WeighbridgeController::class, 'record'])
            ->name('weighbridge.record');
        Route::get('/weighbridge/readings', [WeighbridgeController::class, 'readings'])
            ->name('weighbridge.readings');

        Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
        Route::get('/queue/{appointment}', [QueueController::class, 'show'])->name('queue.show');
        Route::post('/queue/{appointment}/transition', [QueueController::class, 'transition'])
            ->name('queue.transition');
        Route::post('/queue/{appointment}/priority', [QueueController::class, 'prioritize'])
            ->name('queue.priority');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');

        // کاتالوگ: محصولات و انواع کامیون
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');

        Route::get('/truck-types', [TruckTypeController::class, 'index'])->name('truck-types.index');
        Route::post('/truck-types', [TruckTypeController::class, 'store'])->name('truck-types.store');
        Route::put('/truck-types/{truckType}', [TruckTypeController::class, 'update'])->name('truck-types.update');
        Route::delete('/truck-types/{truckType}', [TruckTypeController::class, 'destroy'])->name('truck-types.destroy');

        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('/settings/devices', [DeviceSettingsController::class, 'edit'])->name('settings.devices');
        Route::put('/settings/devices', [DeviceSettingsController::class, 'update'])->name('settings.devices.update');
        Route::post('/settings/devices/token', [DeviceSettingsController::class, 'rotateToken'])
            ->name('settings.devices.token');

        Route::get('/settings/sms', [SmsSettingsController::class, 'edit'])->name('settings.sms');
        Route::put('/settings/sms', [SmsSettingsController::class, 'update'])->name('settings.sms.update');
        Route::post('/settings/sms/test', [SmsSettingsController::class, 'test'])->name('settings.sms.test');
        Route::post('/settings/sms/probe', [SmsSettingsController::class, 'probe'])->name('settings.sms.probe');
    });
});

/*
 * پیوندِ دامنه به برنامه‌ی اندروید.
 *
 * وقتی این فایل روی دامنه باشد و اثر انگشتِ کلیدِ امضا داخلش، اندروید
 * لینکِ https://sedo.site/queue را مستقیم در برنامه باز می‌کند و نه در
 * مرورگر — یعنی راننده‌ای که روی لینکِ پیامک می‌زند، دیگر لازم نیست دوباره
 * وارد شود.
 *
 * تا وقتی اثر انگشت تنظیم نشده، آرایه خالی برمی‌گردد. این خطا نیست:
 * اندروید فقط پیوند را برقرار نمی‌کند و همه‌چیز مثل قبل کار می‌کند.
 */
Route::get('/.well-known/assetlinks.json', function () {
    $fingerprints = config('android.fingerprints');

    $links = $fingerprints === [] ? [] : [[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => config('android.package'),
            'sha256_cert_fingerprints' => $fingerprints,
        ],
    ]];

    return response()->json($links)
        ->header('Cache-Control', 'public, max-age=3600');
})->name('assetlinks');

Route::redirect('/', '/queue');
