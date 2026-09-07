<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ScaleReading;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * باسکول یک عدد تازه داد.
 *
 * ShouldBroadcastNow و نه ShouldBroadcast: این رویداد باید همان لحظه به
 * صفحه برسد. اگر از صف رد شود، عددی که اپراتور می‌بیند چند ثانیه عقب است
 * و روی باسکول، چند ثانیه یعنی وزنِ کامیون قبلی.
 */
class ScaleRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly ScaleReading $reading) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("factory.{$this->reading->factory_id}.weighbridge")];
    }

    public function broadcastAs(): string
    {
        return 'scale.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->reading->id,
            'scale' => $this->reading->scale_name,
            'weight_kg' => (float) $this->reading->weight_kg,
            'is_stable' => $this->reading->is_stable,
            'read_at' => $this->reading->read_at?->toIso8601String(),
        ];
    }
}
