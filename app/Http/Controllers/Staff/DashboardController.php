<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Queue\QueueService;
use App\Domain\Reporting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Factory;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly ReportService $reports,
    ) {}

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->can(Permissions::DASHBOARD_VIEW), 403);

        $factory = $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();

        $today = CarbonImmutable::today();
        $weekAgo = $today->subDays(6);

        // صف امروز روی خودِ داشبورد: مدیر برای دیدن اینکه الان چه خبر است
        // نباید به صفحه‌ی دیگری برود. مدیرعامل عمداً QUEUE_VIEW ندارد، پس
        // برای او این بخش اصلاً ساخته نمی‌شود.
        $canSeeQueue = $request->user()->can(Permissions::QUEUE_VIEW);

        return Inertia::render('Staff/Dashboard', [
            'factoryId' => $factory->id,
            'jalaliDate' => Jalali::long($today),
            'counters' => $this->queue->todayCounters($factory, $today),
            'canSeeQueue' => $canSeeQueue,
            'queue' => $canSeeQueue
                ? $this->queue->todayQueue($factory, $today)
                    ->map(fn (Appointment $a) => $this->queueRow($a))
                    ->values()
                : [],
            'upcomingDays' => $canSeeQueue ? $this->queue->upcomingDays($factory, $today) : [],
            'avgLoadingMinutes' => $this->queue->averageLoadingMinutes($factory, $today)
                ?? $factory->avg_loading_minutes,
            'week' => [
                'summary' => $this->reports->summary($factory, $weekAgo, $today),
                'daily' => $this->reports->daily($factory, $weekAgo, $today),
                'byProduct' => $this->reports->byProduct($factory, $weekAgo, $today),
            ],
        ]);
    }

    /**
     * ردیفِ فقط-خواندنیِ صف.
     *
     * داشبورد جای عمل نیست؛ دکمه‌ی انتقال وضعیت ندارد. همان چند ستونی که
     * با یک نگاه می‌گوید کدام کامیون کجاست.
     *
     * @return array<string, mixed>
     */
    private function queueRow(Appointment $appointment): array
    {
        return [
            'ulid' => $appointment->ulid,
            'number' => $appointment->number,
            'time' => substr((string) $appointment->start_time, 0, 5),
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'status_tone' => $appointment->status->tone(),
            'is_active' => $appointment->status->isActive(),
            'plate' => $appointment->truck?->plate(),
            'truck_type' => $appointment->truck?->truckType?->name,
            'driver' => $appointment->driver?->name,
            'product' => $appointment->product?->name,
            'loading_point' => $appointment->loadingPoint?->name,
            'wait_minutes' => $appointment->waitMinutes(),
        ];
    }
}
