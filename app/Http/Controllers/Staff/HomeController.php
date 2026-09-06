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
     * نگهبان نباید با صف کامل روبه‌رو شود و مدیرعامل نباید پنل اپراتور را ببیند.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        return match (true) {
            $user->hasRole(Roles::GATE) => redirect()->route('staff.gate.index'),
            $user->hasRole(Roles::CEO) => redirect()->route('staff.dashboard'),
            $user->can(Permissions::QUEUE_VIEW) => redirect()->route('staff.queue.index'),
            $user->can(Permissions::DASHBOARD_VIEW) => redirect()->route('staff.dashboard'),
            default => redirect()->route('staff.login')->with('error', 'برای این حساب هیچ دسترسی‌ای تعریف نشده است.'),
        };
    }
}
