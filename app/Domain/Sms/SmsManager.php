<?php

declare(strict_types=1);

namespace App\Domain\Sms;

use App\Security\SafeHttp;
use Throwable;

/**
 * ارسال پیامک از طریق سرویس‌دهنده‌های مختلف.
 *
 * پورتِ App\Services\Sms\SmsManager از پروژه‌ی payroll-saas، تا همان پنل
 * پیامکی با همان تنظیمات اینجا هم کار کند. رفتار هر ارائه‌دهنده — از جمله
 * دامنه‌های جایگزین پنل افه و شکل تشخیص موفقیت آن — عمداً دست‌نخورده مانده.
 *
 * همه‌ی درخواست‌های بیرونی از SafeHttp (محافظ SSRF) عبور می‌کنند.
 * خروجی send: [status, detail]، status از: sent | failed | disabled
 */
class SmsManager
{
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISABLED = 'disabled';

    /** @param array<string, mixed> $s تنظیمات (Setting::values()) */
    public function send(array $s, string $phone, string $text): array
    {
        if (($s['sms_enabled'] ?? '') !== '1') {
            return [self::STATUS_DISABLED, 'ارسال پیامک در تنظیمات غیرفعال است'];
        }

        $phone = trim($phone);

        if ($phone === '') {
            return [self::STATUS_FAILED, 'شماره موبایل خالی است'];
        }

        $provider = ($s['sms_provider'] ?? '') ?: 'console';

        try {
            return match ($provider) {
                'console' => $this->console($phone, $text),
                'afe' => $this->afe($s, $phone, $text),
                'kavenegar' => $this->kavenegar($s, $phone, $text),
                'smsir' => $this->smsir($s, $phone, $text),
                'melipayamak' => $this->melipayamak($s, $phone, $text),
                'custom' => $this->custom($s, $phone, $text),
                default => [self::STATUS_FAILED, 'سرویس‌دهنده‌ی ناشناخته: '.$provider],
            };
        } catch (Throwable $e) {
            return [self::STATUS_FAILED, 'خطای ارسال پیامک: '.mb_substr($e->getMessage(), 0, 150)];
        }
    }

    /**
     * آیا تنظیمات فعلی اصلاً قابل ارسال هستند؟ اگر نه، دلیلش را برمی‌گرداند.
     *
     * send() خودش هم همین‌ها را چک می‌کند؛ این متد جداست تا صف بتواند بین
     * «پنل تنظیم نشده» (تلاش مجدد بی‌فایده است) و «پنل جواب نداد»
     * (ارزش تلاش مجدد دارد) فرق بگذارد.
     */
    public function configurationError(array $s): ?string
    {
        $provider = ($s['sms_provider'] ?? '') ?: 'console';
        $has = fn (string $key): bool => trim((string) ($s[$key] ?? '')) !== '';

        return match ($provider) {
            'console' => null,
            'afe', 'melipayamak' => match (true) {
                ! $has('sms_username') || ! $has('sms_password') => 'نام کاربری/رمز پنل پیامک ثبت نشده است',
                $provider === 'afe' && ! $has('sms_sender') => 'شماره اختصاصی (خط ارسال) ثبت نشده است',
                default => null,
            },
            'kavenegar' => $has('sms_api_key') ? null : 'کلید API کاوه‌نگار ثبت نشده است',
            'smsir' => $has('sms_api_key') ? null : 'کلید API سرویس SMS.ir ثبت نشده است',
            'custom' => $has('sms_custom_url') ? null : 'لینک ارسال پنل سفارشی ثبت نشده است',
            default => 'سرویس‌دهنده‌ی ناشناخته: '.$provider,
        };
    }

    /** @return array<string, string> کلید ارائه‌دهنده => برچسب فارسی */
    public static function providers(): array
    {
        return [
            'console' => 'حالت آزمایشی (بدون ارسال واقعی)',
            'afe' => 'عصر فرا ارتباط و نمایندگی‌ها (afe.ir، wide.ir، …)',
            'kavenegar' => 'کاوه‌نگار',
            'smsir' => 'SMS.ir',
            'melipayamak' => 'ملی‌پیامک',
            'custom' => 'سفارشی — لینک ارسال هر پنل دیگر',
        ];
    }

    private function console(string $phone, string $text): array
    {
        $line = '['.date('Y-m-d H:i:s')."] به {$phone}:\n{$text}\n----\n";
        @file_put_contents(storage_path('logs/sms-test.log'), $line, FILE_APPEND);

        return [self::STATUS_SENT, 'حالت آزمایشی (متن پیامک در storage/logs/sms-test.log ثبت شد)'];
    }

