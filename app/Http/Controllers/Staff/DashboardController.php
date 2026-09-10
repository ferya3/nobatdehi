<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Queue\QueueService;
use App\Domain\Reporting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        // یک بار خوانده می‌شود و سه بخش از آن ساخته می‌شوند: صف، خطوط
        // بارگیری، و هشدارها. سه کوئری جدا برای یک نمای واحد، بی‌دلیل است.
        $queue = $canSeeQueue
            ? $this->queue->todayQueue($factory, $today)
            : collect();

        return Inertia::render('Staff/Dashboard', [
            'factoryId' => $factory->id,
            'jalaliDate' => Jalali::long($today),
            'counters' => $this->queue->todayCounters($factory, $today),
            'canSeeQueue' => $canSeeQueue,
            'queue' => $queue->map(fn (Appointment $a) => $this->queueRow($a))->values(),
            'lines' => $canSeeQueue ? $this->lines($factory, $queue) : [],
            'alerts' => $canSeeQueue ? $this->alerts($queue) : [],
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
     * وضعیت هر خط بارگیری، همین حالا.
     *
     * مهم‌ترین چیزی که مدیر با یک نگاه می‌خواهد بداند: کدام خط مشغول است،
     * با چه کامیونی، و چقدر از کارش گذشته. بدون این، باید ردیف‌های صف را
     * بخواند و خودش سرهم کند.
     *
     * @param  Collection<int, Appointment>  $queue
     * @return array<int, array<string, mixed>>
     */
    private function lines(Factory $factory, $queue): array
    {
        $busy = $queue
            ->filter(fn (Appointment $a) => $a->status === AppointmentStatus::Loading)
            ->keyBy('loading_point_id');

        return LoadingPoint::where('factory_id', $factory->id)
            ->active()
            ->orderBy('code')
            ->get()
            ->map(function (LoadingPoint $point) use ($busy) {
                /** @var Appointment|null $on */
                $on = $busy->get($point->id);

                if ($on === null) {
                    return ['id' => $point->id, 'name' => $point->name, 'busy' => false];
                }

                $expected = $on->expectedLoadingMinutes();
                $elapsed = (int) $on->loading_started_at?->diffInMinutes(now());

                return [
                    'id' => $point->id,
                    'name' => $point->name,
                    'busy' => true,
                    'number' => $on->number,
                    'plate' => $on->truck?->plate(),
                    'driver' => $on->driver?->name,
                    'truck_type' => $on->truck?->truckType?->name,
                    'product' => $on->product?->name,
                    'elapsed_minutes' => $elapsed,
                    'expected_minutes' => $expected,
                    // درصد عمداً روی ۱۰۰ سقف می‌خورد: نوار پیشرفتی که از کادر
                    // بیرون بزند، هم زشت است هم چیزی اضافه نمی‌گوید
                    'percent' => $expected > 0 ? min(100, (int) round($elapsed / $expected * 100)) : 0,
                    'is_late' => $on->isLoadingLate(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * چیزهایی که همین حالا رسیدگی می‌خواهند.
     *
     * داشبوردی که فقط عدد نشان دهد، خبر بد را قایم می‌کند. دو چیز واقعاً
     * فوری‌اند: بارگیری‌ای که طول کشیده، و راننده‌ای که مهلت حضورش تمام شده
     * و هنوز نیامده.
     *
     * @param  Collection<int, Appointment>  $queue
     * @return array<int, array<string, mixed>>
     */
    private function alerts($queue): array
    {
        $alerts = [];

        foreach ($queue as $appointment) {
            $record = $appointment->loadingRecord;

            // مغایرت وزن، جدی‌ترین چیزی است که در محوطه می‌گذرد: کامیون
            // بارگیری شده، برگه‌ی خروج نگرفته، و تا تعیین تکلیف همان‌جا
            // ایستاده. بخشِ هشدارها بود و این را نمی‌گفت.
            if ($record?->discrepancy_kind !== null && $record?->exit_permit_number === null) {
                $alerts[] = [
                    'ulid' => $appointment->ulid,
                    'kind' => 'weight_discrepancy',
                    'number' => $appointment->number,
                    'plate' => $appointment->truck?->plate(),
                    'text' => ($record->discrepancy_kind === 'overload' ? 'اضافه‌بار' : 'مغایرت وزن')
                        .' — برگه خروج صادر نشده و کامیون در محوطه مانده است.',
                ];

                continue;
            }

            if ($appointment->isLoadingLate()) {
                $alerts[] = [
                    'ulid' => $appointment->ulid,
                    'kind' => 'late_loading',
                    'number' => $appointment->number,
                    'plate' => $appointment->truck?->plate(),
                    'text' => 'بارگیری از زمان معمولِ این کامیون گذشته است.',
                ];

                continue;
            }

            $waiting = in_array($appointment->status, [
                AppointmentStatus::Booked,
                AppointmentStatus::Waiting,
                AppointmentStatus::Called,
            ], true);

            if ($waiting && $appointment->noShowDueAt()->isPast()) {
                $alerts[] = [
                    'ulid' => $appointment->ulid,
                    'kind' => 'overdue',
                    'number' => $appointment->number,
                    'plate' => $appointment->truck?->plate(),
                    'text' => 'مهلت حضور تمام شده و راننده هنوز وارد نشده.',
                ];
            }
        }

        return $alerts;
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
