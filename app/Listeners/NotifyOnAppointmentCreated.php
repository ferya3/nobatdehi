<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Sms\SmsService;
use App\Events\AppointmentCreated;
use App\Events\QueueChanged;
use App\Models\Appointment;
use App\Support\Jalali;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * پس از صدور نوبت: پیامک به راننده، پیامک به مدیر، اطلاع به پنل اپراتور.
 *
 * هیچ‌کدام داخل Request صدور نوبت انجام نمی‌شود.
 */
class NotifyOnAppointmentCreated implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly SmsService $sms) {}

    public function handle(AppointmentCreated $event): void
    {
        $appointment = Appointment::with(['driver', 'truck', 'product', 'factory'])
            ->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        $variables = [
            'number' => $appointment->number,
            'plate' => $appointment->truck->plate()->full(),
            'driver' => $appointment->driver->displayName(),
            'product' => $appointment->product->name,
            'date' => Jalali::date($appointment->date),
            'time' => substr((string) $appointment->start_time, 0, 5),
            'factory' => $appointment->factory->name,
        ];

        $this->sms->queueTemplate(
            'appointment.created.driver',
            $appointment->driver->mobile,
            $variables,
            $appointment,
        );

        foreach ((array) config('sms.manager_recipients', []) as $recipient) {
            $this->sms->queueTemplate('appointment.created.manager', trim($recipient), $variables, $appointment);
        }

        QueueChanged::dispatch(
            $appointment->factory_id,
            $appointment->date->toDateString(),
            $appointment->number,
            $appointment->status->value,
        );
    }
}
