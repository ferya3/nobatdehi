<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Data;

use App\Models\AppointmentSlot;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;

final class NewAppointment
{
    public function __construct(
        public readonly Factory $factory,
        public readonly Driver $driver,
        public readonly Truck $truck,
        public readonly Product $product,
        public readonly AppointmentSlot $slot,
        public readonly ?string $idempotencyKey = null,
        public readonly ?int $createdByUserId = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $note = null,
    ) {}
}
