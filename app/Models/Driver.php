<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Support\Mobile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Driver extends Authenticatable
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'mobile_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'app_last_seen_at' => 'datetime',
            'is_blocked' => 'boolean',
        ];
    }

    /**
     * راننده رمز عبور ندارد — ورودش فقط با کد یک‌بارمصرف است.
     *
     * SessionGuard هنگام ساخت کوکی «مرا به خاطر بسپار» هش رمز را می‌خواند تا
     * تغییر رمز، کوکی‌های قدیمی را باطل کند. اینجا رمزی وجود ندارد، پس رشته‌ی
     * خالی برمی‌گردانیم؛ اعتبار کوکی را remember_token تأمین می‌کند که در
     * خروج از حساب عوض می‌شود.
     *
     * بدون این، ورود یک راننده‌ی تکراری با MissingAttributeException می‌شکست.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function trucks(): BelongsToMany
    {
        return $this->belongsToMany(Truck::class)->withPivot('last_used_at');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function activeAppointments(): HasMany
    {
        return $this->appointments()->whereIn('status', AppointmentStatus::activeValues());
    }

    public function setMobileAttribute(?string $value): void
    {
        $this->attributes['mobile'] = Mobile::normalize($value) ?? $value;
    }

    public function getPrettyMobileAttribute(): string
    {
        return Mobile::pretty((string) $this->mobile);
    }

    public function displayName(): string
    {
        return $this->name ?: 'راننده '.$this->pretty_mobile;
    }
}
