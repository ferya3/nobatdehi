<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Weighbridge\ScaleCapture;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreScaleReadingRequest;
use App\Models\Factory;
use Illuminate\Http\JsonResponse;

/**
 * ورودیِ پلِ نشان‌دهنده‌ی باسکول.
 *
 * پل روی همان کامپیوترِ باسکول اجرا می‌شود، پورت COM را می‌خواند و هر
 * عددِ تازه را اینجا می‌فرستد. جواب عمداً کوتاه است: دستگاهی که در محوطه
 * است نباید از پاسخِ این مسیر بفهمد امروز چه حواله‌ای باز است.
 */
class ScaleController extends Controller
{
    public function __construct(private readonly ScaleCapture $capture) {}

    public function store(StoreScaleReadingRequest $request): JsonResponse
    {
        // پل کاربر نیست و کارخانه‌اش از session درنمی‌آید — همان فرض
        // تک‌کارخانه‌ای که در کل پنل هست.
        $factory = Factory::where('is_active', true)->orderBy('id')->firstOrFail();

        $reading = $this->capture->record(
            factory: $factory,
            scaleName: $request->string('scale')->trim()->toString(),
            weightKg: (float) $request->input('weight_kg'),
            isStable: $request->boolean('stable'),
            unit: $request->string('unit')->trim()->toString() ?: null,
            deviceName: $request->string('device')->trim()->toString() ?: null,
            rawFrame: $request->string('raw')->toString() ?: null,
        );

        return response()->json(['id' => $reading->id], 201);
    }
}
