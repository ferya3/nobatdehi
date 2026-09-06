<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpRequest extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->verified_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture();
    }
}
