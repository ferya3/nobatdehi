<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Truck\PlateNumber;
use App\Models\Appointment;
use App\Models\PlateReading;

/**
 * «پلاکِ جلوی راهبند همان پلاکِ حواله است؟»
 *
 * ترتیب مراجع عمدی است و از بالا به پایین:
 *
 *   ۱. خواندنِ دوربین (پلاک‌خوان شبکه‌ای یا دوربین ایستگاه)
 *   ۲. رشته‌ی پلاکی که یک دستگاه مستقیم فرستاده
 *   ۳. تأیید چشمی نگهبان
 *
 * دستگاه بالاتر از نگهبان است چون تبانی نمی‌کند؛ ولی وقتی دستگاه *نتوانسته*
 * بخواند — پلاک گِلی، شب، باران — تصمیم به نگهبان برمی‌گردد. فرقِ «دوربین
 * پلاک دیگری خواند» با «دوربین چیزی نخواند» همان مرزی است که اگر رعایت
 * نشود، اولین شبِ بارانی کل گیت را قفل می‌کند یا کل قانون را بی‌اثر.
 */
final class PlateVerifier
{
    public function __construct(private readonly GateDevices $devices) {}

    public function verify(
        Appointment $appointment,
        ?PlateReading $reading,
        ?string $observedPlate,
        bool $guardConfirmed,
    ): PlateVerdict {
        $expected = $appointment->truck?->plate_key;

        if ($reading !== null) {
            $verdict = $this->fromReading($appointment, $reading, $expected);

            if ($verdict !== null) {
                return $verdict;
            }
            // دوربین نتوانست بخواند — می‌افتد روی مراجع بعدی
        }

        $observed = trim((string) $observedPlate);

        if ($observed !== '') {
            $normalised = PlateNumber::normalizeKey($observed);

            return $normalised !== null && $normalised === $expected
                ? PlateVerdict::pass(PlateVerdict::BY_DEVICE, $expected, $normalised)
                : PlateVerdict::fail(
                    PlateVerdict::BY_DEVICE,
                    $expected,
                    $normalised ?? $observed,
                    'پلاک خوانده‌شده با پلاک حواله یکی نیست. راهبند باز نمی‌شود؛ موضوع به حراست گزارش شد.',
                );
        }

        return $guardConfirmed
            ? PlateVerdict::pass(PlateVerdict::BY_GUARD, $expected)
            : PlateVerdict::fail(
                PlateVerdict::BY_GUARD,
                $expected,
                null,
                'مغایرت پلاک ثبت شد و ورود انجام نشد.',
            );
    }

    /**
     * حرفِ دوربین.
     *
     * null یعنی «این خواندن تصمیم‌گیر نیست» و نه «مطابق است» — فراخواننده
     * باید برود سراغ مرجع بعدی. سه حالت به null می‌رسند: دوربین نتوانست
     * بخواند، اطمینانش زیر آستانه بود، یا اصلاً عکسی از پلاک نداشت.
     */
    private function fromReading(Appointment $appointment, PlateReading $reading, ?string $expected): ?PlateVerdict
    {
        $source = $reading->source === PlateReading::SOURCE_ANPR
            ? PlateVerdict::BY_ANPR
            : PlateVerdict::BY_STATION;

        // خواندنِ کارخانه‌ی دیگر اصلاً نباید به اینجا می‌رسید
        if ($reading->factory_id !== $appointment->factory_id) {
            return PlateVerdict::fail(
                $source,
                $expected,
                $reading->plate_key,
                'خواندنِ پلاک به این کارخانه تعلق ندارد.',
                $reading,
            );
        }

        // خواندنِ کهنه: پلاکِ درستِ دیروز نباید راهبند امروز را باز کند
        if (! $reading->isFresh()) {
            return PlateVerdict::fail(
                $source,
                $expected,
                $reading->plate_key,
                'خواندنِ پلاک قدیمی است. دوباره از پلاک عکس بگیرید.',
                $reading,
            );
        }

        if ($reading->isUnreadable()) {
            return null;
        }

        // اطمینان پایین یعنی «مطمئن نیستم»، نه «غلط است»
        if ($reading->source === PlateReading::SOURCE_ANPR
            && $reading->confidence !== null
            && $reading->confidence < $this->devices->minConfidence()) {
            return null;
        }

        return $reading->plate_key === $expected
            ? PlateVerdict::pass($source, $expected, $reading->plate_key, $reading)
            : PlateVerdict::fail(
                $source,
                $expected,
                $reading->plate_key,
                'پلاک خوانده‌شده با پلاک حواله یکی نیست. راهبند باز نمی‌شود؛ موضوع به حراست گزارش شد.',
                $reading,
            );
    }
}
