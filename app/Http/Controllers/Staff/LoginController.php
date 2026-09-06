<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Audit\SecurityLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function show(): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('staff.home');
        }

        return Inertia::render('Staff/Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'ایمیل را وارد کنید.',
            'email.email' => 'ایمیل معتبر نیست.',
            'password.required' => 'رمز عبور را وارد کنید.',
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            $this->security->log(
                SecurityLogger::LOGIN_FAILED,
                $credentials['email'],
                request: $request,
            );

            // پیام عمداً مبهم است: نگوییم کدام‌یک از ایمیل یا رمز غلط بود.
            throw ValidationException::withMessages([
                'email' => 'ایمیل یا رمز عبور درست نیست.',
            ]);
        }

        $user = Auth::guard('web')->user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();

            $this->security->log(SecurityLogger::PERMISSION_DENIED, $user->email, $user, ['reason' => 'inactive'], $request);

            throw ValidationException::withMessages([
                'email' => 'حساب کاربری شما غیرفعال شده است.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        $this->security->log(SecurityLogger::LOGIN_SUCCESS, $user->email, $user, request: $request);

        return redirect()->intended(route('staff.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = Auth::guard('web')->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->security->log(SecurityLogger::LOGOUT, $user?->email, $user, request: $request);

        return redirect()->route('staff.login');
    }
}
