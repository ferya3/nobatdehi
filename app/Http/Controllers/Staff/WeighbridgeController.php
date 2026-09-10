<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Gate\GateDevices;
use App\Domain\Truck\PlateNumber;
use App\Domain\Weighbridge\ExitPermit;
use App\Domain\Weighbridge\ScaleDevices;
use App\Domain\Weighbridge\WeighingService;
use App\Domain\Weighbridge\WeightSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RecordWeightRequest;
use App\Http\Requests\Staff\WeighbridgePlateLookupRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\ScaleReading;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * باسکول اول (خالی) و دوم (پر).
 *
 * قواعدی که این کلاس نگه می‌دارد:
 *   - وزن خالص هیچ‌وقت وارد نمی‌شود؛ سرور از پر منهای خالی حساب می‌کند.
 *   - هر توزین یک بار ثبت می‌شود. تغییرش کارِ کسی است که rollback دارد.
 *   - برگه‌ی خروج فقط وقتی صادر می‌شود که وزن پاک باشد؛ اضافه‌بار و مغایرت
 *     یعنی قفل، نه هشدار.
 */
class WeighbridgeController extends Controller
{
    public function __construct(
        private readonly WeighingService $weighing,
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
        private readonly ScaleDevices $scales,
        private readonly GateDevices $gateDevices,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeWeighing($request);

        return $this->render($request, null);
    }

    /** پیدا کردن کامیون با اسکن همان QR حواله */
    public function scan(Request $request): Response
    {
        $this->authorizeWeighing($request);

        $token = $request->string('token')->toString();
        $parsed = QrToken::parse($token);

        if ($parsed === null) {
            $this->security->log(SecurityLogger::QR_INVALID, null, context: ['at' => 'weighbridge'], request: $request);

            return $this->render($request, null, 'کد QR معتبر نیست یا منقضی شده است.');
        }

        $appointment = Appointment::where('ulid', $parsed['ulid'])->first();

        if ($appointment === null || $appointment->factory_id !== $this->factory($request)->id) {
            return $this->render($request, null, 'حواله‌ای با این کد پیدا نشد.');
        }

        if (! QrToken::matches($appointment, $token)) {
            $this->security->log(SecurityLogger::QR_REPLAY, $appointment->ulid, $appointment, ['at' => 'weighbridge'], $request);

            return $this->render($request, null, 'این کد دیگر معتبر نیست.');
        }

        return $this->render($request, $appointment);
    }

    /**
     * پیدا کردن حواله با شماره پلاک.
     *
     * بارکدخوان خراب می‌شود، گوشی راننده خاموش می‌شود، و QR روی کاغذِ خیس
     * خوانده نمی‌شود. بدون این، باسکول در همان لحظه می‌ایستد.
     *
     * برخلاف نگهبانی، اینجا جستجوی پلاک چیزی را شل نمی‌کند: ثبت ورود در گیت
     * به بلیط اسکن نیاز دارد ولی ثبت وزن هرگز نداشته. آنچه وزن را نگه
     * می‌دارد جای دیگری است — ترتیب مرحله‌ها، عددی که از خودِ باسکول می‌آید،
     * و مغایرتی که برگه‌ی خروج را قفل می‌کند. پس پیدا کردنِ همان حواله از
     * راه پلاک، هیچ اختیارِ تازه‌ای به کسی نمی‌دهد.
     */
    public function lookup(WeighbridgePlateLookupRequest $request): Response
    {
        $plate = $request->plate();

        if ($plate === null) {
            return $this->render($request, null, 'شماره پلاک معتبر نیست.');
        }

        $today = Appointment::whereHas('truck', fn ($q) => $q->where('plate_key', $plate->key()))
            ->where('factory_id', $this->factory($request)->id)
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->with('loadingRecord')
            ->queueOrder()
            ->get();

        if ($today->isEmpty()) {
            return $this->render($request, null, 'برای این پلاک امروز حواله‌ای ثبت نشده است.');
        }

        // یک کامیون می‌تواند بیش از یک حواله‌ی امروز داشته باشد. آنکه واقعاً
        // کار باسکول دارد را جلو می‌اندازیم؛ وگرنه اپراتور حواله‌ی توزین‌شده
        // را می‌بیند و فکر می‌کند سامانه اشتباه می‌کند.
        $pending = $today->first(
            fn (Appointment $a) => $this->stageFor($a, $a->loadingRecord) !== null,
        );

        // چیزی برای توزین نیست، ولی حواله هست: همان را نشان می‌دهیم تا
        // اپراتور *دلیلش* را ببیند — هنوز وارد نشده، یا قبلاً توزین شده.
        return $this->render($request, $pending ?? $today->first());
    }

