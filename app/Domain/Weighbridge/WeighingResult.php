<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

/**
 * نتیجه‌ی تطبیق وزن خالص با حواله.
 */
final class WeighingResult
{
    public function __construct(
        public readonly float $netKg,
        public readonly ?float $expectedKg,
        public readonly ?float $varianceKg,
        public readonly bool $isOverload,
        public readonly bool $withinTolerance,
        public readonly ?string $blockReason,
    ) {}

    public function isClear(): bool
    {
        return $this->blockReason === null;
    }

    /**
     * وزن با حواله نخواند — چیزی که مدیر باید از آن خبردار شود.
     *
     * عمداً شاملِ «پر کمتر از خالی» نیست: آن یک عددِ اشتباه تایپ‌شده است
     * که اپراتور ده ثانیه بعد خودش درستش می‌کند، نه مغایرتِ بار. اگر برای
     * آن هم پیامک برود، چند روز بعد کسی دیگر این پیامک‌ها را نمی‌خواند.
     */
    public function hasDiscrepancy(): bool
    {
        return $this->isOverload || (! $this->withinTolerance && $this->expectedKg !== null);
    }

    /** «اضافه‌بار» یا «مغایرت وزن» — همان تفکیکی که در پنل هم دیده می‌شود */
    public function discrepancyLabel(): ?string
    {
        if (! $this->hasDiscrepancy()) {
            return null;
        }

        return $this->isOverload ? 'اضافه‌بار' : 'مغایرت وزن';
    }
}
