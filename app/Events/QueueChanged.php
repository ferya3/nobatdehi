<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * صف یک کارخانه تغییر کرد.
 *
 * عمداً هیچ داده‌ای جز شناسه‌ها حمل نمی‌کند: پنل با دریافت این رویداد خودش
 * داده‌ی تازه را می‌گیرد. اینطور نه payload حساسی از کانال عبور می‌کند و نه
 * لازم است شکل داده در دو جا نگه داشته شود.
 */
class QueueChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $factoryId,
        public readonly string $date,
        public readonly ?int $appointmentNumber = null,
        public readonly ?string $toStatus = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("factory.{$this->factoryId}.queue"),
            new PrivateChannel("factory.{$this->factoryId}.dashboard"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.changed';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'date' => $this->date,
            'number' => $this->appointmentNumber,
            'status' => $this->toStatus,
        ];
    }
}
