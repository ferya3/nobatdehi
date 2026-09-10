<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * وزنِ خالص با حواله نخواند — یا اضافه‌بار، یا بیرونِ درصد مجاز.
 *
 * فقط شناسه حمل می‌شود، نه مدل: Listenerهای صف‌شده باید آخرین وضعیت را از
 * دیتابیس بخوانند. «kind» هم می‌آید چون تصمیمش قبلاً در WeighingService
 * گرفته شده و دوباره حساب کردنش یعنی دو روایت از یک اتفاق.
 */
class WeightDiscrepancyDetected
{
    use Dispatchable, SerializesModels;

    public const KIND_OVERLOAD = 'overload';

    public const KIND_VARIANCE = 'variance';

    public function __construct(
        public readonly int $appointmentId,
        public readonly string $kind,
        public readonly float $netKg,
        public readonly ?float $expectedKg,
        public readonly ?float $varianceKg,
        public readonly string $reason,
    ) {}
}
