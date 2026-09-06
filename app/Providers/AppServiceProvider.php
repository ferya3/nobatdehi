<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();

        Password::defaults(fn () => Password::min(10)->uncompromised());

        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // درخواست کد: هم روی شماره، هم روی IP
        RateLimiter::for('otp-request', fn (Request $request) => [
            Limit::perMinute(3)->by('ip:'.$request->ip()),
            Limit::perHour(20)->by('ip:'.$request->ip()),
            Limit::perHour(5)->by('mobile:'.$request->input('mobile')),
        ]);

        // تأیید کد: جلوی brute-force روی یک کد ۵ رقمی
        RateLimiter::for('otp-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perHour(30)->by('ip:'.$request->ip()),
        ]);

        // ورود کارکنان: هم روی ایمیل، هم روی IP
        RateLimiter::for('staff-login', fn (Request $request) => [
            Limit::perMinute(5)->by('email:'.$request->input('email')),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('booking', fn (Request $request) => [
            Limit::perMinute(10)->by('driver:'.($request->user('driver')?->id ?? $request->ip())),
        ]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
