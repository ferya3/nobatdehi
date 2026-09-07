<?php

namespace App\Security;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * کلاینتِ HTTPِ امن در برابر SSRF — روی Http clientِ لاراول.
 *  - فقط http/https مجاز است (نه file://, gopher:// …).
 *  - میزبان پیش از اتصال حل و در برابر deny-listِ کاملِ IPv4/IPv6 بررسی می‌شود.
 *  - ریدایرکت دنبال نمی‌شود (تا یک 3xx نتواند محافظ را به آدرس داخلی دور بزند).
 */
class SafeHttp
{
    /** اعتبارسنجیِ SSRF؛ خروجی: IPهای معتبر برای پین‌کردنِ اتصال (ضدِ DNS-rebinding). */
    public static function assertSafeUrl(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException('نشانی نامعتبر است (فقط http یا https مجاز است).');
        }

        return Ip::assertHostPublic((string) parse_url($url, PHP_URL_HOST));
    }

    /** یک PendingRequestِ آماده با محافظِ SSRF (میزبانِ url باید از پیش بررسی شده باشد). */
    public static function client(int $timeout = 15, array $curl = []): PendingRequest
    {
        $req = Http::timeout($timeout)
            ->withoutRedirecting()
            ->withOptions(['protocols' => ['http', 'https']]);

        return $curl === [] ? $req : $req->withOptions(['curl' => $curl]);
    }

    /**
     * گزینه‌های curl برای پین‌کردنِ اتصال به همان IPِ اعتبارسنجی‌شده (بستنِ DNS-rebinding/TOCTOU):
     * curl دوباره DNS را حل نمی‌کند و مستقیماً به IPِ چک‌شده وصل می‌شود.
     */
    private static function pinCurl(string $url, array $ips): array
    {
        if ($ips === []) {
            return [];
        }
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ips[0]}"]];
    }

    public static function get(string $url, array $query = [], int $timeout = 15)
    {
        $ips = self::assertSafeUrl($url);
        $client = self::client($timeout, self::pinCurl($url, $ips));

        // گزینه‌ی query در Guzzle جایگزین کوئریِ خودِ URL می‌شود، نه اضافه بر آن.
        // پس فرستادن آرایه‌ی خالی، «?to=...&text=...» را پاک می‌کند و پنل
        // سفارشی درخواستی بدون هیچ پارامتری می‌گیرد.
        return $query === [] ? $client->get($url) : $client->get($url, $query);
    }

    public static function post(string $url, array $options = [], int $timeout = 15)
    {
        $ips = self::assertSafeUrl($url);
        $req = self::client($timeout, self::pinCurl($url, $ips));
        if (!empty($options['headers'])) {
            $req = $req->withHeaders($options['headers']);
        }
        if (array_key_exists('json', $options)) {
            return $req->asJson()->post($url, $options['json']);
        }
        if (array_key_exists('form', $options)) {
            return $req->asForm()->post($url, $options['form']);
        }

        $body = $options['body'] ?? [];

        // بدنه‌ی رشته‌ای باید عیناً برود. post() از bodyFormat پیش‌فرض (json)
        // استفاده می‌کند و رشته را JSON-encode می‌کند، پس یک بدنه‌ی
        // «to=1&b=2» به «"to=1&b=2"» تبدیل می‌شد — با گیومه — در حالی که
        // هدر Content-Type می‌گفت form-urlencoded.
        if (is_string($body)) {
            $type = $options['headers']['Content-Type'] ?? 'application/x-www-form-urlencoded';

            return $req->withBody($body, $type)->post($url);
        }

        return $req->post($url, $body);
    }
}
