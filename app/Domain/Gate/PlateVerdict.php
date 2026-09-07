<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Models\PlateReading;

/**
 * نتیجه‌ی تطبیق پلاک: چه شد، و چه کسی/چه چیزی تصمیم گرفت.
 *
 * source همان چیزی است که بعداً در ممیزی اهمیت دارد. «پلاک مطابق بود» بدون
 * اینکه بدانیم دوربین گفته یا نگهبان تیک زده، در بازبینیِ یک حادثه به درد
 * نمی‌خورد.
 */
final class PlateVerdict
{
    /** دوربین شبکه‌ای پلاک‌خوان */
    public const BY_ANPR = 'anpr';

    /** دوربین ایستگاه نگهبانی */
    public const BY_STATION = 'station';

    /** رشته‌ی پلاکی که دستگاه مستقیم فرستاده */
    public const BY_DEVICE = 'device';

    /** تأیید چشمی نگهبان */
    public const BY_GUARD = 'guard';

    private function __construct(
        public readonly bool $passed,
        public readonly string $source,
        public readonly ?string $expected,
        public readonly ?string $observed,
        public readonly ?PlateReading $reading,
        public readonly ?string $message,
    ) {}

    public static function pass(
        string $source,
        ?string $expected,
        ?string $observed = null,
        ?PlateReading $reading = null,
    ): self {
        return new self(true, $source, $expected, $observed, $reading, null);
    }

    public static function fail(
        string $source,
        ?string $expected,
        ?string $observed,
        string $message,
        ?PlateReading $reading = null,
    ): self {
        return new self(false, $source, $expected, $observed, $reading, $message);
    }

    /** @return array<string, mixed> برای لاگ امنیتی */
    public function context(): array
    {
        return array_filter([
            'expected' => $this->expected,
            'observed' => $this->observed,
            'source' => $this->source,
            'reading_id' => $this->reading?->id,
            'confidence' => $this->reading?->confidence,
        ], static fn ($value) => $value !== null);
    }
}
