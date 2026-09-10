<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Gate\GateDevices;
use App\Domain\Gate\ScanTicket;
use App\Domain\Queue\QueueService;
use App\Domain\Truck\PlateNumber;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * یک صفحه به‌جای پنج تا.
 *
 * ایستگاه‌ها از هم جدا بودند چون آدم‌هایشان جدا بودند: نگهبان دمِ راهبند،
 * باسکول‌بان در اتاقک، مسئول لاین سرِ سیلو. ولی در کارخانه‌ای که یک نفر هر
 * سه کار را می‌کند، این جدایی فقط یعنی گشتن در منو.
 *
 * پس ترتیب عوض می‌شود: به‌جای «کدام ایستگاه؟ بعد کدام کامیون؟»، اول کامیون
 * انتخاب می‌شود و کنسول خودش می‌گوید کارِ بعدی‌اش چیست. سامانه از قبل
 * می‌داند هر کامیون کجای مسیر است — همان چیزی که ترتیب مرحله‌ها را نگه
 * می‌دارد — پس پرسیدنش از کاربر کارِ اضافه بود.
 *
 * هیچ اختیارِ تازه‌ای اینجا نیست: هر کار همان مسیرِ همیشگی‌اش را صدا می‌زند
 * و همان دسترسی را می‌خواهد. کاربری که دسترسی باسکول ندارد، بخشِ وزن را
 * اصلاً نمی‌بیند.
 */
