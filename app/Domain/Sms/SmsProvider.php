<?php

declare(strict_types=1);

namespace App\Domain\Sms;

/**
 * قرارداد ارسال پیامک. کد برنامه هرگز مستقیماً به یک پنل پیامکی وصل نمی‌شود؛
 * تعویض ارائه‌دهنده باید یک تغییر در config باشد، نه جراحی نصف پروژه.
 */
interface SmsProvider
{
    /** @return string شناسه‌ی پیام نزد ارائه‌دهنده */
    public function send(string $to, string $body): string;

    public function name(): string;
}
