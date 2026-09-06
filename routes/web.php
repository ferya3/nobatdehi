<?php

use App\Http\Controllers\Driver\AppointmentController;
use App\Http\Controllers\Driver\BookingController;
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

Route::redirect('/', '/queue');
