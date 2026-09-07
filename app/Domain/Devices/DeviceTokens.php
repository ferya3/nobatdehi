<?php

declare(strict_types=1);

namespace App\Domain\Devices;

use App\Models\Setting;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * توکن دستگاه‌هایی که کاربر نیستند.
 *
 * دوربین پلاک‌خوان و پل باسکول هیچ‌کدام session ندارند و نمی‌توانند داشته
 * باشند. تنها چیزی که دارند یک رشته‌ی ثابت است که در تنظیماتشان تایپ شده.
 *
 * هر نوع دستگاه توکن جدا دارد و نه یکی مشترک: عوض‌کردن توکن دوربین نباید
 * باسکول را از کار بیندازد، و دستگاهی که به دست کسی افتاده نباید کلید بقیه
 * را هم داده باشد.
 */
final class DeviceTokens
{
    /** دوربین پلاک‌خوان گیت */
    public const GATE = 'gate';

    /** پل نرم‌افزاریِ نشان‌دهنده‌ی باسکول */
    public const SCALE = 'scale';

    /** @var array<string, array{token: string, enabled: string}> */
    private const KEYS = [
        self::GATE => ['token' => 'gate_anpr_token', 'enabled' => 'gate_anpr_enabled'],
        self::SCALE => ['token' => 'scale_device_token', 'enabled' => 'scale_device_enabled'],
    ];

    public static function kinds(): array
    {
        return array_keys(self::KEYS);
    }

    public static function isKind(string $kind): bool
    {
        return array_key_exists($kind, self::KEYS);
    }

    public function enabled(string $kind): bool
    {
        return Setting::get($this->key($kind, 'enabled'), '0') === '1';
    }

    public function token(string $kind): string
    {
        return Setting::get($this->key($kind, 'token'));
    }

    public function hasToken(string $kind): bool
    {
        return $this->token($kind) !== '';
    }

    /**
     * مقایسه‌ی توکن.
     *
     * hash_equals و نه === : مقایسه‌ی معمولی رشته به‌محض اولین بایتِ متفاوت
     * برمی‌گردد و همان اختلاف زمان، حدس‌زدن توکن را ممکن می‌کند.
     */
    public function matches(string $kind, ?string $candidate): bool
    {
        $token = $this->token($kind);

        if ($token === '' || $candidate === null || $candidate === '') {
            return false;
        }

        return hash_equals($token, $candidate);
    }

    /** توکن تازه می‌سازد — مقدارِ خام فقط همین یک بار برمی‌گردد */
    public function rotate(string $kind): string
    {
        $token = Str::random(48);

        Setting::putMany([$this->key($kind, 'token') => $token]);

        return $token;
    }

    private function key(string $kind, string $which): string
    {
        if (! self::isKind($kind)) {
            throw new InvalidArgumentException("نوع دستگاه ناشناخته: {$kind}");
        }

        return self::KEYS[$kind][$which];
    }
}
