<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use App\Models\AppointmentSlot;
use App\Models\CalendarException;
use App\Models\Factory;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * اسلات‌های ظرفیت را از روی ساعات کاری کارخانه می‌سازد.
 *
 * تولید idempotent است: اجرای دوباره برای یک روز، اسلات تکراری نمی‌سازد و
 * ظرفیت رزروشده را هم دست نمی‌زند. فقط ظرفیت خالی را با تنظیمات جدید هماهنگ
 * می‌کند و هرگز ظرفیت را زیر تعداد رزروشده نمی‌آورد.
 */
final class SlotGenerator
{
    /**
     * اسلات‌های یک روز مشخص را می‌سازد یا به‌روزرسانی می‌کند.
     *
     * @return Collection<int, AppointmentSlot>
     */
    public function generateForDate(Factory $factory, CarbonImmutable $date): Collection
    {
        $plan = $this->planFor($factory, $date);

        if ($plan === null) {
            return collect();
        }

        [$opensAt, $closesAt, $capacity] = $plan;

        return DB::transaction(function () use ($factory, $date, $opensAt, $closesAt, $capacity) {
            $slots = collect();
            $cursor = $opensAt;
            $step = max(5, $factory->slot_minutes);

            while ($cursor->lessThan($closesAt)) {
                $end = $cursor->addMinutes($step);

                if ($end->greaterThan($closesAt)) {
                    break;
                }

                $slot = AppointmentSlot::firstOrNew([
                    'factory_id' => $factory->id,
                    'date' => $date->toDateString(),
                    'start_time' => $cursor->format('H:i:s'),
                ]);

                $slot->end_time = $end->format('H:i:s');

                // ظرفیت هرگز زیر تعداد رزروشده نمی‌رود؛ در غیر این صورت
                // CHECK دیتابیس درست عمل می‌کرد ولی خطا سر جای اشتباه می‌خورد.
                $slot->capacity = max($capacity, $slot->reserved_count ?? 0);

                if (! $slot->exists) {
                    $slot->reserved_count = 0;
                    $slot->is_blocked = false;
                }

                $slot->save();
                $slots->push($slot);

                $cursor = $end;
            }

            return $slots;
        });
    }

    /**
     * اسلات‌های افق نوبت‌دهی (از امروز تا booking_horizon_days).
     *
     * @return Collection<int, AppointmentSlot>
     */
    public function generateHorizon(Factory $factory, ?CarbonImmutable $from = null): Collection
    {
        $from ??= CarbonImmutable::today();
        $slots = collect();

        for ($offset = 0; $offset <= $factory->booking_horizon_days; $offset++) {
            $slots = $slots->merge($this->generateForDate($factory, $from->addDays($offset)));
        }

        return $slots;
    }

    /**
     * برنامه‌ی کاری یک روز: [شروع, پایان, ظرفیت هر اسلات] یا null اگر تعطیل باشد.
     *
     * استثنای تقویم بر ساعات کاری هفتگی اولویت دارد.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}|null
     */
    public function planFor(Factory $factory, CarbonImmutable $date): ?array
    {
        $exception = CalendarException::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->first();

        if ($exception?->is_closed) {
            return null;
        }

        $working = WorkingHour::where('factory_id', $factory->id)
            ->where('weekday', self::weekdayFor($date))
            ->first();

        if ($exception === null && ! $working?->is_open) {
            return null;
        }

        $opensAt = $exception?->opens_at ?? $working?->opens_at ?? '07:00:00';
        $closesAt = $exception?->closes_at ?? $working?->closes_at ?? '18:00:00';
        $capacity = $exception?->capacity_per_slot ?? $working?->capacity_per_slot ?? 5;

        $start = $this->at($date, (string) $opensAt);
        $end = $this->at($date, (string) $closesAt);

        if (! $end->greaterThan($start) || $capacity < 1) {
            return null;
        }

        return [$start, $end, (int) $capacity];
    }

    /**
     * شماره‌ی روز هفته در تقویم ایران: ۰ = شنبه ... ۶ = جمعه.
     * Carbon شنبه را ۶ می‌داند، پس نگاشت می‌کنیم.
     */
    public static function weekdayFor(CarbonImmutable $date): int
    {
        return ((int) $date->dayOfWeek + 1) % 7;
    }

    private function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.substr($time, 0, 5));
    }
}
