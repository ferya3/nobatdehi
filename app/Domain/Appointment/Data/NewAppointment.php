<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Data;

use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;
use Carbon\CarbonImmutable;

/**
 * درخواست نوبت.
 *
 * پیش‌فرض همان است که بود: راننده ساعت نمی‌چیند و زمان‌بند زودترین جای خالی
 * را اعلام می‌کند.
 *
 * ولی راننده‌ای که روزِ دیگری کار دارد، می‌تواند بگوید «فلان روز، حوالی
 * فلان ساعت». آن‌وقت هم ساعت را خودش نمی‌نویسد: این یک *کف* است، نه یک
 * زمان. زمان‌بند اولین جای خالیِ آن روز از آن ساعت به بعد را می‌دهد و اگر
 * جایی نباشد، درخواست رد می‌شود — نه اینکه نوبتی روی ساعتی بنشیند که لاین
 * آن لحظه پر است.
 *
 * روزِ خواسته‌شده هرگز امروز نیست. امروز مالِ صف است و صف را سامانه می‌چیند.
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
        /** روز و ساعتی که راننده خواسته — یا null یعنی «زودترین ممکن» */
        public readonly ?CarbonImmutable $preferredStart = null,
    ) {}
}
