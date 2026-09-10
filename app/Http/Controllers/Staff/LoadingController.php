<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Gate\GateDevices;
use App\Domain\Truck\PlateNumber;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Staff\Concerns\WorksOnOneWaybill;
use App\Http\Requests\Staff\LoadingPlateLookupRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * ایستگاه لاین بارگیری.
 *
 * مسئول بارگیری همان QR حواله را اسکن می‌کند؛ بدون آن دکمه‌ای برای شروع
 * وجود ندارد. یعنی «بارگیری فقط با حواله‌ی مجاز» یک قانون اجرایی است، نه
 * یک توافق شفاهی: کامیونی که کد ندارد، در سامانه هم بارگیری نمی‌شود.
 */
class LoadingController extends Controller
{
    use WorksOnOneWaybill;

    public function __construct(
        private readonly SecurityLogger $security,
        private readonly GateDevices $gateDevices,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeLoading($request);

        return $this->render($request, $this->currentWaybill($request), $this->stationNotice($request));
    }

    public function scan(Request $request): RedirectResponse
    {
        $this->authorizeLoading($request);

        $token = $request->string('token')->toString();
        $parsed = QrToken::parse($token);

        if ($parsed === null) {
            $this->security->log(SecurityLogger::QR_INVALID, null, context: ['at' => 'loading'], request: $request);

            return $this->toStation()->with('station_notice', 'کد QR معتبر نیست یا منقضی شده است.');
        }

        $appointment = Appointment::where('ulid', $parsed['ulid'])->first();

        if ($appointment === null || $appointment->factory_id !== $this->factory($request)->id) {
            return $this->toStation()->with('station_notice', 'حواله‌ای با این کد پیدا نشد.');
        }

        if (! QrToken::matches($appointment, $token)) {
            $this->security->log(SecurityLogger::QR_REPLAY, $appointment->ulid, $appointment, ['at' => 'loading'], $request);

            return $this->toStation()->with('station_notice', 'این کد دیگر معتبر نیست.');
        }

        return $this->toStation($appointment);
    }

    /**
     * پیدا کردن حواله از روی پلاک.
     *
     * بارکدخوان خراب می‌شود و کاغذِ حواله در محوطه‌ی بارگیری خیس و پاره
     * می‌شود. آن لحظه لاین نباید بایستد. این میان‌بر هیچ اختیاری اضافه
     * نمی‌کند: شروع و پایان بارگیری همچنان از Policy و ترتیب مرحله‌ها رد
     * می‌شود و توزین خالیِ نداشته، دکمه‌ی شروع را باز نمی‌کند.
     */
    public function lookup(LoadingPlateLookupRequest $request): RedirectResponse
    {
        $this->authorizeLoading($request);

        $plate = $request->plate();

        if ($plate === null) {
            return $this->toStation()->with('station_notice', 'شماره پلاک معتبر نیست.');
        }

        $today = Appointment::whereHas('truck', fn ($q) => $q->where('plate_key', $plate->key()))
            ->where('factory_id', $this->factory($request)->id)
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->queueOrder()
            ->get();

        if ($today->isEmpty()) {
            return $this->toStation()->with('station_notice', 'برای این پلاک امروز حواله‌ای ثبت نشده است.');
        }

        // یک کامیون می‌تواند بیش از یک حواله‌ی امروز داشته باشد. آنکه کارِ
        // لاین دارد جلو می‌افتد؛ وگرنه مسئول لاین حواله‌ی بارگیری‌شده را
        // می‌بیند و فکر می‌کند سامانه گم کرده است.
        $pending = $today->first(fn (Appointment $a) => $this->actionFor($a) !== null);

        // هیچ‌کدام کارِ لاین ندارند: باز هم نشان می‌دهیم تا *دلیلش* دیده
        // شود — هنوز وارد نشده، یا قبلاً بارگیری شده.
        return $this->toStation($pending ?? $today->first());
    }

