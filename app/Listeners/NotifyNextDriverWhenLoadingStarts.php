<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Notification\DriverNotifier;
use App\Domain\Queue\QueueService;
use App\Domain\Sms\SmsService;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use App\Support\Digits;
use App\Support\Duration;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * وقتی کامیونی روی لاین می‌رود، نفر بعدی خبردار می‌شود.
 *
 * راننده‌ای که نمی‌داند چقدر باید صبر کند، یا موتورش را روشن نگه می‌دارد و
 * جلوی در می‌ماند، یا می‌رود و سرِ نوبتش نیست. هر دو همان چیزی است که این
 * سامانه قرار بود حذف کند. کافی است بداند «تا حدود یک ساعت و بیست دقیقه
 * دیگر» — همان مدتی که کامیونِ جلویی روی لاین است.
 *
 * تخمین است و نه قول: مدت بارگیریِ نوعِ کامیونِ جلویی. اگر آن کامیون طول
 * بکشد، اپراتور راننده را فرا می‌خواند و پیامک «نوبت شما فرا رسید» جداگانه
 * می‌رسد.
 */
class NotifyNextDriverWhenLoadingStarts implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(
        private readonly SmsService $sms,
        private readonly QueueService $queue,
        private readonly DriverNotifier $notifier,
    ) {}

    public function handle(AppointmentTransitioned $event): void
    {
        if ($event->toStatus !== AppointmentStatus::Loading->value) {
            return;
        }

        // بازگردانی هم به LOADING می‌رسد؛ آن اصلاحِ اشتباه است، نه شروعِ تازه
        if ($event->fromStatus === AppointmentStatus::Loaded->value) {
            return;
        }

        $current = Appointment::with(['truck.truckType', 'product', 'factory'])
            ->find($event->appointmentId);

        if ($current === null) {
            return;
        }

        $next = $this->queue->nextInLine($current);

        if ($next?->driver === null) {
            return;
        }

        // کارخانه‌ی چندلاینه پشت سر هم بارگیری شروع می‌کند؛ راننده‌ی بعدی
        // نباید برای هر کدام یک پیامک بگیرد.
        if ($next->next_up_notified_at !== null) {
            return;
        }

        $minutes = $current->expectedLoadingMinutes();

        // ارقام فارسی: پیامک وسط یک متن فارسی خوانده می‌شود و «08:20»
        // لاتین وسط آن، همان جایی است که چشم گیر می‌کند.
        $this->sms->queueTemplate('appointment.next-up', $next->driver->mobile, [
            'driver' => $next->driver->name ?: 'راننده',
            'number' => Digits::toPersian((string) $next->number),
            'ahead_number' => Digits::toPersian((string) $current->number),
            'eta' => Duration::human($minutes),
            'minutes' => Digits::toPersian((string) $minutes),
            'at' => Digits::toPersian(now()->addMinutes($minutes)->format('H:i')),
            'factory' => $next->factory?->name ?? '',
        ], $next);

        /*
         * همان خبر، روی برنامه‌ی اندروید.
         *
         * پیامک می‌رسد و باید برسد؛ این اضافه است و نه جایگزین. راننده‌ای که
         * برنامه را باز دارد، متن کامل و لینکِ نوبتش را هم دارد.
         */
        $this->notifier->send(
            driver: $next->driver,
            title: 'نوبت شما نزدیک است',
            body: sprintf(
                'نوبت جلوتر از شما شروع به بارگیری کرد. تا حدود %s دیگر نوبت شماست.',
                Duration::human($minutes),
            ),
            path: '/queue/appointments/'.$next->ulid,
            kind: 'next-up',
        );

        $next->forceFill(['next_up_notified_at' => now()])->save();
    }
}
