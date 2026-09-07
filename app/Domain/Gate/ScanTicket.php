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

    public static function issue(Request $request, Appointment $appointment): void
    {
        $request->session()->put(self::PREFIX.$appointment->ulid, now()->timestamp);
    }

    public static function isValid(Request $request, Appointment $appointment): bool
    {
        $at = $request->session()->get(self::PREFIX.$appointment->ulid);

        if (! is_int($at)) {
            return false;
        }

        if (now()->timestamp - $at > self::TTL_SECONDS) {
            self::consume($request, $appointment);

            return false;
        }

        return true;
    }

    public static function consume(Request $request, Appointment $appointment): void
    {
        $request->session()->forget(self::PREFIX.$appointment->ulid);
    }
}
