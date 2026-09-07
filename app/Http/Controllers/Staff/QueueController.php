<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\AppointmentStateMachine;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Domain\Queue\QueueService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Models\User;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly AppointmentStateMachine $machine,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAny($request, Permissions::QUEUE_VIEW);

        $factory = $this->factory($request);
        $date = $this->requestedDate($request);

        $appointments = $this->queue->todayQueue($factory, $date);

        return Inertia::render('Staff/Queue/Index', [
            'factoryId' => $factory->id,
            'date' => $date->toDateString(),
            'jalaliDate' => Jalali::long($date),
            'isToday' => $date->isToday(),
            'counters' => $this->queue->todayCounters($factory, $date),
            'appointments' => $appointments->map(
                fn (Appointment $a) => $this->rowFor($request, $a),
            )->values(),
            'loadingPoints' => LoadingPoint::where('factory_id', $factory->id)
                ->active()
                ->orderBy('code')
                ->get(['id', 'name']),
            'statuses' => AppointmentStatus::options(),
        ]);
    }

    /** انجام یک انتقال وضعیت روی یک نوبت */
    public function transition(
        Request $request,
        Appointment $appointment,
        TransitionAppointment $transition,
    ): RedirectResponse {
        $target = AppointmentStatus::tryFrom((string) $request->input('to'));

        if ($target === null) {
            return back()->with('error', 'وضعیت مقصد نامعتبر است.');
        }

        // Policy هم انتقال مجاز را چک می‌کند، هم دسترسی مخصوص همان انتقال را
        if ($request->user()->cannot('transition', [$appointment, $target])) {
            return back()->with('error', 'برای این عملیات دسترسی ندارید.');
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
            'loading_point_id' => ['nullable', 'integer', 'exists:loading_points,id'],
        ]);

        try {
            $transition(
                $appointment,
                $target,
                Actor::user($request->user(), $request->ip()),
                $validated['reason'] ?? null,
                $validated['loading_point_id'] ?? null,
            );
        } catch (InvalidStateTransition|BookingException|TransitionBlocked $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'وضعیت نوبت «'.$target->label().'» ثبت شد.');
    }

    public function show(Request $request, Appointment $appointment): Response
    {
        $this->authorizeAny($request, Permissions::APPOINTMENTS_VIEW);

        $appointment->load([
            'driver', 'truck.truckType', 'product', 'loadingPoint',
            'transitions.user', 'transitions.driver', 'loadingRecord',
        ]);

        return Inertia::render('Staff/Queue/Show', [
            'appointment' => $this->rowFor($request, $appointment),
            'timeline' => $appointment->transitions->map(fn ($t) => [
                'id' => $t->id,
                'from' => $t->from_status?->label(),
                'to' => $t->to_status->label(),
                'tone' => $t->to_status->tone(),
                'is_rollback' => $t->is_rollback,
                'reason' => $t->reason,
                'actor' => $t->actorName(),
                'at' => $t->created_at?->toIso8601String(),
                'clock' => $t->created_at?->format('H:i'),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function rowFor(Request $request, Appointment $appointment): array
    {
        /** @var User $user */
        $user = $request->user();

        $allowed = collect($this->machine->allowedFrom(
            $appointment->status,
            $user->can(Permissions::APPOINTMENTS_ROLLBACK),
        ))
            // انقضا را زمان‌بند شبانه انجام می‌دهد، نه اپراتور با یک کلیک
            ->reject(fn (AppointmentStatus $s) => $s === AppointmentStatus::Expired)
            ->filter(fn (AppointmentStatus $s) => $user->can('transition', [$appointment, $s]))
            ->map(fn (AppointmentStatus $s) => [
                'value' => $s->value,
                'label' => $this->actionLabel($appointment->status, $s),
                'tone' => $s->tone(),
                'is_rollback' => $this->machine->isRollback($appointment->status, $s),
                'needs_reason' => in_array($s, [
                    AppointmentStatus::Cancelled,
                    AppointmentStatus::Rejected,
                    AppointmentStatus::NoShow,
                ], true),
                'needs_loading_point' => $s === AppointmentStatus::Loading,
            ])
            ->values();

        return array_merge(
            (new AppointmentResource($appointment))->resolve($request),
            [
                'wait_minutes' => $appointment->waitMinutes(),
                'loading_minutes' => $appointment->loadingMinutes(),
                'actions' => $allowed,
            ],
        );
    }

    /** برچسب دکمه، نه نام وضعیت: «فراخوانی» به‌جای «فراخوانده‌شده» */
    private function actionLabel(AppointmentStatus $from, AppointmentStatus $to): string
    {
        if ($this->machine->isRollback($from, $to)) {
            return 'بازگرداندن به «'.$to->label().'»';
        }

        return match ($to) {
            AppointmentStatus::Waiting => 'انتقال به صف انتظار',
            AppointmentStatus::Called => 'فراخوانی',
            AppointmentStatus::CheckedIn => 'ثبت ورود به محوطه',
            AppointmentStatus::Loading => 'شروع بارگیری',
            AppointmentStatus::Loaded => 'پایان بارگیری',
            AppointmentStatus::Completed => 'تکمیل و خروج',
            AppointmentStatus::NoShow => 'ثبت عدم حضور',
            AppointmentStatus::Cancelled => 'لغو نوبت',
            AppointmentStatus::Rejected => 'رد نوبت',
            AppointmentStatus::Expired => 'انقضا',
            AppointmentStatus::Booked => 'بازگرداندن به ثبت‌شده',
        };
    }

    private function requestedDate(Request $request): CarbonImmutable
    {
        $raw = $request->string('date')->toString();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)
            ? CarbonImmutable::parse($raw)->startOfDay()
            : CarbonImmutable::today();
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function authorizeAny(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission), 403, 'برای مشاهده این بخش دسترسی ندارید.');
    }
}
