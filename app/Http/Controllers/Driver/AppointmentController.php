<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Queue\QueueService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppointmentController extends Controller
{
    public function __construct(private readonly QueueService $queue) {}

    /** خانه‌ی راننده: نوبت فعال، یا دعوت به گرفتن نوبت */
    public function home(Request $request): Response
    {
        $driver = $this->driver($request);

        $active = $driver->activeAppointments()
            ->with(['product', 'truck.truckType', 'loadingPoint', 'factory'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $past = $driver->appointments()
            ->with(['product', 'truck'])
            ->whereNotIn('status', AppointmentStatus::activeValues())
            ->latest('date')
            ->limit(10)
            ->get();

        $factory = Factory::where('is_active', true)->orderBy('id')->first();

        return Inertia::render('Driver/Home', [
            'driver' => ['name' => $driver->name, 'mobile' => $driver->mobile],
            'active' => $active->map(fn (Appointment $a) => array_merge(
                (new AppointmentResource($a))->resolve($request),
                $this->liveStatus($a),
            )),
            'past' => AppointmentResource::collection($past)->resolve($request),
            'canBook' => $factory !== null && $active->count() < $factory->max_active_per_mobile,
            'activeLimit' => $factory?->max_active_per_mobile,
        ]);
    }

    /** رسید نوبت با QR */
    public function show(Request $request, Appointment $appointment): Response
    {
        $this->authorizeDriver($request, $appointment);

        $appointment->load(['product', 'truck.truckType', 'loadingPoint', 'factory', 'driver']);

        return Inertia::render('Driver/Appointments/Show', [
            'appointment' => array_merge(
                (new AppointmentResource($appointment))->resolve($request),
                $this->liveStatus($appointment),
            ),
            // Closure است، پس در partial reload روی 'appointment' اجرا نمی‌شود:
            // رفرش وضعیت نباید توکن QR قبلی را باطل کند.
            'qr' => fn () => $appointment->status->isActive() ? $this->qrPayload($appointment) : null,
        ]);
    }

    public function cancel(Request $request, Appointment $appointment, TransitionAppointment $transition): RedirectResponse
    {
        $this->authorizeDriver($request, $appointment);

        if (! $appointment->status->isActive()) {
            return back()->with('error', 'این نوبت دیگر قابل لغو نیست.');
        }

        if ($appointment->status->isOnSite()) {
            return back()->with('error', 'کامیون وارد محوطه شده است؛ لغو نوبت با اپراتور انجام می‌شود.');
        }

        try {
            $transition(
                $appointment,
                AppointmentStatus::Cancelled,
                Actor::driver($this->driver($request), $request->ip()),
                // دلیل خالی است: هویت لغوکننده را خود Actor حمل می‌کند و پنل
                // آن را به‌صورت «لغو توسط راننده» نشان می‌دهد.
            );
        } catch (InvalidStateTransition|TransitionBlocked $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('driver.home')->with('success', 'نوبت شما لغو شد.');
    }

    /** @return array<string, mixed> */
    private function liveStatus(Appointment $appointment): array
    {
        return [
            'is_today' => $appointment->date->isToday(),
            'ahead' => $this->queue->positionAhead($appointment),
            'eta_minutes' => $this->queue->estimatedWaitMinutes($appointment),
        ];
    }

    /** @return array<string, string> */
    private function qrPayload(Appointment $appointment): array
    {
        // توکن هر بار که رسید باز می‌شود از نو صادر می‌شود؛ توکن قبلی باطل است.
        ['token' => $token, 'hash' => $hash] = QrToken::issue($appointment);

        $appointment->forceFill(['qr_token_hash' => $hash, 'qr_used_at' => null])->save();

        return ['token' => $token];
    }

    private function driver(Request $request): Driver
    {
        return $request->user('driver');
    }

    private function authorizeDriver(Request $request, Appointment $appointment): void
    {
        // نوبت راننده‌ی دیگر «وجود ندارد»، نه «ممنوع است» — شماره‌ی نوبت لو نرود.
        if ($appointment->driver_id !== $this->driver($request)->id) {
            throw new NotFoundHttpException();
        }
    }
}
