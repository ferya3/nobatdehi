<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AppointmentStatusChanged;
use App\Events\AppointmentTransitioned;
use App\Events\QueueChanged;
use App\Models\Appointment;
use Throwable;

/**
 * هر تغییر وضعیت را بلافاصله به پنل‌های باز اطلاع می‌دهد.
 *
 * صف‌شده نیست: اگر بود، خوابیدنِ worker یعنی پنل اپراتور بی‌صدا از کار
 * می‌افتد. به‌جایش خطای انتشار گرفته و گزارش می‌شود تا کندی یا خرابی
 * WebSocket، ثبت وضعیت توسط اپراتور را خراب نکند.
 */
class BroadcastQueueChange
{
    public function handle(AppointmentTransitioned $event): void
    {
        $appointment = Appointment::with('loadingPoint')->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        try {
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
        } catch (Throwable $e) {
            // Reverb در دسترس نیست: وضعیت ثبت شده و همان مهم است
            report($e);
        }
    }
}
