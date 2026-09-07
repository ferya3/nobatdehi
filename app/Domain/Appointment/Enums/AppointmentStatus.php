<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Enums;

/**
 * چرخه‌ی عمر یک نوبت بارگیری.
 *
 * مسیر اصلی:
 *   BOOKED → WAITING → CALLED → CHECKED_IN → LOADING → LOADED → COMPLETED
 *
 * انتقال‌های مجاز در AppointmentStateMachine تعریف شده‌اند؛ هیچ جای دیگری
 * نباید مستقیماً status را بنویسد.
 */
enum AppointmentStatus: string
{
    case Booked = 'BOOKED';
    case Waiting = 'WAITING';
    case Called = 'CALLED';
    case CheckedIn = 'CHECKED_IN';
    case Loading = 'LOADING';
    case Loaded = 'LOADED';
    case Completed = 'COMPLETED';

    case Cancelled = 'CANCELLED';
    case NoShow = 'NO_SHOW';
    case Rejected = 'REJECTED';
    case Expired = 'EXPIRED';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'ثبت‌شده',
            self::Waiting => 'در انتظار',
            self::Called => 'فراخوانده‌شده',
            self::CheckedIn => 'وارد محوطه',
            self::Loading => 'در حال بارگیری',
            self::Loaded => 'بارگیری‌شده',
            self::Completed => 'تکمیل‌شده',
            self::Cancelled => 'لغوشده',
            self::NoShow => 'عدم حضور',
            self::Rejected => 'رد‌شده',
            self::Expired => 'منقضی',
        };
    }

    /** کلید رنگ در طراحی (design token) */
    public function tone(): string
    {
        return match ($this) {
            self::Booked => 'booked',
            self::Waiting => 'waiting',
            self::Called => 'called',
            self::CheckedIn => 'checkedin',
            self::Loading => 'loading',
            self::Loaded => 'loaded',
            self::Completed => 'completed',
            self::Cancelled, self::NoShow, self::Rejected, self::Expired => 'failed',
        };
    }

    /** آیا نوبت هنوز زنده است و ظرفیت اسلات را اشغال می‌کند؟ */
    public function isActive(): bool
    {
        return in_array($this, self::activeStatuses(), true);
    }

    /** آیا نوبت به پایان رسیده (چه موفق چه ناموفق)؟ */
    public function isFinal(): bool
    {
        return ! $this->isActive();
    }

    /** پایانِ ناموفق: نوبت بسته شد بدون اینکه بارگیری کامل شود. */
    public function isCancellation(): bool
    {
        return in_array($this, [self::Cancelled, self::Rejected, self::NoShow, self::Expired], true);
    }

    /**
     * آیا این نوبت هنوز جای خودش را روی لاین بارگیری نگه داشته؟
     *
     * تکمیل‌شده نگه می‌دارد — آن کامیون واقعاً آن بازه را گرفت و نوبت بعدی
     * نمی‌توانست هم‌زمان با او بارگیری شود. ولی لغوشده و عدم‌حضور نه: کامیونی
     * نیامده، لاین خالی مانده، و اگر آن بازه آزاد نشود ظرفیتِ آن روز برای
     * همیشه سوخته است.
     */
    public function holdsLine(): bool
    {
        return ! $this->isCancellation();
    }

    /**
     * وضعیت‌هایی که بازه‌شان روی لاین آزاد شده است.
     *
     * @return array<int, string>
     */
    public static function releasedValues(): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => ! $s->holdsLine()),
        ));
    }

    /** آیا کامیون فیزیکاً داخل کارخانه است؟ */
    public function isOnSite(): bool
    {
        return in_array($this, [self::CheckedIn, self::Loading, self::Loaded], true);
    }

    /**
     * وضعیت‌هایی که ظرفیت را اشغال می‌کنند.
     * آزادسازی ظرفیت فقط با خروج از این مجموعه اتفاق می‌افتد.
     *
     * @return array<int, self>
     */
    public static function activeStatuses(): array
    {
        return [
            self::Booked,
            self::Waiting,
            self::Called,
            self::CheckedIn,
            self::Loading,
            self::Loaded,
        ];
    }

    /** @return array<int, string> */
    public static function activeValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::activeStatuses());
    }

    /** @return array<int, array{value: string, label: string, tone: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'tone' => $s->tone()],
            self::cases(),
        );
    }
}
