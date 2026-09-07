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
use App\Domain\Gate\ScanTicket;
use App\Domain\Truck\PlateNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\GateCheckInRequest;
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
 * صفحه‌ی نگهبانی: اعتبارسنجی حواله و ثبت ورود.
 *
 * دو قانون که کل این کلاس دور آن‌ها ساخته شده:
 *
 *   ۱. بدون اسکن QR ورود ممنوع است. جستجوی پلاک فقط «نمایش وضعیت» است و
 *      دکمه‌ی ورودش خاموش می‌ماند، مگر کسی دسترسی gate.manual-override
 *      داشته باشد و دلیل بنویسد — که آن هم در لاگ امنیتی ثبت می‌شود.
 *   ۲. پلاکِ دیده‌شده باید با پلاکِ حواله یکی باشد. مغایرت یعنی راهبند بسته
 *      می‌ماند؛ نه هشدار، نه «ادامه بده».
 */
class GateController extends Controller
{
    private const ENTRY_QR = 'qr';

    private const ENTRY_MANUAL = 'manual';

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

        ScanTicket::issue($request, $appointment);

        return $this->result($request, $appointment, scanned: true);
    }

    /**
     * جستجوی پلاک — فقط برای دیدنِ وضعیت.
     *
     * نتیجه‌ی این مسیر بلیط اسکن صادر نمی‌کند، پس دکمه‌ی ورود روی آن خاموش
     * است. این عمدی است: راهی که «وقتی QR خوانده نمی‌شود» باز گذاشته شود،
     * همان راهی است که کامیونِ بی‌حواله از آن وارد می‌شود.
     */
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

    /**
     * ثبت ورود کامیون به محوطه.
     *
     * سه دروازه پشت سر هم؛ رد شدن از هرکدام یعنی راهبند بسته می‌ماند:
     * دسترسی، اسکن QR (یا استثنای ثبت‌شده)، و تطبیق پلاک.
     */
    public function checkIn(
        GateCheckInRequest $request,
        Appointment $appointment,
        TransitionAppointment $transition,
    ): RedirectResponse {
        if ($request->user()->cannot('transition', [$appointment, AppointmentStatus::CheckedIn])) {
            return back()->with('error', 'برای ثبت ورود دسترسی ندارید.');
        }

        $scanned = ScanTicket::isValid($request, $appointment);
        $reason = $request->string('override_reason')->trim()->toString();

        if (! $scanned) {
            $mayOverride = $request->user()->can(Permissions::GATE_MANUAL_OVERRIDE);

            $this->security->log(
                SecurityLogger::GATE_NO_QR,
                $appointment->ulid,
                $appointment,
                ['allowed' => $mayOverride && $reason !== '', 'reason' => $reason ?: null],
                $request,
            );

            if (! $mayOverride) {
                return back()->with(
                    'error',
                    'ورود فقط با اسکن QR ثبت می‌شود. اگر کد راننده خوانده نمی‌شود، با مسئول شیفت تماس بگیرید.',
                );
            }

            if ($reason === '') {
                return back()->with('error', 'برای ثبت ورود بدون اسکن، نوشتن دلیل الزامی است.');
            }
        }

        $plateCheck = $this->verifyPlate($request, $appointment);

        if ($plateCheck !== null) {
            return back()->with('error', $plateCheck);
        }

        try {
            $transition(
                $appointment,
                AppointmentStatus::CheckedIn,
                Actor::user($request->user(), $request->ip()),
            );
        } catch (InvalidStateTransition|TransitionBlocked $e) {
            return back()->with('error', $e->getMessage());
        }

        $appointment->forceFill([
            'qr_used_at' => now(),
            'gate_entry_method' => $scanned ? self::ENTRY_QR : self::ENTRY_MANUAL,
            'gate_observed_plate' => $appointment->truck?->plate_key,
            'gate_override_reason' => $scanned ? null : $reason,
            'gate_override_by_user_id' => $scanned ? null : $request->user()->id,
        ])->save();

        ScanTicket::consume($request, $appointment);

        return redirect()
            ->route('staff.gate.index')
            ->with('success', 'ورود نوبت '.$appointment->number.' ثبت شد.');
    }

    /**
     * تطبیق پلاک. اگر خطایی برگردد، ورود ثبت نمی‌شود.
     *
     * وقتی پلاک‌خوان مقدار فرستاده باشد، خودِ سرور مقایسه می‌کند و تأیید
     * چشمیِ نگهبان اصلاً خوانده نمی‌شود — دستگاه تبانی نمی‌کند.
     */
    private function verifyPlate(GateCheckInRequest $request, Appointment $appointment): ?string
    {
        $expected = $appointment->truck?->plate_key;
        $observed = $request->string('observed_plate')->trim()->toString();

        if ($observed !== '') {
            $normalised = PlateNumber::normalizeKey($observed);

            if ($normalised === $expected) {
                return null;
            }

            $this->security->log(
                SecurityLogger::GATE_PLATE_MISMATCH,
                $appointment->ulid,
                $appointment,
                ['expected' => $expected, 'observed' => $normalised ?? $observed, 'source' => 'device'],
                $request,
            );

            return 'پلاک خوانده‌شده با پلاک حواله یکی نیست. راهبند باز نمی‌شود؛ موضوع به حراست گزارش شد.';
        }

        if ($request->boolean('plate_match')) {
            return null;
        }

        $this->security->log(
            SecurityLogger::GATE_PLATE_MISMATCH,
            $appointment->ulid,
            $appointment,
            ['expected' => $expected, 'observed' => null, 'source' => 'guard'],
            $request,
        );

        return 'مغایرت پلاک ثبت شد و ورود انجام نشد.';
    }

    private function result(
        Request $request,
        ?Appointment $appointment,
        ?string $error = null,
        bool $scanned = false,
    ): Response {
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
                        // بدون اسکن، دکمه‌ی ورود فقط برای دارندگان استثنا باز است
                        'scanned' => $scanned,
                        'needs_override' => ! $scanned,
                        'may_override' => $request->user()->can(Permissions::GATE_MANUAL_OVERRIDE),
                        'expected_plate' => $appointment->truck?->plate(),
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
