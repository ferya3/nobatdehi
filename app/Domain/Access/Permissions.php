<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * فهرست کامل دسترسی‌ها. هر Endpoint دقیقاً به یکی از این‌ها گره می‌خورد؛
 * «کاربر لاگین است» به‌تنهایی مجوز محسوب نمی‌شود.
 */
final class Permissions
{
    public const QUEUE_VIEW = 'queue.view';
    public const QUEUE_CALL = 'queue.call';
    public const QUEUE_CHECKIN = 'queue.checkin';
    public const QUEUE_START_LOADING = 'queue.start-loading';
    public const QUEUE_COMPLETE_LOADING = 'queue.complete-loading';
    public const QUEUE_NO_SHOW = 'queue.no-show';
    public const QUEUE_MANAGE = 'queue.manage';

    /**
     * ثبت ورود بدون اسکن QR.
     *
     * عمداً از queue.checkin جداست: نگهبان می‌تواند ورود ثبت کند ولی فقط با
     * اسکن. دور زدن این قانون کارِ مسئولِ بالادست است و در لاگ امنیتی می‌نشیند.
     */
    public const GATE_MANUAL_OVERRIDE = 'gate.manual-override';

    public const APPOINTMENTS_VIEW = 'appointments.view';
    public const APPOINTMENTS_CREATE = 'appointments.create';
    public const APPOINTMENTS_CANCEL = 'appointments.cancel';
    public const APPOINTMENTS_REJECT = 'appointments.reject';
    public const APPOINTMENTS_ROLLBACK = 'appointments.rollback';

    public const WEIGHING_RECORD = 'weighing.record';

    /**
     * تعیین تکلیفِ توزینِ مغایر.
     *
     * عمداً از weighing.record جداست: باسکول‌بان وزن را می‌خواند، ولی
     * «این اختلاف قابل قبول است» تصمیمِ کسی است که پاسخگوی تناژِ فروخته‌شده
     * باشد. اگر هر دو یکی بودند، قفلِ مغایرت هیچ معنایی نداشت.
     */
    public const WEIGHING_RESOLVE = 'weighing.resolve';

    public const DASHBOARD_VIEW = 'dashboard.view';
    public const REPORTS_VIEW = 'reports.view';

    public const SETTINGS_MANAGE = 'settings.manage';
    public const PRODUCTS_MANAGE = 'products.manage';
    public const USERS_MANAGE = 'users.manage';
    public const ROLES_MANAGE = 'roles.manage';

    public const AUDIT_VIEW = 'audit.view';
    public const SECURITY_LOGS_VIEW = 'security-logs.view';

    /** @return array<string, string> کلید => برچسب فارسی */
    public static function all(): array
    {
        return [
            self::QUEUE_VIEW => 'مشاهده صف',
            self::QUEUE_CALL => 'فراخوانی کامیون',
            self::QUEUE_CHECKIN => 'ثبت ورود به محوطه',
            self::QUEUE_START_LOADING => 'شروع بارگیری',
            self::QUEUE_COMPLETE_LOADING => 'پایان بارگیری',
            self::QUEUE_NO_SHOW => 'ثبت عدم حضور',
            self::QUEUE_MANAGE => 'اداره صف (تغییر ترتیب و وضعیت پایه)',
            self::GATE_MANUAL_OVERRIDE => 'ثبت ورود بدون اسکن QR (استثنا)',

            self::APPOINTMENTS_VIEW => 'مشاهده نوبت‌ها',
            self::APPOINTMENTS_CREATE => 'ثبت نوبت به‌جای راننده',
            self::APPOINTMENTS_CANCEL => 'لغو نوبت',
            self::APPOINTMENTS_REJECT => 'رد نوبت',
            self::APPOINTMENTS_ROLLBACK => 'بازگرداندن وضعیت نوبت',

            self::WEIGHING_RECORD => 'ثبت وزن باسکول',
            self::WEIGHING_RESOLVE => 'تعیین تکلیف مغایرت وزن',

            self::DASHBOARD_VIEW => 'مشاهده داشبورد',
            self::REPORTS_VIEW => 'مشاهده گزارش‌ها',

            self::SETTINGS_MANAGE => 'تنظیمات نوبت‌دهی و ظرفیت',
            self::PRODUCTS_MANAGE => 'مدیریت محصولات و لاین‌ها',
            self::USERS_MANAGE => 'مدیریت کاربران',
            self::ROLES_MANAGE => 'مدیریت نقش‌ها و دسترسی‌ها',

            self::AUDIT_VIEW => 'مشاهده Audit Log',
            self::SECURITY_LOGS_VIEW => 'مشاهده لاگ امنیتی',
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
