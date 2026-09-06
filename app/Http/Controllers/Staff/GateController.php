<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\SecurityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\PlateLookupRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحه‌ی نگهبانی/باسکول: اعتبارسنجی نوبت و ثبت ورود.
 *
 * دو مسیر دارد که به یک نتیجه می‌رسند — اسکن QR، و جستجوی دستی پلاک برای
 * وقتی که گوشی راننده خاموش است یا QR خوانده نمی‌شود.
 */
class GateController extends Controller
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function index(Request $request): Response
    {
        $this->authorizeGate($request);

        return Inertia::render('Staff/Gate/Index', [
            'onSiteCount' => Appointment::where('factory_id', $this->factory($request)->id)
                ->whereDate('date', CarbonImmutable::today()->toDateString())
                ->onSite()
                ->count(),
        ]);
    }

    /** اعتبارسنجی توکن QR */
    public function scan(Request $request): Response
    {
        $this->authorizeGate($request);

        $token = $request->string('token')->toString();
        $parsed = QrToken::parse($token);

        if ($parsed === null) {
            $this->security->log(SecurityLogger::QR_INVALID, null, context: ['reason' => 'bad_token'], request: $request);

            return $this->result($request, null, 'کد QR معتبر نیست یا منقضی شده است.');
        }

        $appointment = Appointment::where('ulid', $parsed['ulid'])->first();

        if ($appointment === null || $appointment->factory_id !== $this->factory($request)->id) {
            $this->security->log(SecurityLogger::QR_INVALID, $parsed['ulid'], context: ['reason' => 'not_found'], request: $request);

            return $this->result($request, null, 'نوبتی با این کد پیدا نشد.');
        }

        // توکن باید همانی باشد که آخرین بار برای این نوبت صادر شده
        if (! QrToken::matches($appointment, $token)) {
            $this->security->log(SecurityLogger::QR_REPLAY, $appointment->ulid, $appointment, request: $request);

            return $this->result($request, $appointment, 'این کد دیگر معتبر نیست. راننده باید کد را از برنامه دوباره باز کند.');
        }

        return $this->result($request, $appointment);
    }

    /** جستجوی دستی با پلاک — وقتی QR در دسترس نیست */
    public function lookup(PlateLookupRequest $request): Response
    {
        $plate = $request->plate();

        if ($plate === null) {
            return $this->result($request, null, 'شماره پلاک معتبر نیست.');
        }

        $appointment = Appointment::whereHas('truck', fn ($q) => $q->where('plate_key', $plate->key()))
            ->where('factory_id', $this->factory($request)->id)
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->active()
            ->queueOrder()
            ->first();

        if ($appointment === null) {
            return $this->result($request, null, 'برای این پلاک نوبت فعالی در امروز ثبت نشده است.');
        }

        return $this->result($request, $appointment);
    }

    /** ثبت ورود کامیون به محوطه */
    public function checkIn(Request $request, Appointment $appointment, TransitionAppointment $transition): RedirectResponse
    {
        $this->authorizeGate($request);

        if ($request->user()->cannot('transition', [$appointment, AppointmentStatus::CheckedIn])) {
            return back()->with('error', 'برای ثبت ورود دسترسی ندارید.');
        }

        try {
            $transition(
                $appointment,
                AppointmentStatus::CheckedIn,
                Actor::user($request->user(), $request->ip()),
            );
        } catch (InvalidStateTransition $e) {
            return back()->with('error', $e->getMessage());
        }

        // توکن QR یک‌بارمصرف است: بعد از ورود دیگر نباید دوباره کار کند
        $appointment->forceFill(['qr_used_at' => now(), 'qr_token_hash' => null])->save();

        return redirect()
            ->route('staff.gate.index')
            ->with('success', 'ورود نوبت '.$appointment->number.' ثبت شد.');
    }

    private function result(Request $request, ?Appointment $appointment, ?string $error = null): Response
    {
        $appointment?->load(['driver', 'truck.truckType', 'product']);

        return Inertia::render('Staff/Gate/Index', [
            'onSiteCount' => Appointment::where('factory_id', $this->factory($request)->id)
                ->whereDate('date', CarbonImmutable::today()->toDateString())
                ->onSite()
                ->count(),
            'result' => [
                'error' => $error,
                'appointment' => $appointment
                    ? array_merge((new AppointmentResource($appointment))->resolve($request), [
                        'can_check_in' => $error === null
                            && $request->user()->can('transition', [$appointment, AppointmentStatus::CheckedIn]),
                        'is_today' => $appointment->date->isToday(),
                    ])
                    : null,
            ],
        ]);
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function authorizeGate(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::QUEUE_CHECKIN), 403, 'برای این بخش دسترسی ندارید.');
    }
}
