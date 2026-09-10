<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

/**
 * نتیجه‌ی یک توزین.
 *
 * عددِ خالص از باسکول می‌آید و ظرفیت از نوع کامیون. چیزی به نام «تناژ
 * موردانتظار» اینجا نیست: باری که کمتر از ظرفیت باشد کاملاً درست است.
 */
final class WeighingResult
{
    public function __construct(
        public readonly float $netKg,
        /** سقفِ مجازِ این کامیون، یا null اگر نوعش تعریف نشده */
        public readonly ?float $capacityKg,
        /** چقدر بیشتر از ظرفیت — فقط وقتی اضافه‌بار باشد */
        public readonly ?float $overloadKg,
        public readonly bool $isOverload,
        public readonly ?string $blockReason,
    ) {}

    public function isClear(): bool
    {
        return $this->blockReason === null;
    }

    /**
     * چیزی که مدیر باید از آن خبردار شود.
     *
     * «پر کمتر از خالی» عمداً اینجا نیست: آن یک عددِ اشتباه تایپ‌شده است که
     * اپراتور ده ثانیه بعد خودش درستش می‌کند، نه ایرادِ بار.
     */
    public function hasDiscrepancy(): bool
    {
        return $this->isOverload;
    }

    public function discrepancyLabel(): ?string
    {
        return $this->isOverload ? 'اضافه‌بار' : null;
    }
}
