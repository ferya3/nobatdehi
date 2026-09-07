<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Data;

use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;

/**
 * درخواست نوبت — بدون ساعت.
 *
 * ساعت عمداً اینجا نیست: راننده آن را انتخاب نمی‌کند و کسی هم نمی‌تواند در
 * درخواست تحمیلش کند. زمان‌بند داخل قفلِ روز حسابش می‌کند.
 */
final class NewAppointment
{
    public function __construct(
        public readonly Factory $factory,
        public readonly Driver $driver,
        public readonly Truck $truck,
        public readonly Product $product,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $createdByUserId = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $note = null,
    ) {}
}