    /** ثبت وزن — خالی یا پر */
    public function record(RecordWeightRequest $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($appointment->factory_id === $this->factory($request)->id, 404);

        $stage = $request->string('stage')->toString();

        // ردیف را عمداً هنوز نمی‌سازیم: توزینی که رد می‌شود نباید یک ردیفِ
        // خالیِ loading_records پشت سرش بگذارد که بعداً کسی نداند چیست.
        $record = LoadingRecord::firstWhere('appointment_id', $appointment->id);

        $blocked = $this->rejectOutOfOrder($appointment, $record, $stage);

        if ($blocked !== null) {
            return back()->with('error', $blocked);
        }

        $source = $this->weightFrom($request);

        if ($source->isRejected()) {
            return back()->with('error', $source->error);
        }

        $record ??= LoadingRecord::create(['appointment_id' => $appointment->id]);

        // ورود دستی وقتی باسکول وصل است، یعنی یا دستگاه خراب شده یا کسی
        // دور می‌زند. هر دو حالت باید جایی ثبت شوند که بعداً دیده شود.
        if ($source->kind === WeightSource::MANUAL && $this->scales->enabled()) {
            $this->security->log(
                SecurityLogger::ADMIN_ACTION,
                $appointment->ulid,
                $appointment,
                ['at' => 'weighbridge', 'stage' => $stage, 'reason' => $source->manualReason],
                $request,
            );
        }

        $photo = $request->file('photo')?->store("weighbridge/{$appointment->ulid}", 'public');

        return $stage === 'tare'
            ? $this->recordTare($request, $appointment, $record, $source, $photo)
            : $this->recordGross($request, $appointment, $record, $source, $photo);
    }

    /**
     * وزن از کدام مرجع می‌آید.
     *
     * وقتی شناسه‌ی خواندن آمده باشد، عددِ فرم اصلاً خوانده نمی‌شود — سرور
     * از روی همان ردیف برمی‌دارد. این تنها راهی است که «مستقیم از باسکول»
     * چیزی بیش از یک ادعا باشد.
     */
    private function weightFrom(RecordWeightRequest $request): WeightSource
    {
        $readingId = $request->integer('reading_id');

        if ($readingId > 0) {
            $reading = ScaleReading::find($readingId);

            if ($reading === null) {
                return WeightSource::rejected('عدد باسکول پیدا نشد. دوباره بگیرید.');
            }

            return WeightSource::resolve(
                $reading,
                $this->factory($request),
                $this->scales->requireStable(),
            );
        }

        return WeightSource::fromOperator(
            round((float) $request->input('weight_kg'), 2),
            $request->string('manual_reason')->trim()->toString(),
        );
    }

    /**
     * ترتیب مرحله‌ها.
     *
     * توزینِ دوباره‌ی یک مرحله بسته است: «اصلاح وزن خالی» بعد از بارگیری،
     * دقیقاً همان حفره‌ای است که وزن خالص را دلخواه می‌کند.
     */
    private function rejectOutOfOrder(Appointment $appointment, ?LoadingRecord $record, string $stage): ?string
    {
        if ($stage === 'tare') {
            if ($record?->hasTare()) {
                return 'وزن خالی این حواله قبلاً ثبت شده است. تغییرش فقط با مسئول شیفت ممکن است.';
            }

            return $appointment->status === AppointmentStatus::CheckedIn
                ? null
                : 'توزین خالی وقتی انجام می‌شود که کامیون وارد محوطه شده و هنوز بارگیری نشده باشد.';
        }

        if ($record?->hasGross()) {
            return 'وزن پر این حواله قبلاً ثبت شده است.';
        }

        if (! $record?->hasTare()) {
            return 'اول باید وزن خالی ثبت شده باشد.';
        }

        return $appointment->status === AppointmentStatus::Loaded
            ? null
            : 'توزین پر بعد از پایان بارگیری انجام می‌شود.';
    }

