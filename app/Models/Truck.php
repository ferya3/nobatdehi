<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Truck\PlateNumber;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Truck extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_blocked' => 'boolean'];
    }

    public function truckType(): BelongsTo
    {
        return $this->belongsTo(TruckType::class);
    }

    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class)->withPivot('last_used_at');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function activeAppointments(): HasMany
    {
        return $this->appointments()->whereIn('status', AppointmentStatus::activeValues());
    }

    public function plate(): PlateNumber
    {
        return new PlateNumber(
            $this->plate_two,
            $this->plate_letter,
            $this->plate_three,
            $this->plate_iran,
        );
    }

    public static function fromPlate(PlateNumber $plate, ?int $truckTypeId = null): self
    {
        return static::firstOrCreate(
            ['plate_key' => $plate->key()],
            [
                'plate_two' => $plate->two,
                'plate_letter' => $plate->letter,
                'plate_three' => $plate->three,
                'plate_iran' => $plate->iran,
                'truck_type_id' => $truckTypeId,
            ],
        );
    }
}