class ConsoleController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly GateDevices $devices,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeConsole($request);

        $factory = $this->factory($request);
        $today = CarbonImmutable::today();

        $rows = $this->queue->todayQueue($factory, $today);
        $selected = $this->selected($request, $factory);

        return Inertia::render('Staff/Console', [
            'factoryId' => $factory->id,
            'jalaliDate' => Jalali::long($today),
            'counters' => $this->queue->todayCounters($factory, $today),
            'rows' => $rows->map(fn (Appointment $a) => $this->row($request, $a))->values(),
            'selected' => $selected === null ? null : $this->detail($request, $selected),
            'loadingPoints' => LoadingPoint::where('factory_id', $factory->id)
                ->active()->orderBy('code')->get(['id', 'name']),
            'plateLetters' => PlateNumber::LETTERS,
            'barcodeEnabled' => $this->devices->barcodeEnabled(),
            'can' => [
                'checkin' => $this->may($request, Permissions::QUEUE_CHECKIN),
                'override' => $this->may($request, Permissions::GATE_MANUAL_OVERRIDE),
                'weigh' => $this->may($request, Permissions::WEIGHING_RECORD),
                'resolve' => $this->may($request, Permissions::WEIGHING_RESOLVE),
                'start_loading' => $this->may($request, Permissions::QUEUE_START_LOADING),
                'finish_loading' => $this->may($request, Permissions::QUEUE_COMPLETE_LOADING),
            ],
        ]);
    }

    private function selected(Request $request, Factory $factory): ?Appointment
    {
        $ulid = $request->string('waybill')->trim()->toString();

        if ($ulid === '') {
            return null;
        }

        return Appointment::where('ulid', $ulid)
            ->where('factory_id', $factory->id)
            ->with(['driver', 'truck.truckType', 'product', 'loadingPoint', 'loadingRecord'])
            ->first();
    }

    /** ردیفِ صف — همان چند ستونی که با یک نگاه می‌گوید کامیون کجاست */
    private function row(Request $request, Appointment $appointment): array
    {
        $next = $this->nextStep($request, $appointment);

        return [
            'ulid' => $appointment->ulid,
            'number' => $appointment->number,
            'time' => substr((string) $appointment->start_time, 0, 5),
            'status' => $appointment->status->value,
            'status_label' => $appointment->status->label(),
            'status_tone' => $appointment->status->tone(),
            'is_active' => $appointment->status->isActive(),
            'plate' => $appointment->truck?->plate(),
            'plate_key' => $appointment->truck?->plate()->key(),
            'driver' => $appointment->driver?->displayName(),
            'product' => $appointment->product?->name,
            'truck_type' => $appointment->truck?->truckType?->name,
            'next' => $next['action'],
            'next_label' => $next['label'],
            'mine' => $next['mine'],
            'alert' => $appointment->loadingRecord?->awaitsDecision() === true,
        ];
    }

    private function detail(Request $request, Appointment $appointment): array
    {
        $next = $this->nextStep($request, $appointment);
        $record = $appointment->loadingRecord;

        return array_merge((new AppointmentResource($appointment))->resolve($request), [
            'next' => $next['action'],
            'next_label' => $next['label'],
            'mine' => $next['mine'],
            'blocked' => $next['blocked'],
            'capacity_kg' => $appointment->truck?->truckType?->capacity_tons !== null
                ? (float) $appointment->truck->truckType->capacity_tons * 1000
                : null,
            'tare_kg' => $record?->empty_weight_kg,
            // ورود بدون اسکن QR استثناست و دلیل می‌خواهد — کنسول هم همان
            // قاعده را دارد، فقط جای دیگری نشانش می‌دهد
            'scanned' => ScanTicket::isValid($request, $appointment),
        ]);
    }

    /**
     * کارِ بعدیِ این کامیون — همان نردبانی که در محوطه بالا می‌رود.
     *
     * @return array{action: ?string, label: ?string, mine: bool, blocked: ?string}
     */
    private function nextStep(Request $request, Appointment $appointment): array
    {
        $record = $appointment->loadingRecord;

        [$action, $label, $permission] = match (true) {
            in_array($appointment->status, [
                AppointmentStatus::Booked,
                AppointmentStatus::Waiting,
                AppointmentStatus::Called,
            ], true) => ['check-in', 'ثبت ورود', Permissions::QUEUE_CHECKIN],

            $appointment->status === AppointmentStatus::CheckedIn && $record?->hasTare() !== true => ['tare', 'توزین خالی', Permissions::WEIGHING_RECORD],

            $appointment->status === AppointmentStatus::CheckedIn => ['start-loading', 'شروع بارگیری', Permissions::QUEUE_START_LOADING],

            $appointment->status === AppointmentStatus::Loading => ['finish-loading', 'پایان بارگیری', Permissions::QUEUE_COMPLETE_LOADING],

            $appointment->status === AppointmentStatus::Loaded && $record?->awaitsDecision() === true => ['resolve', 'تعیین تکلیف اضافه‌بار', Permissions::WEIGHING_RESOLVE],

            $appointment->status === AppointmentStatus::Loaded && $record?->exit_permit_number === null => ['gross', 'توزین پر', Permissions::WEIGHING_RECORD],

            $appointment->status === AppointmentStatus::Loaded => ['exit', 'ثبت خروج', Permissions::QUEUE_COMPLETE_LOADING],

            default => [null, null, null],
        };

        return [
            'action' => $action,
            'label' => $label,
            // «مالِ من است» یعنی همین کاربر می‌تواند انجامش دهد. بقیه هم
            // می‌بینند کامیون کجاست، ولی دکمه‌ای جلویشان باز نمی‌شود.
            'mine' => $permission !== null && $this->may($request, $permission),
            'blocked' => $action === 'resolve' && ! $this->may($request, Permissions::WEIGHING_RESOLVE)
                ? 'منتظر تصمیم مدیر کارخانه'
                : null,
        ];
    }

    private function may(Request $request, string $permission): bool
    {
        return (bool) $request->user()?->can($permission);
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    /**
     * کنسول برای کسی باز است که دستِ‌کم یکی از کارهای محوطه را انجام می‌دهد.
     *
     * دیدنِ صف به تنهایی کافی نیست: کنسول جای عمل است و کسی که هیچ کاری
     * نمی‌تواند بکند، فقط یک فهرست می‌بیند که همان صفحه‌ی صف بهتر نشانش می‌دهد.
     */
    private function authorizeConsole(Request $request): void
    {
        $stations = [
            Permissions::QUEUE_CHECKIN,
            Permissions::WEIGHING_RECORD,
            Permissions::QUEUE_START_LOADING,
            Permissions::QUEUE_COMPLETE_LOADING,
        ];

        foreach ($stations as $permission) {
            if ($this->may($request, $permission)) {
                return;
            }
        }

        abort(403, 'برای این بخش دسترسی ندارید.');
    }
}
