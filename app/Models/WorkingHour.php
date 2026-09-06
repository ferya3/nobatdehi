<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkingHour extends Model
{
    protected $guarded = ['id'];

    /** 0 = شنبه ... 6 = جمعه */
    public const WEEKDAYS = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_open' => 'boolean',
            'capacity_per_slot' => 'integer',
        ];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function getWeekdayNameAttribute(): string
    {
        return self::WEEKDAYS[$this->weekday] ?? '—';
    }
}
