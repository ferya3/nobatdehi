<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TruckType extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'capacity_tons' => 'decimal:2',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function trucks(): HasMany
    {
        return $this->hasMany(Truck::class);
    }
}
