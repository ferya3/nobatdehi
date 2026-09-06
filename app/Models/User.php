<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Mobile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * کاربر داخلی کارخانه: اپراتور، نگهبان، باسکول، انبار، مدیر، مدیرعامل.
 * راننده کاربر این جدول نیست — مدل Driver با guard جداگانه است.
 */
class User extends Authenticatable
{
    use HasRoles, Notifiable;

    protected $fillable = ['factory_id', 'name', 'email', 'mobile', 'password', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    /**
     * کاربر بدون کارخانه (مدیر ارشد سامانه) به همه‌ی کارخانه‌ها دسترسی دارد.
     */
    public function belongsToFactory(int $factoryId): bool
    {
        return $this->factory_id === null || $this->factory_id === $factoryId;
    }

    public function setMobileAttribute(?string $value): void
    {
        $this->attributes['mobile'] = $value ? (Mobile::normalize($value) ?? $value) : null;
    }
}
