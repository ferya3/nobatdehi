<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Sms\SmsService;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * راننده باید بداند ایستگاه بعدی‌اش کجاست.
 *
 * دو لحظه‌ای که راننده در کامیون نشسته و منتظر است کسی چیزی بگوید:
 * وقتی نگهبان پلاکش را تأیید کرد و راهبند بالا رفت، و وقتی بارگیری‌اش
 * تمام شد. تا پیش از این هر دو بار، همان تماس تلفنی‌ای لازم بود که این
 * سامانه قرار است حذفش کند.
 *
 * متنِ هر دو در «تنظیمات ← پیامک‌ها» قابل ویرایش است؛ کارخانه‌ای که
 * باسکولش دو تا است یا مسیرش فرق دارد، خودش می‌نویسد.
 */
class NotifyDriverAtEachStation implements ShouldQueue
{
    public int $tries = 3;

    /** کدام وضعیت، کدام قالب */
    private const TEMPLATES = [
        AppointmentStatus::CheckedIn->value => 'appointment.checked-in',
        AppointmentStatus::Loaded->value => 'appointment.loaded',
    ];

    public function __construct(private readonly SmsService $sms) {}

    public function handle(AppointmentTransitioned $event): void
    {
        $template = self::TEMPLATES[$event->toStatus] ?? null;

        if ($template === null) {
            return;
        }

        $appointment = Appointment::with(['driver', 'truck', 'product', 'loadingPoint'])
            ->find($event->appointmentId);

        if ($appointment?->driver === null) {
            return;
        }

        $this->sms->queueTemplate($template, $appointment->driver->mobile, [
            'number' => $appointment->number,
            'plate' => $appointment->truck?->plate()->full() ?? '—',
            'product' => $appointment->product?->name ?? '—',
            'loading_point' => $appointment->loadingPoint?->name ?? 'محوطه بارگیری',
        ], $appointment);
    }
}
