<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Sms\Providers\KavenegarProvider;
use App\Domain\Sms\Providers\LogSmsProvider;
use App\Domain\Sms\SmsProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsProvider::class, function (): SmsProvider {
            $driver = (string) config('sms.provider', 'log');

            return match ($driver) {
                'log' => new LogSmsProvider(),
                'kavenegar' => new KavenegarProvider(
                    apiKey: (string) config('sms.providers.kavenegar.api_key'),
                    sender: config('sms.providers.kavenegar.sender'),
                    timeout: (int) config('sms.providers.kavenegar.timeout', 10),
                ),
                default => throw new InvalidArgumentException("ارائه‌دهنده‌ی پیامک ناشناخته: {$driver}"),
            };
        });
    }
}
