<?php

declare(strict_types=1);

namespace App\Domain\Sms\Providers;

use App\Domain\Sms\SmsProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** ارائه‌دهنده‌ی پیش‌فرض توسعه: پیامک را فقط در لاگ می‌نویسد. */
final class LogSmsProvider implements SmsProvider
{
    public function send(string $to, string $body): string
    {
        $id = (string) Str::ulid();

        Log::channel(config('sms.log_channel'))->info('SMS', [
            'id' => $id,
            'to' => $to,
            'body' => $body,
        ]);

        return $id;
    }

    public function name(): string
    {
        return 'log';
    }
}
