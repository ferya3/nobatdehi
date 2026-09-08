<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Access\Permissions as P;

final class Roles
{
    public const SUPER_ADMIN = 'super-admin';
    public const FACTORY_MANAGER = 'factory-manager';
    public const OPERATOR = 'operator';
    public const GATE = 'gate';
    public const WEIGHBRIDGE = 'weighbridge';
    public const WAREHOUSE = 'warehouse';
    public const CEO = 'ceo';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::SUPER_ADMIN => 'مدیر ارشد سامانه',
            self::FACTORY_MANAGER => 'مدیر کارخانه',
            self::OPERATOR => 'اپراتور',
            self::GATE => 'نگهبانی',
            self::WEIGHBRIDGE => 'باسکول',
            self::WAREHOUSE => 'انبار',
            self::CEO => 'مدیرعامل',
        ];
    }

    /**
     * نگاشت نقش به دسترسی.
     *
     * مدیرعامل عمداً هیچ دسترسی نوشتنی ندارد: نه کاربر، نه تنظیمات، نه صف.
     * سیستم امن با عنوان شغلی مذاکره نمی‌کند.
     *
     * @return array<string, array<int, string>>
     */
    public static function matrix(): array
    {
        $operator = [
            P::QUEUE_VIEW, P::QUEUE_MANAGE, P::QUEUE_CALL, P::QUEUE_CHECKIN,
            P::QUEUE_START_LOADING, P::QUEUE_COMPLETE_LOADING, P::QUEUE_NO_SHOW,
            P::APPOINTMENTS_VIEW, P::APPOINTMENTS_CANCEL, P::APPOINTMENTS_CREATE,
            P::DASHBOARD_VIEW,
        ];

        return [
            self::SUPER_ADMIN => Permissions::keys(),

            self::FACTORY_MANAGER => array_merge($operator, [
                P::APPOINTMENTS_REJECT, P::APPOINTMENTS_ROLLBACK,
                P::REPORTS_VIEW, P::SETTINGS_MANAGE, P::PRODUCTS_MANAGE,
                P::USERS_MANAGE, P::AUDIT_VIEW, P::WEIGHING_RECORD,
                // استثنای گیت دستِ مدیر است، نه دستِ خودِ نگهبان
                P::GATE_MANUAL_OVERRIDE,
            ]),

            self::OPERATOR => $operator,

            self::GATE => [
                P::QUEUE_VIEW, P::QUEUE_CHECKIN, P::APPOINTMENTS_VIEW,
            ],

            self::WEIGHBRIDGE => [
                P::QUEUE_VIEW, P::APPOINTMENTS_VIEW, P::WEIGHING_RECORD,
            ],

            self::WAREHOUSE => [
                P::QUEUE_VIEW, P::APPOINTMENTS_VIEW,
                P::QUEUE_START_LOADING, P::QUEUE_COMPLETE_LOADING,
            ],

            // مدیرعامل عمداً QUEUE_VIEW ندارد: پنل اپراتور صفحه‌ی او نیست.
            // دید او از طریق داشبورد و گزارش است، نه صف عملیاتی.
            self::CEO => [
                P::DASHBOARD_VIEW, P::REPORTS_VIEW, P::APPOINTMENTS_VIEW,
            ],
        ];
    }
}
