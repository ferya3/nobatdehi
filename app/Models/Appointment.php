<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Support\Digits;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property AppointmentStatus $status
 */
class Appointment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    /** ULID فقط برای ستون عمومی، نه کلید اصلی */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'date' => 'date',
            'qr_used_at' => 'datetime',
            'waiting_at' => 'datetime',
            'called_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'loading_started_at' => 'datetime',
            'loading_completed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- روابط

    public function factory(): BelongsTo
    {
        return $this->belongsTo(Factory::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function truck(): BelongsTo
    {
        return $this->belongsTo(Truck::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'slot_id');
    }

    public function loadingPoint(): BelongsTo
    {
        return $this->belongsTo(LoadingPoint::class);
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(AppointmentTransition::class)->orderBy('created_at');
    }

    public function loadingRecord(): HasOne
    {
        return $this->hasOne(LoadingRecord::class);
    }

    // ---------------------------------------------------------------- Scope

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::activeValues());
    }

    public function scopeForDate(Builder $query, string|CarbonImmutable $date): Builder
    {
        return $query->whereDate('date', $date instanceof CarbonImmutable ? $date->toDateString() : $date);
    }

    public function scopeOnSite(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AppointmentStatus::CheckedIn->value,
            AppointmentStatus::Loading->value,
            AppointmentStatus::Loaded->value,
        ]);
    }

    /** ترتیب طبیعی صف: زمان اسلات، بعد شماره نوبت */
    public function scopeQueueOrder(Builder $query): Builder
    {
        return $query->orderBy('start_time')->orderBy('number');
    }

    // -------------------------------------------------------------- کمکی‌ها

    public function label(): string
    {
        return '#'.Digits::toPersian($this->number);
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->date->format('Y-m-d').' '.substr((string) $this->start_time, 0, 5),
        );
    }

    public function timeRange(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    /** مدت انتظار: از ورود تا شروع بارگیری (دقیقه) */
    public function waitMinutes(): ?int
    {
        if (! $this->checked_in_at || ! $this->loading_started_at) {
            return null;
        }

        return (int) $this->checked_in_at->diffInMinutes($this->loading_started_at);
    }

    /** مدت بارگیری (دقیقه) */
    public function loadingMinutes(): ?int
    {
        if (! $this->loading_started_at || ! $this->loading_completed_at) {
            return null;
        }

        return (int) $this->loading_started_at->diffInMinutes($this->loading_completed_at);
    }
}
