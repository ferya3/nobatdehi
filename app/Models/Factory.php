<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factory extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'slot_minutes' => 'integer',
            'daily_capacity' => 'integer',
            'loading_lines' => 'integer',
            'avg_loading_minutes' => 'integer',
            'no_show_grace_minutes' => 'integer',
            'booking_horizon_days' => 'integer',
            'booking_lead_minutes' => 'integer',
            'max_active_per_mobile' => 'integer',
            'max_active_per_plate' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    public function calendarExceptions(): HasMany
    {
        return $this->hasMany(CalendarException::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function loadingPoints(): HasMany
    {
        return $this->hasMany(LoadingPoint::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(AppointmentSlot::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
