<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Audit Log: «چه داده‌ای، توسط چه کسی، از چه مقداری به چه مقداری» عوض شد.
 * لاگ امنیتی (SecurityLogger) جداست و «چه کسی چه تلاشی کرد» را ثبت می‌کند.
 */
final class AuditLogger
{
    public function log(
        string $action,
        Model $entity,
        array $oldValues = [],
        array $newValues = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::create([
            'action' => $action,
            'entity_type' => $entity::class,
            'entity_id' => $entity->getKey(),
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'user_id' => $request?->user('web')?->id,
            'driver_id' => $request?->user('driver')?->id,
            'actor_label' => $request?->user('web') || $request?->user('driver') ? null : 'سامانه',
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'created_at' => now(),
        ]);
    }
}
