<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
