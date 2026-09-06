<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Sms\SmsService;
use App\Models\SmsMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSmsMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** فاصله‌ی تلاش مجدد: ۱۰ ثانیه، ۱ دقیقه، ۵ دقیقه */
    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $smsMessageId) {}

    public function handle(SmsService $sms): void
    {
        $message = SmsMessage::find($this->smsMessageId);

        if ($message === null || $message->status === 'SENT') {
            return;
        }

        $message->increment('attempts');

        $sms->dispatchNow($message);
    }
}
