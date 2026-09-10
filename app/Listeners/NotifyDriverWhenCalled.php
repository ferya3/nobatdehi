<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Sms\SmsService;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * وقتی اپراتور کامیونی را فرا می‌خواند، راننده پیامک می‌گیرد.
 *
 * راننده ممکن است برنامه را باز نداشته باشد؛ همان تماس تلفنی‌ای که این سامانه
 * قرار است حذف کند، دقیقاً از همین‌جا شروع می‌شود.
 */
class NotifyDriverWhenCalled implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly SmsService $sms) {}

    public function handle(AppointmentTransitioned $event): void
    {
        if ($event->toStatus !== AppointmentStatus::Called->value) {
            return;
        }

        $appointment = Appointment::with(['driver', 'truck', 'loadingPoint'])->find($event->appointmentId);

        if ($appointment?->driver === null) {
            return;
        }

        $this->sms->queueTemplate('appointment.called', $appointment->driver->mobile, [
            'number' => $appointment->number,
            'plate' => $appointment->truck?->plate()->full() ?? '—',
            'loading_point' => $appointment->loadingPoint?->name ?? 'محوطه بارگیری',
        ], $appointment);
    }
}
