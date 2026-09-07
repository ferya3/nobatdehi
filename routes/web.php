<?php

use App\Http\Controllers\Driver\AppointmentController;
use App\Http\Controllers\Driver\BookingController;
use App\Http\Controllers\Driver\OtpController;
use App\Http\Controllers\Staff\Catalog\ProductController;
use App\Http\Controllers\Staff\Catalog\TruckTypeController;
use App\Http\Controllers\Staff\DashboardController;
use App\Http\Controllers\Staff\GateController;
use App\Http\Controllers\Staff\HomeController;
use App\Http\Controllers\Staff\LoginController;
use App\Http\Controllers\Staff\QueueController;
use App\Http\Controllers\Staff\ReportController;
use App\Http\Controllers\Staff\SettingsController;
use App\Http\Controllers\Staff\SmsSettingsController;
use App\Http\Controllers\Staff\WeighbridgeController;
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

    Route::middleware('auth:web')->group(function () {
        Route::get('/', HomeController::class)->name('home');
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // نگهبانی / باسکول
        Route::get('/gate', [GateController::class, 'index'])->name('gate.index');
        Route::post('/gate/scan', [GateController::class, 'scan'])->name('gate.scan');
        Route::post('/gate/lookup', [GateController::class, 'lookup'])->name('gate.lookup');
        Route::post('/gate/{appointment}/check-in', [GateController::class, 'checkIn'])->name('gate.check-in');

        // باسکول اول و دوم
        Route::get('/weighbridge', [WeighbridgeController::class, 'index'])->name('weighbridge.index');
        Route::post('/weighbridge/scan', [WeighbridgeController::class, 'scan'])->name('weighbridge.scan');
        Route::post('/weighbridge/{appointment}/record', [WeighbridgeController::class, 'record'])
            ->name('weighbridge.record');

        Route::get('/queue', [QueueController::class, 'index'])->name('queue.index');
        Route::get('/queue/{appointment}', [QueueController::class, 'show'])->name('queue.show');
        Route::post('/queue/{appointment}/transition', [QueueController::class, 'transition'])
            ->name('queue.transition');

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

        Route::get('/settings/sms', [SmsSettingsController::class, 'edit'])->name('settings.sms');
        Route::put('/settings/sms', [SmsSettingsController::class, 'update'])->name('settings.sms.update');
        Route::post('/settings/sms/test', [SmsSettingsController::class, 'test'])->name('settings.sms.test');
        Route::post('/settings/sms/probe', [SmsSettingsController::class, 'probe'])->name('settings.sms.probe');
    });
});

Route::redirect('/', '/queue');
