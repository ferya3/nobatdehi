<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScaleReading extends Model
{
    /**
     * عمرِ یک خواندن برای ثبت وزن.
     *
     * کوتاه‌تر از بلیط اسکن گیت و عمداً: وزن هر لحظه عوض می‌شود. عددی که دو
     * دقیقه پیش خوانده شده، وزنِ همان کامیون نیست — شاید اصلاً کامیون بعدی
     * روی باسکول است.
     */
    public const FRESH_SECONDS = 90;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'weight_kg' => 'decimal:2',
            'is_stable' => 'boolean',
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

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where('read_at', '>=', now()->subSeconds(self::FRESH_SECONDS));
    }

    public function isFresh(): bool
    {
        return $this->read_at !== null
            && $this->read_at->greaterThanOrEqualTo(now()->subSeconds(self::FRESH_SECONDS));
    }
}
