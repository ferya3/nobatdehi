<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Support;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

/**
 * توکن QR نوبت.
 *
 * محتوای QR هرگز appointment_id خام نیست — آن را هر کسی می‌تواند حدس بزند.
 * به‌جایش یک توکن امضاشده با HMAC است که:
 *   - به یک نوبت مشخص گره خورده
 *   - تاریخ انقضا دارد
 *   - nonce دارد تا هر بار صدور، توکن قبلی را باطل کند
 *
 * سرور فقط hash توکن را نگه می‌دارد؛ لو رفتن دیتابیس، QR جعل‌کردنی نمی‌کند.
 */
final class QrToken
{
    private const VERSION = 'v1';

    /** @return array{token: string, hash: string} */
    public static function issue(Appointment $appointment): array
    {
        $nonce = Str::random(16);
        $expiresAt = self::expiryFor($appointment)->getTimestamp();

        $payload = implode('.', [self::VERSION, $appointment->ulid, $nonce, $expiresAt]);
        $token = $payload.'.'.self::sign($payload);

        return ['token' => $token, 'hash' => self::hash($token)];
    }

    /**
     * اعتبارسنجی توکن. در صورت معتبر بودن، ULID نوبت را برمی‌گرداند.
     *
     * @return array{ulid: string}|null
     */
    public static function parse(?string $token): ?array
    {
        if ($token === null || substr_count($token, '.') !== 4) {
            return null;
        }

        [$version, $ulid, $nonce, $expiresAt, $signature] = explode('.', $token);

        if ($version !== self::VERSION) {
            return null;
        }

        $payload = implode('.', [$version, $ulid, $nonce, $expiresAt]);

        if (! hash_equals(self::sign($payload), $signature)) {
            return null;
        }

        if (! ctype_digit($expiresAt) || (int) $expiresAt < time()) {
            return null;
        }

        return ['ulid' => $ulid];
    }

    /** hash ذخیره‌شده در دیتابیس برای تطبیق — از خودش توکن ساخته نمی‌شود */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function matches(Appointment $appointment, string $token): bool
    {
        return $appointment->qr_token_hash !== null
            && hash_equals($appointment->qr_token_hash, self::hash($token));
    }

    /** توکن تا پایان روز نوبت معتبر است */
    private static function expiryFor(Appointment $appointment): CarbonImmutable
    {
        return CarbonImmutable::parse($appointment->date->format('Y-m-d'))->endOfDay();
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) Config::get('app.key'));
    }
}
