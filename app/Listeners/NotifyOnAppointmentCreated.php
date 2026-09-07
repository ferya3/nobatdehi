<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Queue\QueueService;
use App\Domain\Sms\ManagerRecipients;
use App\Domain\Sms\SmsService;
use App\Events\AppointmentCreated;
use App\Events\QueueChanged;
use App\Models\Appointment;
use App\Support\Digits;
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

    public function __construct(
        private readonly SmsService $sms,
        private readonly QueueService $queue,
    ) {}

    public function handle(AppointmentCreated $event): void
    {
        $appointment = Appointment::with(['driver', 'truck.truckType', 'product', 'factory'])
            ->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        $schedule = $this->queue->plannedSchedule($appointment);

        $variables = [
            'number' => $appointment->number,
            'plate' => $appointment->truck->plate()->full(),
            'driver' => $appointment->driver->displayName(),
            'product' => $appointment->product->name,
            'date' => Jalali::date($appointment->date),
            'time' => substr((string) $appointment->start_time, 0, 5),
            'factory' => $appointment->factory->name,

            // زمان همان لحظه‌ی ثبت اعلام می‌شود؛ راننده نباید تا صبح روز
            // نوبت منتظر بماند تا بفهمد کارش چقدر طول می‌کشد.
            'loading_minutes' => Digits::toPersian((string) $schedule['loading_minutes']),
            'ends_at' => Digits::toPersian($schedule['ends_at']),
        ];

        $this->sms->queueTemplate(
            'appointment.created.driver',
            $appointment->driver->mobile,
            $variables,
            $appointment,
        );

        foreach (ManagerRecipients::all() as $recipient) {
            $this->sms->queueTemplate('appointment.created.manager', $recipient, $variables, $appointment);
        }

        QueueChanged::dispatch(
            $appointment->factory_id,
            $appointment->date->toDateString(),
            $appointment->number,
            $appointment->status->value,
        );
    }
}
