<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

use App\Models\Appointment;
use App\Models\Factory;
use App\Support\Digits;

/**
 * حسابِ وزن خالص و قضاوت درباره‌اش.
 *
 * سه قانونِ مرحله‌ی ۵، همگی اینجا و فقط اینجا:
 *
 *   وزن خالص = پر − خالی   (هرگز دستی وارد نمی‌شود)
 *   اگر خالص از ظرفیت کامیون بیشتر بود ⇒ قفل، برگه‌ی خروج صادر نمی‌شود
 *   اگر خالص با تناژ حواله بیشتر از حد مجاز فرق داشت ⇒ قفل تا تعیین تکلیف
 */
final class WeighingService
{
    public function evaluate(Appointment $appointment, float $tareKg, float $grossKg): WeighingResult
    {
        /** @var Factory $factory */
        $factory = $appointment->factory;

        if ($grossKg <= $tareKg) {
            return new WeighingResult(
                netKg: 0.0,
                expectedKg: null,
                varianceKg: null,
                isOverload: false,
                withinTolerance: false,
                blockReason: 'وزن پر از وزن خالی بیشتر نیست؛ یکی از دو توزین اشتباه ثبت شده است.',
            );
        }

        $net = round($grossKg - $tareKg, 2);

        $capacityKg = $this->capacityKg($appointment);
        $expected = $this->expectedNetKg($appointment);

        // اضافه‌بار روی ظرفیت قانونی کامیون سنجیده می‌شود، نه روی حواله
        if ($capacityKg !== null && $net > $capacityKg) {
            return new WeighingResult(
                netKg: $net,
                expectedKg: $expected,
                varianceKg: $expected !== null ? round($net - $expected, 2) : null,
                isOverload: true,
                withinTolerance: false,
                blockReason: sprintf(
                    'اضافه‌بار: وزن خالص %s کیلوگرم و ظرفیت مجاز این کامیون %s کیلوگرم است. برگه خروج صادر نمی‌شود.',
                    Digits::toPersian(number_format($net)),
                    Digits::toPersian(number_format($capacityKg)),
                ),
            );
        }

        if ($expected === null) {
            // محصول تناژ تعریف‌شده ندارد؛ چیزی برای مقایسه نیست
            return new WeighingResult($net, null, null, false, true, null);
        }

        $variance = round($net - $expected, 2);
        $allowed = $expected * ((float) $factory->weight_tolerance_percent / 100);
        $within = abs($variance) <= $allowed;

        return new WeighingResult(
            netKg: $net,
            expectedKg: $expected,
            varianceKg: $variance,
            isOverload: false,
            withinTolerance: $within,
            blockReason: $within ? null : sprintf(
                'مغایرت وزن: خالص %s کیلوگرم در برابر %s کیلوگرمِ حواله (اختلاف %s کیلوگرم). برگه خروج تا تعیین تکلیف صادر نمی‌شود.',
                Digits::toPersian(number_format($net)),
                Digits::toPersian(number_format($expected)),
                Digits::toPersian(number_format(abs($variance))),
            ),
        );
    }

    /**
     * تناژی که از این کامیون انتظار می‌رود، بر حسب کیلوگرم.
     *
     * تناژ روی محصول تعریف می‌شود («هر بارگیریِ این محصول ۳۰ تن») ولی
     * کامیون است که آن را می‌برد. یک تکِ ده‌تنی هرگز به سی تن نمی‌رسد؛
     * سنجیدنش با عددِ محصول یعنی هر بارگیریِ تک، «مغایرت» می‌شود و چند روز
     * بعد کسی دیگر این هشدار را جدی نمی‌گیرد.
     *
     * محصولی که تناژ تعریف‌شده ندارد، عمداً بی‌مقایسه می‌ماند: کارخانه
     * نگفته چقدر انتظار دارد، پس سامانه هم از خودش عددی نمی‌سازد.
     * اضافه‌بار جدا و همیشه سنجیده می‌شود.
     */
    public function expectedNetKg(Appointment $appointment): ?float
    {
        $tons = $appointment->product?->load_tons;

        if ($tons === null) {
            return null;
        }

        $expected = round((float) $tons * 1000, 2);
        $capacity = $this->capacityKg($appointment);

        return $capacity === null ? $expected : min($expected, $capacity);
    }

    /** ظرفیت مجاز کامیون بر حسب کیلوگرم */
    public function capacityKg(Appointment $appointment): ?float
    {
        $tons = $appointment->truck?->truckType?->capacity_tons;

        return $tons !== null ? round((float) $tons * 1000, 2) : null;
    }
}
