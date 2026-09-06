<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use App\Domain\Sms\Exceptions\SmsSendFailed;
use App\Jobs\SendSmsMessage;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Support\Mobile;
use Illuminate\Database\Eloquent\Model;

/**
 * پیامک هرگز داخل Request اصلی ارسال نمی‌شود.
 *
 * queue() فقط یک ردیف sms_messages می‌سازد و Job را روی صف می‌گذارد؛ اگر
 * ارائه‌دهنده کند شود، صدور نوبت کند نمی‌شود.
 */
final class SmsService
{
    public function __construct(private readonly SmsProvider $provider) {}

    /** ارسال با قالب ذخیره‌شده در دیتابیس */
    public function queueTemplate(string $templateKey, string $to, array $variables = [], ?Model $related = null): ?SmsMessage
    {
        $template = SmsTemplate::where('key', $templateKey)->where('is_active', true)->first();

        if ($template === null) {
            return null;
        }

        return $this->queue($to, $this->render($template->body, $variables), $templateKey, $related);
    }

    public function queue(string $to, string $body, ?string $templateKey = null, ?Model $related = null): ?SmsMessage
    {
        $normalized = Mobile::normalize($to);

        if ($normalized === null) {
            return null;
        }

        $message = new SmsMessage([
            'to' => $normalized,
            'body' => $body,
            'template_key' => $templateKey,
            'status' => 'QUEUED',
        ]);

        if ($related !== null) {
            $message->related()->associate($related);
        }

        $message->save();

        SendSmsMessage::dispatch($message->id);

        return $message;
    }

    /** ارسال واقعی — فقط از داخل Job صدا زده می‌شود */
    public function dispatchNow(SmsMessage $message): void
    {
        try {
            $providerId = $this->provider->send($message->to, $message->body);

            $message->forceFill([
                'status' => 'SENT',
                'provider' => $this->provider->name(),
                'provider_message_id' => $providerId ?: null,
                'sent_at' => now(),
                'error' => null,
            ])->save();
        } catch (SmsSendFailed $e) {
            $message->forceFill([
                'status' => 'FAILED',
                'provider' => $this->provider->name(),
                'error' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }

    /** جای‌گذاری {متغیر}ها در متن قالب */
    public function render(string $body, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $body = str_replace('{'.$key.'}', (string) $value, $body);
        }

        return $body;
    }
}
