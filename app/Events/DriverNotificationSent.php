<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DriverNotification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * یک اعلان تازه برای راننده — روی همان کانال خصوصیِ خودش.
 *
 * این راهِ فوری است. برنامه‌ی اندروید یک اتصال دائم نگه می‌دارد و همین
 * رویداد را در همان ثانیه می‌گیرد. سر زدنِ دوره‌ای هم سرِ جایش می‌ماند:
 * گوشی‌های ارزان و مدیرهای باتریِ سخت‌گیر، سرویس را می‌کشند و آن‌وقت
 * تنها چیزی که باقی می‌ماند همان سر زدن است.
 */
class DriverNotificationSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly DriverNotification $notification) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("driver.{$this->notification->driver_id}")];
    }

    public function broadcastAs(): string
    {
        return 'driver.notification';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'title' => $this->notification->title,
            'body' => $this->notification->body,
            'path' => $this->notification->path,
            'kind' => $this->notification->kind,
        ];
    }
}
