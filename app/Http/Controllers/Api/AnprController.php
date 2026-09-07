<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Gate\PlateCapture;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAnprReadingRequest;
use App\Models\Factory;
use App\Models\PlateReading;
use Illuminate\Http\JsonResponse;

/**
 * ورودیِ دوربین پلاک‌خوان شبکه‌ای.
 *
 * دوربین در تنظیماتش یک آدرس HTTP می‌گیرد و هر بار که پلاکی می‌بیند، همان
 * را صدا می‌زند. جواب عمداً کوتاه و بی‌جزئیات است: دستگاهی که در محوطه است
 * نباید بتواند از پاسخِ این مسیر بفهمد چه نوبتی امروز ثبت شده.
 */
class AnprController extends Controller
{
    public function __construct(private readonly PlateCapture $capture) {}

    public function store(StoreAnprReadingRequest $request): JsonResponse
    {
        // دستگاه کاربر نیست، پس کارخانه‌اش از session درنمی‌آید. توکن هم مثل
        // بقیه‌ی تنظیمات یکی است برای کل نصب — همان فرض تک‌کارخانه‌ای که در
        // کل پنل هست. روزی که چند کارخانه لازم شد، توکن باید per-factory شود.
        $factory = Factory::where('is_active', true)->orderBy('id')->firstOrFail();

        $reading = $this->capture->record(
            factory: $factory,
            source: PlateReading::SOURCE_ANPR,
            rawPlate: $request->string('plate')->trim()->toString() ?: null,
            image: $request->file('image_file'),
            imageData: $request->string('image')->toString() ?: null,
            confidence: $request->has('confidence') ? $request->integer('confidence') : null,
            lane: $request->string('lane')->trim()->toString() ?: null,
            deviceName: $request->string('device')->trim()->toString() ?: null,
            capturedAt: $request->capturedAt(),
        );

        return response()->json([
            'id' => $reading->id,
            // دستگاه فقط باید بداند خواندنش رسید و قابل فهم بود یا نه
            'recognised' => ! $reading->isUnreadable(),
        ], 201);
    }
}
