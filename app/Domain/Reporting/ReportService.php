<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * گزارش‌ها همه از appointment_transitions و ستون‌های زمانی نوبت می‌آیند،
 * نه از تخمین دستی. برای همین بود که هر انتقال ستون زمان خودش را دارد.
 */
final class ReportService
{
    /** خلاصه‌ی یک بازه */
    public function summary(Factory $factory, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = $this->scope($factory, $from, $to);

        $total = (clone $base)->count();
        $completed = (clone $base)->where('status', AppointmentStatus::Completed->value)->count();
        $noShow = (clone $base)->where('status', AppointmentStatus::NoShow->value)->count();
        $cancelled = (clone $base)->whereIn('status', [
            AppointmentStatus::Cancelled->value,
            AppointmentStatus::Rejected->value,
        ])->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'no_show' => $noShow,
            'cancelled' => $cancelled,
            // درصد عدم حضور نسبت به نوبت‌هایی که قرار بود اتفاق بیفتند
            'no_show_rate' => $total > 0 ? round($noShow / $total * 100, 1) : 0.0,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
            'avg_wait_minutes' => $this->averageMinutes($factory, $from, $to, 'checked_in_at', 'loading_started_at'),
            'avg_loading_minutes' => $this->averageMinutes($factory, $from, $to, 'loading_started_at', 'loading_completed_at'),
            'avg_on_site_minutes' => $this->averageMinutes($factory, $from, $to, 'checked_in_at', 'completed_at'),
        ];
    }

    /** تعداد نوبت به تفکیک روز */
    public function daily(Factory $factory, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->scope($factory, $from, $to)
            ->selectRaw('date, count(*) as total')
            ->selectRaw('count(*) filter (where status = ?) as completed', [AppointmentStatus::Completed->value])
            ->selectRaw('count(*) filter (where status = ?) as no_show', [AppointmentStatus::NoShow->value])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => CarbonImmutable::parse($row->date)->toDateString(),
                'jalali' => Jalali::date($row->date),
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'no_show' => (int) $row->no_show,
            ]);
    }

    /** شلوغ‌ترین ساعات */
    public function byHour(Factory $factory, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->scope($factory, $from, $to)
            ->selectRaw("to_char(start_time, 'HH24:00') as hour, count(*) as total")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn ($row) => ['hour' => $row->hour, 'total' => (int) $row->total]);
    }

    /** تفکیک محصول و تناژ */
    public function byProduct(Factory $factory, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        // تناژ از باسکول خوانده می‌شود، نه از حواله.
        //
        // پیش از این جمعِ load_tons گزارش می‌شد — یعنی «چقدر قرار بود برود»،
        // نه «چقدر رفت». کارخانه‌ای که باسکول دارد و در گزارشش عددِ حواله را
        // می‌بیند، دقیقاً همان اختلافی را نمی‌بیند که باسکول برای دیدنش
        // نصب شده است.
        //
        // coalesce برای حواله‌های قدیمیِ پیش از باسکول است؛ تکمیل‌شده‌ی امروز
        // بدون توزین وجود ندارد، چون برگه‌ی خروج بدون وزن پر صادر نمی‌شود.
        $completed = AppointmentStatus::Completed->value;

        return $this->scope($factory, $from, $to)
            ->join('products', 'products.id', '=', 'appointments.product_id')
            ->leftJoin('loading_records', 'loading_records.appointment_id', '=', 'appointments.id')
            ->selectRaw('products.name as name, count(*) as total')
            ->selectRaw('count(*) filter (where appointments.status = ?) as completed', [$completed])
            ->selectRaw(
                'coalesce(sum(coalesce(loading_records.net_weight_kg / 1000.0, products.load_tons))'
                .' filter (where appointments.status = ?), 0) as tons',
                [$completed],
            )
            ->groupBy('products.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'total' => (int) $row->total,
                'completed' => (int) $row->completed,
                'tons' => round((float) $row->tons, 2),
            ]);
    }

    /** عملکرد اپراتورها بر اساس انتقال‌های ثبت‌شده */
    public function byOperator(Factory $factory, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return DB::table('appointment_transitions')
            ->join('appointments', 'appointments.id', '=', 'appointment_transitions.appointment_id')
            ->join('users', 'users.id', '=', 'appointment_transitions.user_id')
            ->where('appointments.factory_id', $factory->id)
            ->whereBetween('appointments.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('users.name as name, count(*) as actions')
            ->selectRaw('count(*) filter (where appointment_transitions.to_status = ?) as completed', [AppointmentStatus::Completed->value])
            ->selectRaw('count(*) filter (where appointment_transitions.is_rollback) as rollbacks')
            ->groupBy('users.name')
            ->orderByDesc('actions')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'actions' => (int) $row->actions,
                'completed' => (int) $row->completed,
                'rollbacks' => (int) $row->rollbacks,
            ]);
    }

    /**
     * میانگین فاصله‌ی دو زمان به دقیقه.
     * فقط ردیف‌هایی که هر دو زمان را دارند شمرده می‌شوند.
     */
    private function averageMinutes(
        Factory $factory,
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $start,
        string $end,
    ): ?int {
        $average = $this->scope($factory, $from, $to)
            ->whereNotNull($start)
            ->whereNotNull($end)
            ->selectRaw("avg(extract(epoch from ({$end} - {$start})) / 60) as minutes")
            ->value('minutes');

        return $average !== null ? (int) round((float) $average) : null;
    }

    private function scope(Factory $factory, CarbonImmutable $from, CarbonImmutable $to)
    {
        return Appointment::query()
            ->where('appointments.factory_id', $factory->id)
            ->whereBetween('appointments.date', [$from->toDateString(), $to->toDateString()]);
    }
}
