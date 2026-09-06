<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppointmentSlot extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'capacity' => 'integer',
            'reserved_count' => 'integer',
            'is_blocked' => 'boolean',
        ];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'slot_id');
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->reserved_count);
    }

    public function isFull(): bool
    {
        return $this->remaining() <= 0;
    }

    /** لحظه‌ی شروع اسلات در تایم‌زون برنامه */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->date->format('Y-m-d').' '.substr((string) $this->start_time, 0, 5),
        );
    }

    public function isBookable(): bool
    {
        return ! $this->is_blocked && ! $this->isFull();
    }
}
