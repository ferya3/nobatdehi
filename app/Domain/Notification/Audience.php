<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Builder;

/**
 * چه کسانی این اعلان را می‌گیرند.
 *
 * «همه» عمداً یعنی همه‌ی راننده‌هایی که تا حالا نوبت گرفته‌اند و مسدود
 * نیستند — نه هر شماره‌ای که یک بار کد ورود خواسته و رها کرده. فرستادن
 * اعلان به کسی که هیچ‌وقت با کارخانه کار نکرده، مزاحمت است.
 */
enum Audience: string
{
    case All = 'all';
    case Today = 'today';
    case InQueue = 'in_queue';
    case One = 'one';

    public function label(): string
    {
        return match ($this) {
            self::All => 'همه‌ی راننده‌ها',
            self::Today => 'نوبت‌داران امروز',
            self::InQueue => 'راننده‌های داخل صف',
            self::One => 'یک راننده',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::All => 'هر راننده‌ای که تا امروز دست‌کم یک نوبت گرفته و مسدود نیست',
            self::Today => 'راننده‌هایی که نوبتِ فعالِ امروز دارند',
            self::InQueue => 'راننده‌هایی که همین حالا در محوطه یا روی لاین هستند',
            self::One => 'فقط شماره‌ای که وارد می‌کنید',
        };
    }

    /** @return array<int, array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $a) => [
            'value' => $a->value,
            'label' => $a->label(),
            'description' => $a->description(),
        ], self::cases());
    }

    /**
     * پرس‌وجوی راننده‌های این مخاطب.
     *
     * @param  string|null  $mobile  فقط برای One معنا دارد
     */
    public function query(int $factoryId, ?string $mobile = null): Builder
    {
        $drivers = Driver::query()->where('is_blocked', false);

        return match ($this) {
            self::One => $drivers->where('mobile', $mobile),

            self::All => $drivers->whereIn(
                'id',
                Appointment::where('factory_id', $factoryId)->select('driver_id'),
            ),

            self::Today => $drivers->whereIn(
                'id',
                Appointment::where('factory_id', $factoryId)
                    ->whereDate('date', today())
                    ->whereIn('status', AppointmentStatus::activeValues())
                    ->select('driver_id'),
            ),

            self::InQueue => $drivers->whereIn(
                'id',
                Appointment::where('factory_id', $factoryId)
                    ->whereIn('status', [
                        AppointmentStatus::CheckedIn->value,
                        AppointmentStatus::Loading->value,
                        AppointmentStatus::Loaded->value,
                    ])
                    ->select('driver_id'),
            ),
        };
    }
}