    // ------------------------------- عصر فرا ارتباط (afe.ir / wide.ir) ---

    /** @return array<int, string> */
    private function afeCandidates(array $s): array
    {
        $list = [];
        $domain = trim((string) ($s['sms_afe_domain'] ?? ''));

        if ($domain !== '') {
            $d = preg_replace('#^www\.#i', '', (string) preg_replace('#/.*$#', '', $domain));
            $list[] = preg_match('#^https?://#i', $domain) ? $domain : 'https://www.'.$d.'/Url/SendSMS.aspx';
        }

        $list[] = 'https://www.afe.ir/Url/SendSMS.aspx';
        $list[] = 'https://www.wide.ir/Url/SendSMS.aspx';

        return array_values(array_unique($list));
    }

    private function afeStatusText(string $body): string
    {
        $body = trim($body);
        $pos = strpos($body, '<');

        return trim($pos === false ? $body : substr($body, 0, $pos));
    }

    private function afeIsSuccess(string $body): bool
    {
        if (stripos($body, 'send successfully') !== false) {
            return true;
        }

        $status = $this->afeStatusText($body);

        return $status !== '' && ctype_digit(str_replace([',', ' '], '', $status));
    }

    private function afe(array $s, string $phone, string $text): array
    {
        if (trim((string) ($s['sms_username'] ?? '')) === '' || trim((string) ($s['sms_password'] ?? '')) === '') {
            return [self::STATUS_FAILED, 'نام کاربری/رمز پنل پیامک ثبت نشده است'];
        }

        if (trim((string) ($s['sms_sender'] ?? '')) === '') {
            return [self::STATUS_FAILED, 'شماره اختصاصی (خط ارسال) ثبت نشده است'];
        }

        $query = [
            'Username' => trim((string) $s['sms_username']),
            'Password' => trim((string) $s['sms_password']),
            'Number' => trim((string) $s['sms_sender']),
            'Mobile' => $phone,
            'SMS' => $text,
        ];

        foreach ($this->afeCandidates($s) as $base) {
            try {
                $resp = SafeHttp::get($base, $query);
            } catch (Throwable) {
                continue;
            }

            $body = (string) $resp->body();

            if ($this->afeIsSuccess($body)) {
                return [self::STATUS_SENT, 'پیامک ارسال شد (پاسخ پنل: '.mb_substr($this->afeStatusText($body), 0, 80).')'];
            }

            $status = $this->afeStatusText($body);

            if ($status !== '') {
                return [self::STATUS_FAILED, mb_substr($status, 0, 160)];
            }
        }

        return [self::STATUS_FAILED, 'ارسال نشد — پاسخ نامشخص از پنل (احتمالاً محدودیت موقت).'];
    }

    /**
     * عیب‌یابی هوشمند پنل افه/نمایندگی‌ها: چند آدرس را با مشخصات ثبت‌شده امتحان
     * می‌کند و در اولین «ارسال موفق» می‌ایستد تا پیامک تکراری نرود.
     *
     * @return array<int, array{url: string, host: string, code: int, ok: bool, body: string}>
     */
    public function probeAfe(array $s, string $testMobile): array
    {
        $query = [
            'Username' => trim((string) ($s['sms_username'] ?? '')),
            'Password' => trim((string) ($s['sms_password'] ?? '')),
            'Number' => trim((string) ($s['sms_sender'] ?? '')),
            'Mobile' => trim($testMobile),
            'SMS' => "تست سامانه نوبت‌دهی بارگیری\nاین پیام آزمایشی است.",
        ];

        $results = [];

        foreach ($this->afeCandidates($s) as $base) {
            $row = [
                'url' => $base.'?'.preg_replace('/(Password=)[^&]*/i', '$1******', http_build_query($query)),
                'host' => parse_url($base, PHP_URL_HOST) ?: $base,
                'code' => 0,
                'ok' => false,
                'body' => '',
            ];

            try {
                $resp = SafeHttp::get($base, $query);
                $row['code'] = $resp->status();
                $row['body'] = trim((string) $resp->body());
                $row['ok'] = $this->afeIsSuccess($row['body']);
            } catch (Throwable $e) {
                $row['body'] = 'خطای اتصال: '.$e->getMessage();
            }

            $results[] = $row;

            if ($row['ok']) {
                break; // آدرس درست پیدا شد
            }
        }

        return $results;
    }

    // ------------------------------------------------- بقیه‌ی پنل‌ها ---

