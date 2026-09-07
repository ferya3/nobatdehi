<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Appointment\Data\Actor;
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

    /**
     * برچسب فارسیِ «چه کسی نوبت را بست» — همان چیزی که در پنل دیده می‌شود.
     * برای نوبت‌های باز یا تکمیل‌شده null است.
     */
    public function cancelledByLabel(): ?string
    {
        if (! $this->status->isCancellation()) {
            return null;
        }

        $verb = $this->status === AppointmentStatus::NoShow ? 'ثبت عدم حضور' : 'لغو';

        return match ($this->cancelled_by_type) {
            Actor::TYPE_DRIVER => $verb.' توسط راننده',
            Actor::TYPE_STAFF => $verb.' توسط '.($this->cancelled_by_name ?: 'اپراتور'),
            Actor::TYPE_SYSTEM => $verb.' توسط سامانه',
            // نوبت‌های قبل از افزوده‌شدن این ستون‌ها
            default => null,
        };
    }

    /**
     * مدت مورد انتظار بارگیری این نوبت (دقیقه).
     *
     * ترتیب اولویت عمدی است: نوع کامیون دقیق‌ترین اطلاعات را دارد، بعد
     * محصول، و در آخر میانگین کارخانه به‌عنوان تور ایمنی.
     */
    public function expectedLoadingMinutes(): int
    {
        return $this->truck?->truckType?->loading_minutes
            ?? $this->product?->loading_minutes
            ?? (int) $this->factory->avg_loading_minutes;
    }

    /** مهلت حضور بعد از شروع ساعت نوبت (دقیقه) */
    public function graceMinutes(): int
    {
        return $this->truck?->truckType?->grace_minutes
            ?? (int) $this->factory->no_show_grace_minutes;
    }

    /** لحظه‌ای که بعد از آن، نیامدن راننده «عدم حضور» محسوب می‌شود */
    public function noShowDueAt(): CarbonImmutable
    {
        return $this->startsAt()->addMinutes($this->graceMinutes());
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
