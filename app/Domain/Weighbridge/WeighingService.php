<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

use App\Models\Appointment;
use App\Support\Digits;

/**
 * حسابِ وزن خالص و قضاوت درباره‌اش.
 *
 * باسکول شناور است: وزن خالص هرچیزی است که باسکول می‌گوید — پر منهای خالی —
 * و سامانه عددی از خودش به آن تحمیل نمی‌کند.
 *
 * تا پیش از این، تناژِ محصول («هر بارگیریِ این محصول ۳۰ تن») یک الزام بود و
 * هر باری که به آن نمی‌رسید «مغایرت» می‌شد. ولی خاوری که نصفِ ظرفیتش بار
 * زده، هیچ اشکالی ندارد — بارِ نصفه یک انتخاب است، نه یک خطا. نتیجه‌ی آن
 * قانون این بود که هر بارگیریِ کوچک قفل می‌شد و اخطاری می‌رفت که کسی چند روز
 * بعد دیگر جدی‌اش نمی‌گرفت.
 *
 * آنچه می‌ماند یک سقف است، نه یک کف:
 *
 *   وزن خالص = پر − خالی            (هرگز دستی وارد نمی‌شود)
 *   پر که از خالی بیشتر نباشد ⇒ یکی از دو توزین اشتباه ثبت شده
 *   خالص بیشتر از ظرفیت کامیون ⇒ اضافه‌بار، برگه‌ی خروج صادر نمی‌شود
 */
final class WeighingService
{
    public function evaluate(Appointment $appointment, float $tareKg, float $grossKg): WeighingResult
    {
        $capacity = $this->capacityKg($appointment);

        if ($grossKg <= $tareKg) {
            return new WeighingResult(
                netKg: 0.0,
                capacityKg: $capacity,
                overloadKg: null,
                isOverload: false,
                blockReason: 'وزن پر از وزن خالی بیشتر نیست؛ یکی از دو توزین اشتباه ثبت شده است.',
            );
        }

        $net = round($grossKg - $tareKg, 2);

        // تنها مرزِ باقی‌مانده: ظرفیت قانونیِ خودِ کامیون. این یکی قابل
        // مذاکره نیست — کامیونِ اضافه‌بار حق خروج ندارد.
        if ($capacity !== null && $net > $capacity) {
            return new WeighingResult(
                netKg: $net,
                capacityKg: $capacity,
                overloadKg: round($net - $capacity, 2),
                isOverload: true,
                blockReason: sprintf(
                    'اضافه‌بار: وزن خالص %s کیلوگرم و ظرفیت مجاز این کامیون %s کیلوگرم است. برگه خروج صادر نمی‌شود.',
                    Digits::toPersian(number_format($net)),
                    Digits::toPersian(number_format($capacity)),
                ),
            );
        }

        return new WeighingResult($net, $capacity, null, false, null);
    }

    /**
     * ظرفیت مجاز این کامیون بر حسب کیلوگرم.
     *
     * تناژ با ماشین می‌آید، نه با محصول: خاور و تریلی یک محصول را می‌برند و
     * دو عددِ کاملاً متفاوت. کامیونی که نوعش تعریف نشده، سقفی هم ندارد.
     */
    public function capacityKg(Appointment $appointment): ?float
    {
        $tons = $appointment->truck?->truckType?->capacity_tons;

        return $tons !== null ? round((float) $tons * 1000, 2) : null;
    }
}
