<?php

use App\Domain\Access\Permissions;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| کانال‌های Broadcast
|--------------------------------------------------------------------------
|
| همه‌ی کانال‌ها خصوصی‌اند. مجوز عضویت دقیقاً همان دسترسی‌ای است که برای
| دیدن همان داده در HTTP لازم است — وگرنه WebSocket به یک در پشتی برای
| دور زدن RBAC تبدیل می‌شود.
|
*/

// صف یک کارخانه — برای پنل اپراتور
Broadcast::channel('factory.{factoryId}.queue', function (User $user, int $factoryId) {
    return $user->can(Permissions::QUEUE_VIEW) && $user->belongsToFactory($factoryId);
});

// شمارنده‌های داشبورد
Broadcast::channel('factory.{factoryId}.dashboard', function (User $user, int $factoryId) {
    return $user->can(Permissions::DASHBOARD_VIEW) && $user->belongsToFactory($factoryId);
});

// رویدادهای نگهبانی
Broadcast::channel('factory.{factoryId}.gate', function (User $user, int $factoryId) {
    return $user->can(Permissions::QUEUE_CHECKIN) && $user->belongsToFactory($factoryId);
});

// وضعیت نوبت یک راننده — فقط خود راننده
Broadcast::channel('driver.{driverId}', function (Driver $driver, int $driverId) {
    return $driver->id === $driverId;
}, ['guards' => ['driver']]);

// یک نوبت مشخص — راننده‌ی همان نوبت
Broadcast::channel('appointment.{ulid}', function (Driver $driver, string $ulid) {
    return Appointment::where('ulid', $ulid)->where('driver_id', $driver->id)->exists();
}, ['guards' => ['driver']]);
