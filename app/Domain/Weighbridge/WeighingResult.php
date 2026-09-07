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
}
