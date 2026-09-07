<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use App\Domain\Sms\Exceptions\SmsSendFailed;
use App\Jobs\SendSmsMessage;
use App\Models\Setting;
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
    public function __construct(private readonly SmsManager $manager) {}

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
            'body' => $this->withOptOut($body),
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
        $settings = Setting::values();

        // پنل تنظیم‌نشده با تلاش مجدد درست نمی‌شود؛ یک‌بار ثبت و تمام.
        if ($reason = $this->manager->configurationError($settings)) {
            $message->forceFill([
                'status' => 'FAILED',
                'provider' => (string) ($settings['sms_provider'] ?? ''),
                'error' => $reason,
            ])->save();

            return;
        }

        [$status, $detail] = $this->manager->send($settings, $message->to, $message->body);

        $message->forceFill([
            'status' => match ($status) {
                SmsManager::STATUS_SENT => 'SENT',
                SmsManager::STATUS_DISABLED => 'DISABLED',
                default => 'FAILED',
            },
            'provider' => (string) ($settings['sms_provider'] ?? ''),
            'error' => $status === SmsManager::STATUS_SENT ? null : $detail,
            'sent_at' => $status === SmsManager::STATUS_SENT ? now() : null,
        ])->save();

        // خاموش‌بودن پیامک در تنظیمات خطا نیست؛ تلاش دوباره هم بی‌فایده است.
        if ($status === SmsManager::STATUS_FAILED) {
            throw new SmsSendFailed($detail);
        }
    }

    /**
     * متن نهایی پیام: خط «لغو» تنظیمات به انتهای هر پیام اضافه می‌شود،
     * همان‌طور که در payroll-saas انجام می‌شود.
     */
    private function withOptOut(string $body): string
    {
        $optOut = trim(Setting::get('sms_optout'));

        return $optOut === '' ? $body : $body."\n".$optOut;
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