    private function recordTare(
        Request $request,
        Appointment $appointment,
        LoadingRecord $record,
        WeightSource $source,
        ?string $photo,
    ): RedirectResponse {
        $record->forceFill([
            'empty_weight_kg' => $source->weightKg,
            'tare_weighed_at' => now(),
            'tare_source' => $source->kind,
            'tare_reading_id' => $source->reading?->id,
            'tare_manual_reason' => $source->manualReason,
            'tare_photo_path' => $photo,
            'tare_by_user_id' => $request->user()->id,
            'waybill_number' => $record->waybill_number ?? $appointment->number,
            'expected_net_kg' => $this->weighing->expectedNetKg($appointment),
        ])->save();

        // خواندن به همین حواله گره می‌خورد تا در سابقه پیدا شود
        $source->reading?->forceFill(['appointment_id' => $appointment->id])->save();

        $this->audit->log(
            action: 'RECORD_TARE_WEIGHT',
            entity: $record,
            newValues: [
                'empty_weight_kg' => $source->weightKg,
                'source' => $source->kind,
                'reading_id' => $source->reading?->id,
                'manual_reason' => $source->manualReason,
            ],
            request: $request,
        );

        return back()->with('success', 'وزن خالی ثبت شد. کامیون می‌تواند به لاین بارگیری برود.');
    }

    private function recordGross(
        Request $request,
        Appointment $appointment,
        LoadingRecord $record,
        WeightSource $source,
        ?string $photo,
    ): RedirectResponse {
        $appointment->loadMissing(['product', 'truck.truckType', 'factory']);

        $weight = $source->weightKg;

        $result = $this->weighing->evaluate($appointment, (float) $record->empty_weight_kg, $weight);

        $record->forceFill([
            'loaded_weight_kg' => $weight,
            'gross_weighed_at' => now(),
            'gross_source' => $source->kind,
            'gross_reading_id' => $source->reading?->id,
            'gross_manual_reason' => $source->manualReason,
            'gross_photo_path' => $photo,
            'gross_by_user_id' => $request->user()->id,
            'net_weight_kg' => $result->netKg,
            'expected_net_kg' => $result->expectedKg,
            'variance_kg' => $result->varianceKg,
            'is_overload' => $result->isOverload,
        ])->save();

        $source->reading?->forceFill(['appointment_id' => $appointment->id])->save();

        $this->audit->log(
            action: 'RECORD_GROSS_WEIGHT',
            entity: $record,
            newValues: [
                'loaded_weight_kg' => $weight,
                'net_weight_kg' => $result->netKg,
                'variance_kg' => $result->varianceKg,
                'is_overload' => $result->isOverload,
                'source' => $source->kind,
                'reading_id' => $source->reading?->id,
                'manual_reason' => $source->manualReason,
            ],
            request: $request,
        );

        if (! $result->isClear()) {
            $this->security->log(
                SecurityLogger::SUSPICIOUS_REQUEST,
                $appointment->ulid,
                $appointment,
                [
                    'at' => 'weighbridge',
                    'net_kg' => $result->netKg,
                    'expected_kg' => $result->expectedKg,
                    'overload' => $result->isOverload,
                ],
                $request,
            );

            return back()->with('error', $result->blockReason);
        }

        $number = ExitPermit::issue($appointment, $record);

        return back()->with('success', "وزن پر ثبت و برگه خروج {$number} صادر شد.");
    }

    /**
     * تازه‌ترین عددِ هر باسکول.
     *
     * WebSocket راه اصلی است، ولی اتاقک باسکول جایی است که شبکه ضعیف است
     * و صفحه ساعت‌ها باز می‌ماند. این مسیر همان پشتیبانِ polling است که
     * وقتی اتصال زنده بیفتد، باسکول را بی‌مصرف نمی‌گذارد.
     */
    public function readings(Request $request): JsonResponse
    {
        $this->authorizeWeighing($request);

        return response()->json(['scales' => $this->liveScales($request)]);
    }

