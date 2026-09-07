<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Audit\SecurityLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ChangePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function __construct(private readonly SecurityLogger $security) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('Staff/Password', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(ChangePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => Hash::make($request->string('password')->toString()),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // session تازه: اگر رمز به‌خاطر لو رفتن عوض شده، نشستِ قبلی باید بمیرد
        $request->session()->regenerate();

        $this->security->log(
            SecurityLogger::ADMIN_ACTION,
            (string) $user->id,
            $user,
            ['action' => 'password_changed'],
            $request,
        );

        return redirect()->route('staff.home')->with('success', 'رمز عبور شما عوض شد.');
    }
}