    /** شروع یا پایان بارگیری — هر دو از روی همان اسکن */
    public function transition(Request $request, Appointment $appointment, TransitionAppointment $transition): RedirectResponse
    {
        $this->authorizeLoading($request);

        abort_unless($appointment->factory_id === $this->factory($request)->id, 404);

        $target = AppointmentStatus::tryFrom((string) $request->input('to'));

        if (! in_array($target, [AppointmentStatus::Loading, AppointmentStatus::Loaded], true)) {
            return $this->toStation($appointment)->with('error', 'این عملیات از لاین بارگیری انجام نمی‌شود.');
        }

        if ($request->user()->cannot('transition', [$appointment, $target])) {
            return $this->toStation($appointment)->with('error', 'برای این عملیات دسترسی ندارید.');
        }

        $validated = $request->validate([
            'loading_point_id' => ['nullable', 'integer', 'exists:loading_points,id'],
        ]);

        try {
            $transition(
                $appointment,
                $target,
                Actor::user($request->user(), $request->ip()),
                loadingPointId: $validated['loading_point_id'] ?? null,
            );
        } catch (InvalidStateTransition|TransitionBlocked $e) {
            return $this->toStation($appointment)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('staff.loading.index')
            ->with('success', $target === AppointmentStatus::Loading
                ? 'شروع بارگیری ثبت شد.'
                : 'پایان بارگیری ثبت شد. کامیون را به باسکول دوم بفرستید.');
    }

    private function render(Request $request, ?Appointment $appointment, ?string $error = null): Response
    {
        $factory = $this->factory($request);

        $appointment?->load(['driver', 'truck.truckType', 'product', 'loadingRecord', 'factory']);

        return Inertia::render('Staff/Loading/Index', [
            // همان بارکدخوانی که در گیت هست، اینجا هم کار می‌کند
            'barcodeEnabled' => $this->gateDevices->barcodeEnabled(),
            'plateLetters' => PlateNumber::LETTERS,
            'loadingPoints' => LoadingPoint::where('factory_id', $factory->id)
                ->active()
                ->orderBy('code')
                ->get(['id', 'name']),
            'inProgress' => $this->inProgress($factory),
            'result' => [
                'error' => $error,
                'appointment' => $appointment
                    ? array_merge((new AppointmentResource($appointment))->resolve($request), [
                        'action' => $this->actionFor($appointment),
                        'has_tare' => (bool) $appointment->loadingRecord?->hasTare(),
                        'expected_minutes' => $appointment->expectedLoadingMinutes(),
                        'elapsed_minutes' => $this->elapsedMinutes($appointment),
                        'is_late' => $appointment->isLoadingLate(),
                    ])
                    : null,
            ],
        ]);
    }

    /** کامیون‌هایی که همین حالا روی لاین‌اند — با نشانه‌ی تأخیر */
    private function inProgress(Factory $factory): array
    {
        return Appointment::with(['truck.truckType', 'product', 'loadingPoint'])
            ->where('factory_id', $factory->id)
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->where('status', AppointmentStatus::Loading->value)
            ->orderBy('loading_started_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'ulid' => $a->ulid,
                'number' => $a->number,
                'plate' => $a->truck?->plate(),
                'product' => $a->product?->name,
                'loading_point' => $a->loadingPoint?->name,
                'elapsed_minutes' => $this->elapsedMinutes($a),
                'expected_minutes' => $a->expectedLoadingMinutes(),
                'is_late' => $a->isLoadingLate(),
            ])
            ->all();
    }

    private function elapsedMinutes(Appointment $appointment): ?int
    {
        return $appointment->loading_started_at === null
            ? null
            : (int) $appointment->loading_started_at->diffInMinutes(now());
    }

    private function actionFor(Appointment $appointment): ?string
    {
        return match ($appointment->status) {
            AppointmentStatus::CheckedIn => 'start',
            AppointmentStatus::Loading => 'finish',
            default => null,
        };
    }

    private function stationRoute(): string
    {
        return 'staff.loading.index';
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function authorizeLoading(Request $request): void
    {
        abort_unless(
            $request->user()?->can(Permissions::QUEUE_START_LOADING)
                || $request->user()?->can(Permissions::QUEUE_COMPLETE_LOADING),
            403,
            'برای لاین بارگیری دسترسی ندارید.',
        );
    }
}
