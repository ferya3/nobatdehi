<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Appointment\Enums\AppointmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentTransition extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_status' => AppointmentStatus::class,
            'to_status' => AppointmentStatus::class,
            'is_rollback' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function actorName(): string
    {
        return $this->user?->name
            ?? $this->driver?->displayName()
            ?? $this->actor_label
            ?? 'سامانه';
    }
}
