<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LoadingPoint extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
