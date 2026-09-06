<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** وضعیت نوبت یک راننده عوض شد — برای صفحه‌ی پیگیری راننده */
class AppointmentStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $driverId,
        public readonly string $ulid,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly ?string $loadingPoint = null,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("driver.{$this->driverId}"),
            new PrivateChannel("appointment.{$this->ulid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'appointment.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'ulid' => $this->ulid,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'loading_point' => $this->loadingPoint,
        ];
    }
}
