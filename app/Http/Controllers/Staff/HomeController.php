<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Access\Roles;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * هر نقش به صفحه‌ی خودش می‌رود.
     *
     * کسی که تمام روز پشت یک ایستگاه است باید همان‌جا باز شود؛ نگهبان نباید
     * با صف کامل روبه‌رو شود. برای بقیه داشبورد صفحه‌ی اول است — صف امروز
     * هم همان‌جاست، پس چیزی از دست نمی‌رود.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->hasRole(Roles::GATE) => redirect()->route('staff.gate.index'),
            $user->hasRole(Roles::WEIGHBRIDGE) => redirect()->route('staff.weighbridge.index'),
            $user->hasRole(Roles::WAREHOUSE) => redirect()->route('staff.loading.index'),
            $user->can(Permissions::DASHBOARD_VIEW) => redirect()->route('staff.dashboard'),
            $user->can(Permissions::QUEUE_VIEW) => redirect()->route('staff.queue.index'),
            default => redirect()->route('staff.login')->with('error', 'برای این حساب هیچ دسترسی‌ای تعریف نشده است.'),
        };
    }
}
