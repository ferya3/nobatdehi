<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

use App\Models\Factory;
use App\Models\ScaleReading;

/**
 * از کجا آمد این عدد.
 *
 * دو حالت، و هیچ حالت سومی: یا ردیفی در scale_readings پشتش هست، یا کسی
 * تایپش کرده و دلیلش را نوشته. قبلاً حالت سومی وجود داشت — «اپراتور تیک
 * زد که از باسکول آمده» — که در ممیزی هیچ ارزشی نداشت.
 */
final class WeightSource
{
    public const DEVICE = 'device';

    public const MANUAL = 'manual';

    private function __construct(
        public readonly string $kind,
        public readonly float $weightKg,
        public readonly ?ScaleReading $reading,
        public readonly ?string $manualReason,
        public readonly ?string $error,
    ) {}

    public static function fromDevice(ScaleReading $reading): self
    {
        return new self(self::DEVICE, (float) $reading->weight_kg, $reading, null, null);
    }

    public static function fromOperator(float $weightKg, string $reason): self
    {
        return new self(self::MANUAL, $weightKg, null, $reason, null);
    }

    public static function rejected(string $error): self
    {
        return new self(self::MANUAL, 0.0, null, null, $error);
    }

    public function isRejected(): bool
    {
        return $this->error !== null;
    }

    /**
     * خواندنی که مرورگر به آن اشاره کرده، اگر واقعاً قابل استناد باشد.
     *
     * مرورگر فقط شناسه می‌فرستد و هرگز خودِ وزن را. اگر عدد از فرم خوانده
     * می‌شد، «مستقیم از باسکول» دوباره به یک رشته‌ی قابل تایپ برمی‌گشت.
     */
    public static function resolve(
        ScaleReading $reading,
        Factory $factory,
        bool $requireStable,
    ): self {
        if ($reading->factory_id !== $factory->id) {
            return self::rejected('این خواندن به باسکول این کارخانه تعلق ندارد.');
        }

        // وزن هر لحظه عوض می‌شود؛ عددِ دو دقیقه پیش وزنِ کامیون بعدی است
        if (! $reading->isFresh()) {
            return self::rejected('عدد باسکول قدیمی است. دوباره از روی نشان‌دهنده بگیرید.');
        }

        if ($requireStable && ! $reading->is_stable) {
            return self::rejected('عقربه‌ی باسکول هنوز آرام نگرفته است. چند لحظه صبر کنید.');
        }

        return self::fromDevice($reading);
    }
}
