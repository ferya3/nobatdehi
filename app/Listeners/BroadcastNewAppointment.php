<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AppointmentCreated;
use App\Events\QueueChanged;
use App\Models\Appointment;
use Throwable;

/**
 * نوبت تازه را بلافاصله به پنل‌های باز اطلاع می‌دهد.
 *
 * عمداً ShouldQueue نیست و عمداً از listener پیامک جدا شده.
 *
 * قبلاً این کار داخل NotifyOnAppointmentCreated بود که صف‌شده است؛ یعنی
 * زنده بودنِ پنل اپراتور به بالا بودنِ worker پیامک گره خورده بود. اگر
 * Horizon می‌خوابید یا یک job پیامک گیر می‌کرد، راننده نوبت می‌گرفت و در
 * پنل هیچ‌چیز نمی‌آمد — بدون هیچ خطایی، هیچ‌جا.
 *
 * پیامک می‌تواند صبر کند؛ صفِ کارخانه نه.
 */
class BroadcastNewAppointment
{
    public function handle(AppointmentCreated $event): void
    {
        $appointment = Appointment::find($event->appointmentId);

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
        } catch (Throwable $e) {
            /*
             * Reverb که در دسترس نباشد نباید ثبت نوبت راننده را خراب کند.
             * پنل روی polling پشتیبان می‌افتد و همان را هم به اپراتور
             * نشان می‌دهد.
             */
            report($e);
        }
    }
}
