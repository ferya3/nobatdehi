<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\Appointment;
use Illuminate\Http\Request;

/**
 * اثباتِ اینکه QR این نوبت، همین الان و روی همین ایستگاه، اسکن شده است.
 *
 * بدون این، «بدون QR ورود ممنوع» یک جمله در UI بود: کافی بود کسی مستقیم
 * POST بزند به مسیر check-in. بلیط در session ایستگاه می‌نشیند، عمر کوتاه
 * دارد، و بعد از مصرف پاک می‌شود تا یک اسکن، دو کامیون را وارد نکند.
 */
final class ScanTicket
{
    /** فاصله‌ی معقول بین اسکن و فشردن دکمه‌ی ورود */
    public const TTL_SECONDS = 600;

    private const PREFIX = 'gate.scan.';

    /** با دوربینِ مرورگر خوانده شد */
    public const SOURCE_CAMERA = 'camera';

    /** با بارکدخوانِ سخت‌افزاری خوانده شد */
    public const SOURCE_BARCODE = 'barcode';

    /**
     * بلیط، خودِ دستگاهِ اسکن را هم با خودش می‌برد.
     *
     * وگرنه در سابقه فقط می‌ماند «با QR وارد شد» و معلوم نیست بارکدخوانِ
     * گیت خوانده یا کسی با دوربینِ گوشی‌اش عکسِ QR را اسکن کرده — که دو
     * چیز کاملاً متفاوت‌اند وقتی بعداً دنبال یک ورودِ مشکوک می‌گردیم.
     */
    public static function issue(Request $request, Appointment $appointment, string $source = self::SOURCE_CAMERA): void
    {
        $request->session()->put(self::PREFIX.$appointment->ulid, [
            'at' => now()->timestamp,
            'source' => $source,
        ]);
    }

    public static function isValid(Request $request, Appointment $appointment): bool
    {
        return self::read($request, $appointment) !== null;
    }

    /** دستگاهی که این نوبت را اسکن کرد، یا null اگر بلیطی نیست */
    public static function source(Request $request, Appointment $appointment): ?string
    {
        return self::read($request, $appointment)['source'] ?? null;
    }

    /** @return array{at: int, source: string}|null */
    private static function read(Request $request, Appointment $appointment): ?array
    {
        $ticket = $request->session()->get(self::PREFIX.$appointment->ulid);

        if (! is_array($ticket) || ! is_int($ticket['at'] ?? null)) {
            return null;
        }

        if (now()->timestamp - $ticket['at'] > self::TTL_SECONDS) {
            self::consume($request, $appointment);

            return null;
        }

        return [
            'at' => $ticket['at'],
            'source' => is_string($ticket['source'] ?? null) ? $ticket['source'] : self::SOURCE_CAMERA,
        ];
    }

    public static function consume(Request $request, Appointment $appointment): void
    {
        $request->session()->forget(self::PREFIX.$appointment->ulid);
    }
}
