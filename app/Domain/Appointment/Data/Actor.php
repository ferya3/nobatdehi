<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Data;

use App\Domain\Access\Permissions;
use App\Models\Driver;
use App\Models\User;

/**
 * چه کسی این انتقال را انجام داد: کاربر داخلی، راننده، یا خود سامانه.
 */
final class Actor
{
    public const TYPE_DRIVER = 'driver';

    public const TYPE_STAFF = 'staff';

    public const TYPE_SYSTEM = 'system';

    private function __construct(
        public readonly ?User $user = null,
        public readonly ?Driver $driver = null,
        public readonly ?string $label = null,
        public readonly ?string $ip = null,
    ) {}

    public static function user(User $user, ?string $ip = null): self
    {
        return new self(user: $user, ip: $ip);
    }

    public static function driver(Driver $driver, ?string $ip = null): self
    {
        return new self(driver: $driver, label: 'راننده', ip: $ip);
    }

    public static function system(string $label = 'سامانه'): self
    {
        return new self(label: $label);
    }

    public function canRollback(): bool
    {
        return (bool) $this->user?->can(Permissions::APPOINTMENTS_ROLLBACK);
    }

    public function name(): string
    {
        return $this->user?->name ?? $this->driver?->displayName() ?? $this->label ?? 'سامانه';
    }

    /** نوع عامل — مبنای برچسب «لغو توسط ...» در پنل */
    public function type(): string
    {
        return match (true) {
            $this->driver !== null => self::TYPE_DRIVER,
            $this->user !== null => self::TYPE_STAFF,
            default => self::TYPE_SYSTEM,
        };
    }
}
