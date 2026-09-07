<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Support\TrustedProxies;
use App\Http\Middleware\VerifyDeviceToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        /*
         * فقط پروکسی‌های واقعی مورد اعتمادند.
         *
         * '*' یعنی هر کسی می‌تواند X-Forwarded-For جعل کند و آن‌وقت
         * rate limit روی IP، لاگ امنیتی و مسدودسازی همگی به یک عدد دلخواه
         * نگاه می‌کنند. پیش‌فرض همان چیزی است که نصب‌کننده می‌سازد: Nginx
         * روی همین ماشین. پشت Cloudflare یا لود بالانسر، TRUSTED_PROXIES را
         * روی رنج واقعی آن‌ها بگذارید.
         */
        $middleware->trustProxies(
            at: TrustedProxies::from(env('TRUSTED_PROXIES')),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // هدرهای امنیتی روی هر پاسخ — نه فقط جایی که Nginx یادش بماند
        $middleware->append(SecurityHeaders::class);

        // دوربین پلاک‌خوان و پل باسکول کاربر نیستند و session ندارند؛
        // توکن دستگاه دارند. پارامتر می‌گوید کدام دستگاه: device:gate یا device:scale
        $middleware->alias(['device' => VerifyDeviceToken::class]);

        // دو دروازه‌ی ورود جدا: راننده و کارکنان
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('panel*')
            ? route('staff.login')
            : route('driver.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
