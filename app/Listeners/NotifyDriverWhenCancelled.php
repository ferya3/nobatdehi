<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Sms\SmsService;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use App\Support\Jalali;
use Illuminate\Contracts\Queue\ShouldQueue;

/** لغو یا رد نوبت توسط کارخانه — راننده باید بداند، نه اینکه سر ساعت برسد */
class NotifyDriverWhenCancelled implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly SmsService $sms) {}

    public function handle(AppointmentTransitioned $event): void
    {
        $cancelled = [AppointmentStatus::Cancelled->value, AppointmentStatus::Rejected->value];

        if (! in_array($event->toStatus, $cancelled, true)) {
            return;
        }

        $appointment = Appointment::with('driver')->find($event->appointmentId);

        if ($appointment?->driver === null) {
            return;
        }

        // اگر خود راننده لغو کرده، پیامک اضافه است
        $lastTransition = $appointment->transitions()->latest('id')->first();

        if ($lastTransition?->driver_id !== null) {
            return;
        }

        $this->sms->queueTemplate('appointment.cancelled', $appointment->driver->mobile, [
            'number' => $appointment->number,
            'date' => Jalali::date($appointment->date),
            'time' => substr((string) $appointment->start_time, 0, 5),
        ], $appointment);
    }
}
