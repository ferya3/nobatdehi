<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SmsMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
