<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadingRecord extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'empty_weight_kg' => 'decimal:2',
            'loaded_weight_kg' => 'decimal:2',
            'net_weight_kg' => 'decimal:2',
            'expected_net_kg' => 'decimal:2',
            'variance_kg' => 'decimal:2',
            'is_overload' => 'boolean',
            'tare_weighed_at' => 'datetime',
            'gross_weighed_at' => 'datetime',
            'exit_permit_issued_at' => 'datetime',
            'discrepancy_alerted_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function loadingPoint(): BelongsTo
    {
        return $this->belongsTo(LoadingPoint::class);
    }

    public function tareBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tare_by_user_id');
    }

    public function grossBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gross_by_user_id');
    }

    public function hasTare(): bool
    {
        return $this->empty_weight_kg !== null;
    }

    public function hasGross(): bool
    {
        return $this->loaded_weight_kg !== null;
    }
}
