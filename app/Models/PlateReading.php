<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlateReading extends Model
{
    /** دوربین شبکه‌ای پلاک‌خوان، خودش فرستاده */
    public const SOURCE_ANPR = 'anpr';

    /** نگهبان از ایستگاه عکس گرفته */
    public const SOURCE_STATION = 'station';

    /**
     * عمرِ یک خواندن برای بازکردن راهبند.
     *
     * همان ۱۰ دقیقه‌ی ScanTicket است و به همان دلیل: خواندنِ دیروزِ همین
     * پلاک نباید امروز راهبند را باز کند. بدون این، «پلاک را دوربین تأیید
     * کرد» به یک مهر همیشگی تبدیل می‌شود.
     */
    public const FRESH_SECONDS = 600;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'confidence' => 'integer',
        ];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('captured_at', '>=', now()->subSeconds(self::FRESH_SECONDS));
    }

    public function isFresh(): bool
    {
        return $this->captured_at !== null
            && $this->captured_at->greaterThanOrEqualTo(now()->subSeconds(self::FRESH_SECONDS));
    }

    /** دستگاه پلاک را خواند ولی ما نتوانستیم بفهمیم چه بوده */
    public function isUnreadable(): bool
    {
        return $this->plate_key === null;
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_ANPR => 'دوربین پلاک‌خوان',
            self::SOURCE_STATION => 'دوربین ایستگاه نگهبانی',
            default => $this->source,
        };
    }
}
