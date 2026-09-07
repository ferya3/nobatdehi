<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Sms\ManagerRecipients;
use App\Domain\Sms\SmsService;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use App\Support\Jalali;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * نوبتی بسته شد — چه راننده بسته باشد، چه کارخانه.
 *
 * دو مخاطب با دو منطق متفاوت:
 *
 * راننده فقط وقتی خبر می‌گیرد که خودش لغو نکرده باشد. پیامکِ «نوبتت لغو
 * شد» به کسی که سه ثانیه پیش دکمه‌ی لغو را زده، فقط اعتماد به پیامک‌های
 * سامانه را کم می‌کند.
 *
 * مدیر همیشه خبر می‌گیرد، از هر دو طرف. جای خالیِ یک نوبتِ لغوشده در برنامه
 * می‌ماند و پر نمی‌شود؛ کسی که باید بداند ظرفیت امروز چه شد، همان مدیر است.
 * و لغوِ پی‌درپیِ یک راننده الگویی است که تا کسی نبیندش، ادامه دارد.
 */
class NotifyOnAppointmentCancelled implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly SmsService $sms) {}

    public function handle(AppointmentTransitioned $event): void
    {
        $closing = [
            AppointmentStatus::Cancelled->value,
            AppointmentStatus::Rejected->value,
        ];

        if (! in_array($event->toStatus, $closing, true)) {
            return;
        }

        $appointment = Appointment::with(['driver', 'truck', 'product', 'factory'])
            ->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        $byDriver = $appointment->cancelled_by_type === Actor::TYPE_DRIVER;

        $variables = [
            'number' => $appointment->number,
            'date' => Jalali::date($appointment->date),
            'time' => substr((string) $appointment->start_time, 0, 5),
            'plate' => $appointment->truck?->plate()->full() ?? '—',
            'driver' => $appointment->driver?->displayName() ?? '—',
            'product' => $appointment->product?->name ?? '—',
            // «لغو توسط راننده» یا «لغو توسط اپراتور فلانی» — همان برچسبی
            // که در پنل هم دیده می‌شود، تا دو روایت از یک اتفاق نساخته باشیم.
            'by' => $appointment->cancelledByLabel() ?? 'سامانه',
            'reason' => $appointment->cancel_reason ?: '—',
        ];

        if (! $byDriver && $appointment->driver !== null) {
            $this->sms->queueTemplate(
                'appointment.cancelled',
                $appointment->driver->mobile,
                $variables,
                $appointment,
            );
        }

        foreach (ManagerRecipients::all() as $recipient) {
            $this->sms->queueTemplate('appointment.cancelled.manager', $recipient, $variables, $appointment);
        }
    }
}
