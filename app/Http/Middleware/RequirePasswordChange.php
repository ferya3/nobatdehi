<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * تا وقتی رمزِ اولیه عوض نشده، هیچ صفحه‌ای از پنل باز نمی‌شود.
 *
 * صفحه‌ی تغییر رمز و خروج استثنا هستند، وگرنه کاربر در یک حلقه‌ی
 * تغییرمسیر گیر می‌کند.
 */
class RequirePasswordChange
{
    /** @var array<int, string> */
    private const ALLOWED = [
        'staff.password.edit',
        'staff.password.update',
        'staff.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        return redirect()
            ->route('staff.password.edit')
            ->with('error', 'برای ادامه باید رمز عبور پیش‌فرض را عوض کنید.');
    }
}
