<?php

declare(strict_types=1);

namespace App\Domain\Sms\Providers;

use App\Domain\Sms\Exceptions\SmsSendFailed;
use App\Domain\Sms\SmsProvider;
use Illuminate\Support\Facades\Http;

final class KavenegarProvider implements SmsProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $sender = null,
        private readonly int $timeout = 10,
    ) {}

    public function send(string $to, string $body): string
    {
        $response = Http::timeout($this->timeout)
            ->asForm()
            ->post("https://api.kavenegar.com/v1/{$this->apiKey}/sms/send.json", array_filter([
                'receptor' => $to,
                'message' => $body,
                'sender' => $this->sender,
            ]));

        if ($response->failed()) {
            throw new SmsSendFailed('ارسال پیامک ناموفق بود: HTTP '.$response->status());
        }

        $status = (int) data_get($response->json(), 'return.status');

        if ($status !== 200) {
            throw new SmsSendFailed(
                'ارسال پیامک ناموفق بود: '.(data_get($response->json(), 'return.message') ?? $status)
            );
        }

        return (string) data_get($response->json(), 'entries.0.messageid', '');
    }

    public function name(): string
    {
        return 'kavenegar';
    }
}
