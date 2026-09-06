<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarException extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_closed' => 'boolean',
            'capacity_per_slot' => 'integer',
        ];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }
}
