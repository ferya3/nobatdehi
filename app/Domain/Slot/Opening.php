<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use App\Support\Jalali;
use Carbon\CarbonImmutable;

/**
 * جایی که سامانه برای یک کامیون در صف باز کرده.
 *
 * راننده این را انتخاب نمی‌کند؛ می‌بیند. تفاوت همان چیزی است که «نوبت
 * کارخانه» را از «نوبت مطب» جدا می‌کند.
 */
final class Opening
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly int $line,
        public readonly int $loadingMinutes,
    ) {}

    public function startTime(): string
    {
        return $this->startsAt->format('H:i:s');
    }

    public function endTime(): string
    {
        return $this->endsAt->format('H:i:s');
    }

    /** @return array<string, mixed> نمایش برای راننده */
    public function toArray(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'jalali' => Jalali::date($this->date),
            'jalali_long' => Jalali::long($this->date),
            'day_label' => Jalali::dayLabel($this->date),
            'is_today' => $this->date->isToday(),
            'starts_at' => $this->startsAt->format('H:i'),
            'ends_at' => $this->endsAt->format('H:i'),
            'loading_minutes' => $this->loadingMinutes,
        ];
    }
}
