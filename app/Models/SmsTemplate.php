<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
