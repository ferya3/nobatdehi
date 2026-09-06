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
}
