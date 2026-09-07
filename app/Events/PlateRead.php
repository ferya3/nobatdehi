<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlateReading;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * دوربین پلاک‌خوان یک پلاک خواند.
 *
 * روی کانال گیت پخش می‌شود تا صفحه‌ی نگهبانی همان لحظه نوبت را بالا بیاورد؛
 * وگرنه دوربین وصل است ولی نگهبان تا polling بعدی چیزی نمی‌بیند و عملاً
 * همان تایپ دستی را ادامه می‌دهد.
 *
 * خودِ عکس در payload نیست — فقط شناسه. عکس از مسیرِ دارای دسترسی گرفته
 * می‌شود، تا کانال به راهی برای بیرون‌کشیدن تصویر تبدیل نشود.
 */
class PlateRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly PlateReading $reading) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("factory.{$this->reading->factory_id}.gate")];
    }

    public function broadcastAs(): string
    {
        return 'plate.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->reading->id,
            'source' => $this->reading->source,
            'plate_key' => $this->reading->plate_key,
            'confidence' => $this->reading->confidence,
            'lane' => $this->reading->lane,
            'has_image' => $this->reading->image_path !== null,
            'captured_at' => $this->reading->captured_at?->toIso8601String(),
        ];
    }
}
