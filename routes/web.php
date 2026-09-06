<?php

use App\Http\Controllers\Driver\OtpController;
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
        Route::get('/', fn () => inertia('Driver/Home'))->name('home');
        Route::post('/logout', [OtpController::class, 'logout'])->name('logout');
    });
});

Route::redirect('/', '/queue');
