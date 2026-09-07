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
use App\Http\Controllers\Controller;
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
    public function __construct(private readonly SecurityLogger $security) {}

    public function index(Request $request): Response
    {
        $this->authorizeLoading($request);

        return $this->render($request, null);
    }

    public function scan(Request $request): Response
    {
        $this->authorizeLoading($request);

        $token = $request->string('token')->toString();
        $parsed = QrToken::parse($token);

        if ($parsed === null) {
            $this->security->log(SecurityLogger::QR_INVALID, null, context: ['at' => 'loading'], request: $request);

            return $this->render($request, null, 'کد QR معتبر نیست یا منقضی شده است.');
        }

        $appointment = Appointment::where('ulid', $parsed['ulid'])->first();

        if ($appointment === null || $appointment->factory_id !== $this->factory($request)->id) {
            return $this->render($request, null, 'حواله‌ای با این کد پیدا نشد.');
        }

        if (! QrToken::matches($appointment, $token)) {
            $this->security->log(SecurityLogger::QR_REPLAY, $appointment->ulid, $appointment, ['at' => 'loading'], $request);

            return $this->render($request, null, 'این کد دیگر معتبر نیست.');
        }

        return $this->render($request, $appointment);
    }

    /** شروع یا پایان بارگیری — هر دو از روی همان اسکن */
    public function transition(Request $request, Appointment $appointment, TransitionAppointment $transition): RedirectResponse
    {
        $this->authorizeLoading($request);

        abort_unless($appointment->factory_id === $this->factory($request)->id, 404);

        $target = AppointmentStatus::tryFrom((string) $request->input('to'));

        if (! in_array($target, [AppointmentStatus::Loading, AppointmentStatus::Loaded], true)) {
            return back()->with('error', 'این عملیات از لاین بارگیری انجام نمی‌شود.');
        }

        if ($request->user()->cannot('transition', [$appointment, $target])) {
            return back()->with('error', 'برای این عملیات دسترسی ندارید.');
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
            return back()->with('error', $e->getMessage());
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
