<?php

namespace App\Security;

/**
 * محافظِ SSRF: تشخیصِ آدرس‌های داخلی/خصوصی/رزروشده برای IPv4 و IPv6 با deny-listِ کامل.
 */
class Ip
{
    private const V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '255.255.255.255/32',
    ];

    private const V6 = [
        '::1/128', '::/128', '::ffff:0:0/96', 'fc00::/7', 'fe80::/10', 'fec0::/10',
        'ff00::/8', '2001:db8::/32', '64:ff9b::/96', '100::/64',
    ];

    /** تطبیقِ IP با یک CIDR — هم IPv4 هم IPv6 (مقایسهٔ بیتیِ پیشوند). */
    public static function cidrMatch(string $ip, string $cidr): bool
    {
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton((string) $net);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }
        $bits = $bits === null ? strlen($ipBin) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rem)) & 0xFF);
        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($netBin[$bytes]) & ord($mask));
    }

    /** آیا این IP به شبکهٔ داخلی/خصوصی/رزروشده اشاره می‌کند؟ */
    public static function isPrivate(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return true; // نامعتبر → محافظه‌کارانه مسدود
        }
        // IPv4-mapped / -compatible در IPv6 → آدرسِ IPv4 نهفته را استخراج و بررسی کن
        if (strlen($bin) === 16 && strncmp($bin, str_repeat("\x00", 10), 10) === 0) {
            $marker = substr($bin, 10, 2);
            if ($marker === "\xff\xff" || $marker === "\x00\x00") {
                $v4 = @inet_ntop(substr($bin, 12));
                if ($v4 !== false && str_contains($v4, '.')) {
                    return self::isPrivate($v4);
                }
            }
        }
        foreach (strlen($bin) === 4 ? self::V4 : self::V6 as $cidr) {
            if (self::cidrMatch($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * حلِ نامِ میزبان به IPها (IPv4 + IPv6) و اطمینان از اینکه هیچ‌کدام داخلی نیستند.
     * در صورت داخلی‌بودن، استثنا پرتاب می‌کند. اگر resolver محدود بود و چیزی حل نشد، عبور می‌دهد.
     *
     * خروجی: IPهای معتبرِ یک میزبانِ نامی برای «پین‌کردن» در curl (ضدِ DNS-rebinding)؛
     * برای IP literal آرایهٔ خالی (نیازی به پین نیست، اتصال مستقیم است).
     */
    public static function assertHostPublic(string $host): array
    {
        $host = trim($host, '[]');
        if ($host === '') {
            throw new \RuntimeException('میزبانِ نامعتبر.');
        }
        $isLiteral = (bool) filter_var($host, FILTER_VALIDATE_IP);
        $ips = [];
        if ($isLiteral) {
            $ips[] = $host;
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }
        foreach ($ips as $ip) {
            if (self::isPrivate($ip)) {
                throw new \RuntimeException('اتصال به نشانی داخلی/خصوصی مجاز نیست.');
            }
        }

        return $isLiteral ? [] : $ips;
    }
}
