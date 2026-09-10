<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Sms\SmsService;
use App\Domain\Sms\WeightAlertRecipients;
use App\Events\WeightDiscrepancyDetected;
use App\Models\Appointment;
use App\Support\Digits;
use App\Support\Jalali;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * مغایرت وزن، خبرِ مدیر است.
 *
 * برگه‌ی خروج خودش قفل می‌شود و کامیون بیرون نمی‌رود؛ ولی قفل به تنهایی
 * یعنی یک کامیونِ ایستاده در محوطه و یک اپراتور که باید تصمیم بگیرد. کسی
 * که تصمیم می‌گیرد باید همان لحظه بداند، نه فردا در گزارش.
 */
class AlertManagersOfWeightDiscrepancy implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly SmsService $sms) {}

    public function handle(WeightDiscrepancyDetected $event): void
    {
        $appointment = Appointment::with(['driver', 'truck.truckType', 'product', 'factory'])
            ->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        $kg = fn (?float $value) => $value === null
            ? '—'
            : Digits::toPersian(number_format($value));

        $variables = [
            'kind' => 'اضافه‌بار',
            'number' => $appointment->number,
            'date' => Jalali::date($appointment->date),
            'plate' => $appointment->truck?->plate()->full() ?? '—',
            'driver' => $appointment->driver?->displayName() ?? '—',
            'product' => $appointment->product?->name ?? '—',
            'net' => $kg($event->netKg),
            'expected' => $kg($event->capacityKg),
            'variance' => $kg($event->overloadKg),
            'reason' => $event->reason,
        ];

        foreach (WeightAlertRecipients::all() as $recipient) {
            $this->sms->queueTemplate('weight.discrepancy.manager', $recipient, $variables, $appointment);
        }
    }
}
