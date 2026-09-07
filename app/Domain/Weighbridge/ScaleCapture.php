<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

use App\Events\ScaleRead;
use App\Models\Factory;
use App\Models\ScaleReading;
use Carbon\CarbonImmutable;

/**
 * ثبت یک عددِ باسکول.
 *
 * عمداً هر خواندنی را می‌نویسد، حتی ناپایدار و حتی صفر. «کدام عدد قابل ثبت
 * است» تصمیمِ لحظه‌ی ثبت وزن است و نه لحظه‌ی دریافت — اگر همین‌جا فیلتر
 * کنیم، دیگر نمی‌شود فهمید باسکول چند بار نوسان کرد تا آرام بگیرد.
 */
final class ScaleCapture
{
    public function record(
        Factory $factory,
        string $scaleName,
        float $weightKg,
        bool $isStable,
        ?string $unit = null,
        ?string $deviceName = null,
        ?string $rawFrame = null,
        ?CarbonImmutable $readAt = null,
    ): ScaleReading {
        $reading = ScaleReading::create([
            'factory_id' => $factory->id,
            'scale_name' => mb_substr($scaleName, 0, 64),
            'device_name' => $deviceName !== null ? mb_substr($deviceName, 0, 64) : null,
            'weight_kg' => round($weightKg, 2),
            'unit' => $unit !== null && $unit !== '' ? mb_substr($unit, 0, 8) : 'kg',
            'is_stable' => $isStable,
            'raw_frame' => $rawFrame !== null ? mb_substr($rawFrame, 0, 128) : null,
            'read_at' => $readAt ?? now(),
        ]);

        // صفحه‌ی باسکول باید عدد را همان لحظه ببیند، نه با تأخیر polling
        ScaleRead::dispatch($reading);

        return $reading;
    }
}
