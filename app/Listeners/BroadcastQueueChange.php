<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AppointmentTransitioned;
use App\Events\AppointmentStatusChanged;
use App\Events\QueueChanged;
use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * هر تغییر وضعیت را به پنل‌های باز اطلاع می‌دهد.
 *
 * صف‌شده است تا کندی یا خرابی WebSocket، ثبت وضعیت توسط اپراتور را کند نکند.
 */
class BroadcastQueueChange implements ShouldQueue
{
    public int $tries = 3;

    public function handle(AppointmentTransitioned $event): void
    {
        $appointment = Appointment::with('loadingPoint')->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        QueueChanged::dispatch(
            $appointment->factory_id,
            $appointment->date->toDateString(),
            $appointment->number,
            $appointment->status->value,
        );

        AppointmentStatusChanged::dispatch(
            $appointment->driver_id,
            $appointment->ulid,
            $appointment->status->value,
            $appointment->status->label(),
            $appointment->loadingPoint?->name,
        );
    }
}