    private function kavenegar(array $s, string $phone, string $text): array
    {
        $apiKey = trim((string) ($s['sms_api_key'] ?? ''));

        if ($apiKey === '') {
            return [self::STATUS_FAILED, 'کلید API کاوه‌نگار ثبت نشده است'];
        }

        $params = ['receptor' => $phone, 'message' => $text];

        if (trim((string) ($s['sms_sender'] ?? '')) !== '') {
            $params['sender'] = trim((string) $s['sms_sender']);
        }

        $url = 'https://api.kavenegar.com/v1/'.rawurlencode($apiKey).'/sms/send.json';
        $data = SafeHttp::get($url, $params)->json() ?: [];

        if (($data['return']['status'] ?? 0) === 200) {
            return [self::STATUS_SENT, 'پیامک با موفقیت از طریق کاوه‌نگار ارسال شد'];
        }

        return [self::STATUS_FAILED, 'کاوه‌نگار: '.($data['return']['message'] ?? 'خطای نامشخص')];
    }

    private function smsir(array $s, string $phone, string $text): array
    {
        $apiKey = trim((string) ($s['sms_api_key'] ?? ''));

        if ($apiKey === '') {
            return [self::STATUS_FAILED, 'کلید API سرویس SMS.ir ثبت نشده است'];
        }

        $resp = SafeHttp::post('https://api.sms.ir/v1/send/bulk', [
            'headers' => ['x-api-key' => $apiKey, 'Accept' => 'application/json'],
            'json' => [
                'lineNumber' => trim((string) ($s['sms_sender'] ?? '')),
                'messageText' => $text,
                'mobiles' => [$phone],
            ],
        ]);

        $data = $resp->json() ?: [];

        if (($data['status'] ?? 0) === 1) {
            return [self::STATUS_SENT, 'پیامک با موفقیت از طریق SMS.ir ارسال شد'];
        }

        return [self::STATUS_FAILED, 'SMS.ir: '.($data['message'] ?? 'خطای نامشخص')];
    }

    private function melipayamak(array $s, string $phone, string $text): array
    {
        $username = trim((string) ($s['sms_username'] ?? ''));
        $password = trim((string) ($s['sms_password'] ?? ''));

        if ($username === '' || $password === '') {
            return [self::STATUS_FAILED, 'نام کاربری/رمز ملی‌پیامک ثبت نشده است'];
        }

        $resp = SafeHttp::post('https://rest.payamak-panel.com/api/SendSMS/SendSMS', [
            'headers' => ['Accept' => 'application/json'],
            'json' => [
                'username' => $username,
                'password' => $password,
                'to' => $phone,
                'from' => trim((string) ($s['sms_sender'] ?? '')),
                'text' => $text,
            ],
        ]);

        $data = $resp->json() ?: [];

        if (($data['RetStatus'] ?? 0) === 1) {
            return [self::STATUS_SENT, 'پیامک با موفقیت از طریق ملی‌پیامک ارسال شد'];
        }

        return [self::STATUS_FAILED, 'ملی‌پیامک: '.($data['StrRetStatus'] ?? 'خطای نامشخص')];
    }

    private function custom(array $s, string $phone, string $text): array
    {
        $template = trim((string) ($s['sms_custom_url'] ?? ''));

        if ($template === '') {
            return [self::STATUS_FAILED, 'لینک ارسال پنل سفارشی ثبت نشده است'];
        }

        $values = [
            '{to}' => rawurlencode($phone),
            '{text}' => rawurlencode($text),
            '{from}' => rawurlencode(trim((string) ($s['sms_sender'] ?? ''))),
            '{username}' => rawurlencode(trim((string) ($s['sms_username'] ?? ''))),
            '{password}' => rawurlencode(trim((string) ($s['sms_password'] ?? ''))),
            '{apikey}' => rawurlencode(trim((string) ($s['sms_api_key'] ?? ''))),
        ];

        $method = strtoupper((string) ($s['sms_custom_method'] ?? 'GET'));

        if ($method === 'POST' && str_contains($template, '?')) {
            [$base, $query] = explode('?', $template, 2);
            $resp = SafeHttp::post($base, [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => strtr($query, $values),
            ]);
        } else {
            $resp = SafeHttp::get(strtr($template, $values));
        }

        $code = $resp->status();
        $body = trim((string) $resp->body());

        if ($code >= 200 && $code < 300) {
            return [self::STATUS_SENT, "پاسخ پنل (کد {$code}): ".(mb_substr($body, 0, 150) ?: 'بدون متن')];
        }

        return [self::STATUS_FAILED, "پاسخ غیرمنتظره‌ی پنل (کد {$code}): ".mb_substr($body, 0, 150)];
    }
}
