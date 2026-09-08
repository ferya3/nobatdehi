<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Domain\Auth\Exceptions\OtpException;
use App\Domain\Auth\OtpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\RequestOtpRequest;
use App\Http\Requests\Driver\VerifyOtpRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::guard('driver')->check()) {
            return redirect()->route('driver.home');
        }

        return Inertia::render('Driver/Auth/Login');
    }

    public function request(RequestOtpRequest $request): RedirectResponse
    {
        try {
            $result = $this->otp->request($request->mobile(), $request);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['mobile' => $e->getMessage()]);
        }

        return redirect()
            ->route('driver.otp.verify.show')
            ->with('otp', [
                'mobile' => $result['mobile'],
                'resend_in' => $result['resend_in'],
                'expires_in' => $result['expires_in'],
                'dev_code' => $result['code'],

                // طول کد از سرور می‌آید و نه از یک عدد ثابت در Vue.
                // با عدد ثابت، تغییر OTP_LENGTH فرم را بی‌صدا خراب می‌کرد:
                // راننده کد شش‌رقمی می‌گرفت و فرم فقط پنج خانه داشت.
                'length' => (int) config('otp.length'),
            ]);
    }

    public function showVerify(Request $request): Response|RedirectResponse
    {
        $otp = $request->session()->get('otp');

        if (! is_array($otp) || ! isset($otp['mobile'])) {
            return redirect()->route('driver.login');
        }

        // تازه‌سازی شمارنده در رفرش صفحه
        $otp['resend_in'] = $this->otp->secondsUntilResend($otp['mobile']);

        // نشست‌های قدیمی این کلید را ندارند؛ بدون این، رفرشِ صفحه
        // فرم را بدون خانه رها می‌کرد
        $otp['length'] = (int) ($otp['length'] ?? config('otp.length'));
        $request->session()->keep('otp');
        $request->session()->flash('otp', $otp);

        return Inertia::render('Driver/Auth/Verify', ['otp' => $otp]);
    }

    public function verify(VerifyOtpRequest $request): RedirectResponse
    {
        try {
            $driver = $this->otp->verify($request->mobile(), $request->string('code')->toString(), $request);
        } catch (OtpException $e) {
            /*
             * صفحه‌ی تأیید باید سرِ جایش بماند.
             *
             * otp یک flash است و بعد از این درخواست پاک می‌شود. بدون
             * نگه‌داشتنش، یک رقمِ اشتباه راننده را به صفحه‌ی ورود پرت
             * می‌کرد و باید از اول کد می‌گرفت — با اینکه مهلت تلاش مجدد
             * وجود دارد و هنوز تمام نشده بود.
             */
            $this->keepVerifyPage($request);

            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        Auth::guard('driver')->login($driver, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('driver.home'));
    }

    /**
     * داده‌ی صفحه‌ی تأیید را برای درخواست بعدی نگه می‌دارد.
     *
     * شمارنده‌ها دوباره حساب می‌شوند و نه از مقدار کهنه: راننده‌ای که
     * سی ثانیه با کد کلنجار رفته، نباید «۶۰ ثانیه تا ارسال مجدد» ببیند.
     */
    private function keepVerifyPage(Request $request): void
    {
        $otp = $request->session()->get('otp');

        if (! is_array($otp) || ! isset($otp['mobile'])) {
            return;
        }

        $otp['resend_in'] = $this->otp->secondsUntilResend($otp['mobile']);
        $otp['length'] = (int) ($otp['length'] ?? config('otp.length'));

        $request->session()->flash('otp', $otp);
    }

    public function resend(RequestOtpRequest $request): RedirectResponse
    {
        return $this->request($request);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('driver')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('driver.login');
    }
}