    /**
     * آخرین خواندنِ هر باسکول، جدا از هم.
     *
     * باسکولِ ورودی و خروجی دو دستگاه جدا هستند و عددشان نباید قاطی شود؛
     * اپراتور باید بداند این عدد از کدام پل آمده.
     *
     * @return array<int, array<string, mixed>>
     */
    private function liveScales(Request $request): array
    {
        if (! $this->scales->enabled()) {
            return [];
        }

        $factoryId = $this->factory($request)->id;

        return ScaleReading::where('factory_id', $factoryId)
            ->fresh()
            ->whereIn('id', function ($query) use ($factoryId) {
                $query->selectRaw('max(id)')
                    ->from('scale_readings')
                    ->where('factory_id', $factoryId)
                    ->where('read_at', '>=', now()->subSeconds(ScaleReading::FRESH_SECONDS))
                    ->groupBy('scale_name');
            })
            ->orderBy('scale_name')
            ->get()
            ->map(fn (ScaleReading $reading) => [
                'id' => $reading->id,
                'scale' => $reading->scale_name,
                'weight_kg' => (float) $reading->weight_kg,
                'is_stable' => $reading->is_stable,
                'read_at' => $reading->read_at?->toIso8601String(),
                'clock' => $reading->read_at?->format('H:i:s'),
            ])
            ->all();
    }

    private function render(Request $request, ?Appointment $appointment, ?string $error = null): Response
    {
        $appointment?->load(['driver', 'truck.truckType', 'product', 'loadingRecord', 'factory']);

        $record = $appointment?->loadingRecord;

        return Inertia::render('Staff/Weighbridge/Index', [
            'factoryId' => $this->factory($request)->id,
            'scaleEnabled' => $this->scales->enabled(),
            // همان بارکدخوانی که در گیت هست، اینجا هم کار می‌کند
            'barcodeEnabled' => $this->gateDevices->barcodeEnabled(),
            'requireStable' => $this->scales->requireStable(),
            'scales' => $this->liveScales($request),
            'pending' => $this->pendingCounts($request),
            // از سرور می‌آید و نه یک ثابت در Vue: نسخه‌ی سوم این فهرست،
            // همان نسخه‌ای است که روزی با PlateNumber فرق پیدا می‌کند.
            'plateLetters' => PlateNumber::LETTERS,
            'result' => [
                'error' => $error,
                'appointment' => $appointment
                    ? array_merge((new AppointmentResource($appointment))->resolve($request), [
                        'stage' => $this->stageFor($appointment, $record),
                        'expected_net_kg' => $this->weighing->expectedNetKg($appointment),
                        'capacity_kg' => $this->weighing->capacityKg($appointment),
                        'weighing' => $record ? [
                            'empty_weight_kg' => $record->empty_weight_kg,
                            'loaded_weight_kg' => $record->loaded_weight_kg,
                            'net_weight_kg' => $record->net_weight_kg,
                            'variance_kg' => $record->variance_kg,
                            'is_overload' => $record->is_overload,
                            'exit_permit_number' => $record->exit_permit_number,
                        ] : null,
                    ])
                    : null,
            ],
        ]);
    }

    /** کدام باسکول الان کار دارد */
    private function stageFor(Appointment $appointment, ?LoadingRecord $record): ?string
    {
        if ($record === null || ! $record->hasTare()) {
            return $appointment->status === AppointmentStatus::CheckedIn ? 'tare' : null;
        }

        if (! $record->hasGross()) {
            return $appointment->status === AppointmentStatus::Loaded ? 'gross' : null;
        }

        return null;
    }

    /** @return array<string, int> */
    private function pendingCounts(Request $request): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $factoryId = $this->factory($request)->id;

        $count = fn (AppointmentStatus $status) => Appointment::where('factory_id', $factoryId)
            ->whereDate('date', $today)
            ->where('status', $status->value)
            ->count();

        return [
            'tare' => $count(AppointmentStatus::CheckedIn),
            'gross' => $count(AppointmentStatus::Loaded),
        ];
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function authorizeWeighing(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::WEIGHING_RECORD), 403, 'برای باسکول دسترسی ندارید.');
    }
}
