<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * وضعیت صف: چند نفر جلوتر از من هستند و تقریباً چقدر طول می‌کشد.
 */
final class QueueService
{
    /**
     * تعداد کامیون‌های جلوتر از این نوبت در صف امروز.
     *
     * «جلوتر» یعنی ساعت اسلات زودتر، یا همان ساعت با شماره‌ی نوبت کمتر — و
     * هنوز بارگیری‌شان تمام نشده باشد.
     */
    public function positionAhead(Appointment $appointment): ?int
    {
        // موقعیت در صف فقط برای نوبت امروز معنی دارد. برای نوبت فردا،
        // «۰ خودرو جلوتر از شما» حرف نادرستی به راننده می‌زند.
        if (! $appointment->status->isActive() || ! $appointment->date->isToday()) {
            return null;
        }

        $stillWaiting = [
            AppointmentStatus::Booked->value,
            AppointmentStatus::Waiting->value,
            AppointmentStatus::Called->value,
            AppointmentStatus::CheckedIn->value,
            AppointmentStatus::Loading->value,
        ];

        return Appointment::where('factory_id', $appointment->factory_id)
            ->whereDate('date', $appointment->date)
            ->whereIn('status', $stillWaiting)
            ->whereKeyNot($appointment->id)
            ->where(function ($query) use ($appointment) {
                $query->where('start_time', '<', $appointment->start_time)
                    ->orWhere(function ($q) use ($appointment) {
                        $q->where('start_time', $appointment->start_time)
                            ->where('number', '<', $appointment->number);
                    });
            })
            ->count();
    }

    /**
     * تخمین زمان انتظار به دقیقه.
     *
     * عمداً «تخمینی» است و در UI هم همین‌طور نوشته می‌شود: کارخانه ساعت اتمی
     * نیست و وعده‌ی دقیق دادن فقط اعتماد راننده را از بین می‌برد.
     */
    public function estimatedWaitMinutes(Appointment $appointment): ?int
    {
        if (! $appointment->status->isActive() || $appointment->status === AppointmentStatus::Loading) {
            return null;
        }

        $ahead = $this->positionAhead($appointment);

        if ($ahead === null) {
            return null;
        }

        $factory = $appointment->factory;
        $lines = max(1, (int) $factory->loading_lines);
        $perTruck = $this->averageLoadingMinutes($factory) ?? (int) $factory->avg_loading_minutes;

        $queueMinutes = (int) ceil(($ahead * $perTruck) / $lines);

        // اگر ساعت نوبت هنوز نرسیده، انتظار حداقل تا شروع اسلات است
        $untilSlot = (int) max(0, CarbonImmutable::now()->diffInMinutes($appointment->startsAt(), false));

        return max($queueMinutes, $untilSlot);
    }

    /** میانگین واقعی زمان بارگیری امروز — اگر داده‌ای نبود، null */
    public function averageLoadingMinutes(Factory $factory, ?CarbonImmutable $date = null): ?int
    {
        $date ??= CarbonImmutable::today();

        $average = Appointment::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('loading_started_at')
            ->whereNotNull('loading_completed_at')
            ->selectRaw('avg(extract(epoch from (loading_completed_at - loading_started_at)) / 60) as minutes')
            ->value('minutes');

        return $average !== null ? (int) round((float) $average) : null;
    }

    /** شمارنده‌های امروز برای داشبورد و پنل اپراتور */
    public function todayCounters(Factory $factory, ?CarbonImmutable $date = null): array
    {
        $date ??= CarbonImmutable::today();

        $rows = Appointment::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (AppointmentStatus ...$statuses) => (int) collect($statuses)
            ->sum(fn (AppointmentStatus $s) => (int) ($rows[$s->value] ?? 0));

        return [
            'total' => (int) $rows->sum(),
            'waiting' => $count(AppointmentStatus::Booked, AppointmentStatus::Waiting, AppointmentStatus::Called),
            'on_site' => $count(AppointmentStatus::CheckedIn),
            'loading' => $count(AppointmentStatus::Loading, AppointmentStatus::Loaded),
            'completed' => $count(AppointmentStatus::Completed),
            'cancelled' => $count(AppointmentStatus::Cancelled, AppointmentStatus::Rejected),
            'no_show' => $count(AppointmentStatus::NoShow),
        ];
    }

    /**
     * صف امروز به ترتیب طبیعی.
     *
     * @return Collection<int, Appointment>
     */
    public function todayQueue(Factory $factory, ?CarbonImmutable $date = null): Collection
    {
        $date ??= CarbonImmutable::today();

        return Appointment::with(['driver', 'truck.truckType', 'product', 'loadingPoint'])
            ->where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->queueOrder()
            ->get();
    }
}
