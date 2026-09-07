<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use App\Models\Factory;
use App\Models\TruckType;
use Illuminate\Support\Collection;

/**
 * «اگر همین حالا نوبت بگیرم، چه ساعتی می‌افتد؟»
 *
 * راننده پیش از تأیید باید بداند چه چیزی به او داده می‌شود. این نمایش است
 * و نه انتخاب: تغییرش فقط با عوض‌کردن نوع خودرو ممکن است، چون مدت بارگیریِ
 * تریلی و خاور فرق دارد و همان مدت جای نوبت را تعیین می‌کند.
 *
 * عددها تخمینی‌اند تا لحظه‌ی ثبت: بین دیدن و زدن دکمه، ممکن است کسی دیگر
 * نوبت گرفته باشد. زمان‌بند داخل قفلِ روز دوباره حساب می‌کند و همان عدد
 * قطعی است.
 */
final class OpeningPreview
{
    public function __construct(private readonly AppointmentScheduler $scheduler) {}

    /**
     * برای هر نوع خودروی فعال، اولین جای خالی.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forTruckTypes(Factory $factory, Collection $truckTypes): Collection
    {
        $fallback = (int) $factory->avg_loading_minutes;

        return $truckTypes->map(function (TruckType $type) use ($factory, $fallback) {
            $minutes = $type->loading_minutes ?? $fallback;
            $opening = $this->scheduler->nextOpening($factory, $minutes);

            return [
                'id' => $type->id,
                'name' => $type->name,
                'capacity_tons' => $type->capacity_tons,
                'loading_minutes' => $minutes,
                'opening' => $opening?->toArray(),
            ];
        })->values();
    }
}
