<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Factory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use App\Support\Jalali;

/**
 * چه روزها و چه ساعت‌هایی برای راننده قابل انتخاب‌اند.
 */
final class AvailabilityService
{
    public function __construct(private readonly SlotGenerator $generator) {}

    /**
     * روزهای قابل انتخاب در افق نوبت‌دهی، با تعداد جای خالی هر روز.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function days(Factory $factory): Collection
    {
        $today = CarbonImmutable::today();
        $last = $today->addDays($factory->booking_horizon_days);

        $slots = AppointmentSlot::where('factory_id', $factory->id)
            ->whereBetween('date', [$today->toDateString(), $last->toDateString()])
            ->get()
            ->groupBy(fn (AppointmentSlot $slot) => $slot->date->toDateString());

        $bookedPerDay = Appointment::where('factory_id', $factory->id)
            ->whereBetween('date', [$today->toDateString(), $last->toDateString()])
            ->whereIn('status', AppointmentStatus::activeValues())
            ->selectRaw('date, count(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

        $days = collect();

        for ($offset = 0; $offset <= $factory->booking_horizon_days; $offset++) {
            $date = $today->addDays($offset);
            $key = $date->toDateString();

            /** @var Collection<int, AppointmentSlot> $daySlots */
            $daySlots = $slots->get($key, collect());

            $bookable = $daySlots->filter(fn (AppointmentSlot $slot) => $this->isSelectable($factory, $slot));
            $dayBooked = (int) ($bookedPerDay[$key] ?? 0);
            $dayRemaining = max(0, $factory->daily_capacity - $dayBooked);

            $days->push([
                'date' => $key,
                'jalali' => Jalali::date($date),
                'jalali_label' => Jalali::dayLabel($date),
                'is_today' => $offset === 0,
                'is_open' => $daySlots->isNotEmpty(),
                'remaining' => min((int) $bookable->sum(fn (AppointmentSlot $s) => $s->remaining()), $dayRemaining),
            ]);
        }

        return $days;
    }

    /**
     * اسلات‌های یک روز با وضعیت ظرفیت.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function slotsForDate(Factory $factory, CarbonImmutable $date): Collection
    {
        $slots = AppointmentSlot::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->orderBy('start_time')
            ->get();

        if ($slots->isEmpty()) {
            $slots = $this->generator->generateForDate($factory, $date)->sortBy('start_time')->values();
        }

        return $slots->map(fn (AppointmentSlot $slot) => [
            'id' => $slot->id,
            'start_time' => substr((string) $slot->start_time, 0, 5),
            'end_time' => substr((string) $slot->end_time, 0, 5),
            'capacity' => $slot->capacity,
            'reserved' => $slot->reserved_count,
            'remaining' => $slot->remaining(),
            'selectable' => $this->isSelectable($factory, $slot),
        ])->values();
    }

    /** آیا این اسلات همین الان برای راننده قابل انتخاب است؟ */
    public function isSelectable(Factory $factory, AppointmentSlot $slot): bool
    {
        if (! $slot->isBookable()) {
            return false;
        }

        return $slot->startsAt()->greaterThanOrEqualTo(
            CarbonImmutable::now()->addMinutes($factory->booking_lead_minutes)
        );
    }
}
