<?php

declare(strict_types=1);

namespace App\Domain\Appointment;

use App\Domain\Appointment\Enums\AppointmentStatus as S;

/**
 * تنها مرجع انتقال وضعیت نوبت.
 *
 * هیچ Controller یا Model اجازه ندارد status را مستقیماً بنویسد؛ همه چیز از
 * TransitionAppointment عبور می‌کند و آن هم اینجا اعتبارسنجی می‌شود.
 */
final class AppointmentStateMachine
{
    /**
     * انتقال‌های رو‌به‌جلوی مجاز.
     *
     * @var array<string, array<int, string>>
     */
    private const FORWARD = [
        'BOOKED' => ['WAITING', 'CHECKED_IN', 'CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'],
        'WAITING' => ['CALLED', 'CHECKED_IN', 'CANCELLED', 'NO_SHOW', 'REJECTED', 'EXPIRED'],
        'CALLED' => ['CHECKED_IN', 'CANCELLED', 'NO_SHOW'],
        'CHECKED_IN' => ['LOADING', 'CANCELLED'],
        'LOADING' => ['LOADED', 'CANCELLED'],
        'LOADED' => ['COMPLETED'],
        'COMPLETED' => [],
        'CANCELLED' => [],
        'NO_SHOW' => [],
        'REJECTED' => [],
        'EXPIRED' => [],
    ];

    /**
     * بازگشت به عقب — فقط با دسترسی ویژه و همیشه با ثبت دلیل.
     * اپراتور معمولی نمی‌تواند LOADING را به BOOKED برگرداند.
     *
     * @var array<string, array<int, string>>
     */
    private const ROLLBACK = [
        'CALLED' => ['WAITING'],
        'CHECKED_IN' => ['WAITING', 'CALLED'],
        'LOADING' => ['CHECKED_IN'],
        'LOADED' => ['LOADING'],
        'COMPLETED' => ['LOADED'],
        'NO_SHOW' => ['BOOKED', 'WAITING'],
        'CANCELLED' => ['BOOKED', 'WAITING'],
    ];

    /** دسترسی لازم برای بازگرداندن وضعیت */
    public const ROLLBACK_PERMISSION = 'appointments.rollback';

    public function canTransition(S $from, S $to, bool $withRollbackPermission = false): bool
    {
        if ($from === $to) {
            return false;
        }

        if (in_array($to->value, self::FORWARD[$from->value] ?? [], true)) {
            return true;
        }

        return $withRollbackPermission && $this->isRollback($from, $to);
    }

    public function isRollback(S $from, S $to): bool
    {
        return in_array($to->value, self::ROLLBACK[$from->value] ?? [], true);
    }

    /**
     * وضعیت‌هایی که از وضعیت فعلی قابل رسیدن هستند.
     *
     * @return array<int, S>
     */
    public function allowedFrom(S $from, bool $withRollbackPermission = false): array
    {
        $values = self::FORWARD[$from->value] ?? [];

        if ($withRollbackPermission) {
            $values = array_merge($values, self::ROLLBACK[$from->value] ?? []);
        }

        return array_values(array_map(
            fn (string $value) => S::from($value),
            array_unique($values),
        ));
    }

    /** دسترسی لازم برای این انتقال مشخص */
    public function permissionFor(S $from, S $to): string
    {
        if ($this->isRollback($from, $to) && ! in_array($to->value, self::FORWARD[$from->value] ?? [], true)) {
            return self::ROLLBACK_PERMISSION;
        }

        return match ($to) {
            // ورود به صف انتظار بخشی از اداره‌ی روزمره‌ی صف است
            S::Waiting => 'queue.manage',
            S::Called => 'queue.call',
            S::CheckedIn => 'queue.checkin',
            S::Loading => 'queue.start-loading',
            S::Loaded, S::Completed => 'queue.complete-loading',
            S::NoShow => 'queue.no-show',
            S::Cancelled => 'appointments.cancel',
            S::Rejected => 'appointments.reject',
            default => 'queue.manage',
        };
    }
}
