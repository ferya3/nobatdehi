<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

        return $this->aheadQuery($appointment)->count();
    }

    /**
     * همان مجموعه‌ی «جلوتر»ها — ستون‌ها عمداً کامل نوشته شده‌اند چون
     * minutesAhead() روی همین کوئری join می‌زند.
     */
    private function aheadQuery(Appointment $appointment): Builder
    {
        $stillWaiting = [
            AppointmentStatus::Booked->value,
            AppointmentStatus::Waiting->value,
            AppointmentStatus::Called->value,
            AppointmentStatus::CheckedIn->value,
            AppointmentStatus::Loading->value,
        ];

        return Appointment::where('appointments.factory_id', $appointment->factory_id)
            ->whereDate('appointments.date', $appointment->date)
            ->whereIn('appointments.status', $stillWaiting)
            ->whereKeyNot($appointment->id)
            ->where(function ($query) use ($appointment) {
                $query->where('appointments.start_time', '<', $appointment->start_time)
                    ->orWhere(function ($q) use ($appointment) {
                        $q->where('appointments.start_time', $appointment->start_time)
                            ->where('appointments.number', '<', $appointment->number);
                    });
            });
    }

    /**
     * مجموع مدت بارگیریِ مورد انتظارِ کامیون‌های جلوتر.
     *
     * COALESCE همان زنجیره‌ی Appointment::expectedLoadingMinutes() است، فقط
     * در SQL: یک تریلی و یک خاور نباید در تخمین صف یک وزن داشته باشند.
     */
    public function minutesAhead(Appointment $appointment, int $fallback): int
    {
        $minutes = $this->aheadQuery($appointment)
            ->join('trucks', 'trucks.id', '=', 'appointments.truck_id')
            ->leftJoin('truck_types', 'truck_types.id', '=', 'trucks.truck_type_id')
            ->join('products', 'products.id', '=', 'appointments.product_id')
            ->selectRaw(
                'coalesce(sum(coalesce(truck_types.loading_minutes, products.loading_minutes, ?)), 0) as minutes',
                [$fallback],
            )
            ->value('minutes');

        return (int) round((float) $minutes);
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

        // میانگین واقعیِ امروز فقط جایی به کار می‌آید که نه نوع کامیون و نه
        // محصول، مدت بارگیری تعریف‌شده نداشته باشند.
        $fallback = $this->averageLoadingMinutes($factory) ?? (int) $factory->avg_loading_minutes;

        $queueMinutes = (int) ceil($this->minutesAhead($appointment, $fallback) / $lines);

        // اگر ساعت نوبت هنوز نرسیده، انتظار حداقل تا شروع اسلات است
        $untilSlot = (int) max(0, CarbonImmutable::now()->diffInMinutes($appointment->startsAt(), false));

        return max($queueMinutes, $untilSlot);
    }

    /**
     * برنامه‌ی زمانیِ تخمینی یک نوبت — همان چیزی که به راننده اعلام می‌شود.
     *
     * برخلاف estimatedWaitMinutes() که فقط برای امروز معنی دارد، این برای
     * نوبت فردا هم کار می‌کند: راننده در لحظه‌ی گرفتن نوبت باید بداند حدوداً
     * چه ساعتی بارگیری‌اش تمام می‌شود، نه اینکه صبح روز بعد بفهمد.
     *
     * ساعت‌ها روی سرور به «H:i» تبدیل می‌شوند و نه به‌صورت ISO فرستاده
     * می‌شوند: مرورگر راننده ISO را با منطقه‌ی زمانی *خودش* رندر می‌کند و
     * گوشی‌ای که روی UTC مانده، ۰۷:۰۰ را ۰۳:۳۰ نشان می‌دهد.
     *
     * @return array{loading_minutes:int, queue_minutes:int, starts_at:string, ends_at:string}
     */
    public function plannedSchedule(Appointment $appointment): array
    {
        $appointment->loadMissing(['factory', 'product', 'truck.truckType']);

        $factory = $appointment->factory;
        $lines = max(1, (int) $factory->loading_lines);
        $fallback = (int) $factory->avg_loading_minutes;

        $loadingMinutes = $appointment->expectedLoadingMinutes();
        $queueMinutes = (int) ceil($this->minutesAhead($appointment, $fallback) / $lines);

        $startsAt = $appointment->startsAt()->addMinutes($queueMinutes);

        return [
            'loading_minutes' => $loadingMinutes,
            'queue_minutes' => $queueMinutes,
            'starts_at' => $startsAt->format('H:i'),
            'ends_at' => $startsAt->addMinutes($loadingMinutes)->format('H:i'),
        ];
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
