<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Weighbridge\ExitPermit;
use App\Domain\Weighbridge\WeighingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\RecordWeightRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use Carbon\CarbonImmutable;
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

    /** ثبت وزن — خالی یا پر */
    public function record(RecordWeightRequest $request, Appointment $appointment): RedirectResponse
    {
        abort_unless($appointment->factory_id === $this->factory($request)->id, 404);

        $stage = $request->string('stage')->toString();
        $weight = round((float) $request->input('weight_kg'), 2);

        $record = LoadingRecord::firstOrCreate(['appointment_id' => $appointment->id]);

        $blocked = $this->rejectOutOfOrder($appointment, $record, $stage);

        if ($blocked !== null) {
            return back()->with('error', $blocked);
        }

        $photo = $request->file('photo')?->store("weighbridge/{$appointment->ulid}", 'public');

        return $stage === 'tare'
            ? $this->recordTare($request, $appointment, $record, $weight, $photo)
            : $this->recordGross($request, $appointment, $record, $weight, $photo);
    }

    /**
     * ترتیب مرحله‌ها.
     *
     * توزینِ دوباره‌ی یک مرحله بسته است: «اصلاح وزن خالی» بعد از بارگیری،
     * دقیقاً همان حفره‌ای است که وزن خالص را دلخواه می‌کند.
     */
    private function rejectOutOfOrder(Appointment $appointment, LoadingRecord $record, string $stage): ?string
    {
        if ($stage === 'tare') {
            if ($record->hasTare()) {
                return 'وزن خالی این حواله قبلاً ثبت شده است. تغییرش فقط با مسئول شیفت ممکن است.';
            }

            return $appointment->status === AppointmentStatus::CheckedIn
                ? null
                : 'توزین خالی وقتی انجام می‌شود که کامیون وارد محوطه شده و هنوز بارگیری نشده باشد.';
        }

        if ($record->hasGross()) {
            return 'وزن پر این حواله قبلاً ثبت شده است.';
        }

        if (! $record->hasTare()) {
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
        float $weight,
        ?string $photo,
    ): RedirectResponse {
        $record->forceFill([
            'empty_weight_kg' => $weight,
            'tare_weighed_at' => now(),
            'tare_source' => $request->string('source')->toString(),
            'tare_photo_path' => $photo,
            'tare_by_user_id' => $request->user()->id,
            'waybill_number' => $record->waybill_number ?? $appointment->number,
            'expected_net_kg' => $this->weighing->expectedNetKg($appointment),
        ])->save();

        $this->audit->log(
            action: 'RECORD_TARE_WEIGHT',
            entity: $record,
            newValues: ['empty_weight_kg' => $weight, 'source' => $request->input('source')],
            request: $request,
        );

        return back()->with('success', 'وزن خالی ثبت شد. کامیون می‌تواند به لاین بارگیری برود.');
    }

    private function recordGross(
        Request $request,
        Appointment $appointment,
        LoadingRecord $record,
        float $weight,
        ?string $photo,
    ): RedirectResponse {
        $appointment->loadMissing(['product', 'truck.truckType', 'factory']);

        $result = $this->weighing->evaluate($appointment, (float) $record->empty_weight_kg, $weight);

        $record->forceFill([
            'loaded_weight_kg' => $weight,
            'gross_weighed_at' => now(),
            'gross_source' => $request->string('source')->toString(),
            'gross_photo_path' => $photo,
            'gross_by_user_id' => $request->user()->id,
            'net_weight_kg' => $result->netKg,
            'expected_net_kg' => $result->expectedKg,
            'variance_kg' => $result->varianceKg,
            'is_overload' => $result->isOverload,
        ])->save();

        $this->audit->log(
            action: 'RECORD_GROSS_WEIGHT',
            entity: $record,
            newValues: [
                'loaded_weight_kg' => $weight,
                'net_weight_kg' => $result->netKg,
                'variance_kg' => $result->varianceKg,
                'is_overload' => $result->isOverload,
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

    private function render(Request $request, ?Appointment $appointment, ?string $error = null): Response
    {
        $appointment?->load(['driver', 'truck.truckType', 'product', 'loadingRecord', 'factory']);

        $record = $appointment?->loadingRecord;

        return Inertia::render('Staff/Weighbridge/Index', [
            'pending' => $this->pendingCounts($request),
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
