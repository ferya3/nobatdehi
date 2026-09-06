<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * فقط شناسه حمل می‌شود، نه مدل: Listener های صف‌شده باید همیشه
 * آخرین وضعیت را از دیتابیس بخوانند، نه یک snapshot کهنه را.
 */
class AppointmentTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $appointmentId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {}
}
