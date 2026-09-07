<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Domain\Sms\SmsManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateSmsSettingsRequest;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Support\Mobile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تنظیمات پنل پیامک.
 *
 * عمداً در دیتابیس است و نه در .env: عوض‌کردن پنل یا رمزش نباید نیاز به
 * دسترسی SSH و ری‌استارت سرویس داشته باشد.
 */
class SmsSettingsController extends Controller
{
    public function __construct(
        private readonly SmsManager $sms,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): Response
    {
        $this->authorizeSettings($request);

        return Inertia::render('Staff/SmsSettings', [
            'settings' => Setting::forDisplay(),
            'providers' => collect(SmsManager::providers())
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'recent' => SmsMessage::latest('id')->limit(15)->get()
                ->map(fn (SmsMessage $m) => [
                    'id' => $m->id,
                    'to' => $m->to,
                    'template' => $m->template_key,
                    'status' => $m->status,
                    'provider' => $m->provider,
                    'error' => $m->error,
                    'attempts' => $m->attempts,
                    'at' => $m->created_at?->format('Y-m-d H:i'),
                ]),
        ]);
    }

    public function update(UpdateSmsSettingsRequest $request): RedirectResponse
    {
        $before = Setting::values();

        Setting::putMany($request->settings());

        $after = Setting::values();

        $this->audit->log(
            action: 'UPDATE_SMS_SETTINGS',
            entity: $request->user(),
            oldValues: $this->redact($before),
            newValues: $this->redact($after),
            request: $request,
        );

        return back()->with('success', 'تنظیمات پنل پیامک ذخیره شد.');
    }

    /** ارسال یک پیامک آزمایشی با تنظیمات ذخیره‌شده */
    public function test(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request);

        $mobile = Mobile::normalize((string) $request->input('mobile'));

        if ($mobile === null) {
            throw ValidationException::withMessages(['mobile' => 'شماره موبایل معتبر نیست.']);
        }

        $settings = Setting::values();

        if ($reason = $this->sms->configurationError($settings)) {
            return back()->with('error', $reason);
        }

        // آزمایش عمداً همین‌جا و همزمان ارسال می‌شود، نه روی صف:
        // اپراتور باید پاسخ واقعی پنل را ببیند، نه «به صف اضافه شد».
        [$status, $detail] = $this->sms->send($settings, $mobile, "پیامک آزمایشی سامانه نوبت‌دهی بارگیری.\nاگر این پیام را دریافت کردید، تنظیمات درست است.");

        SmsMessage::create([
            'to' => $mobile,
            'body' => 'پیامک آزمایشی',
            'template_key' => 'test',
            'provider' => (string) ($settings['sms_provider'] ?? ''),
            'status' => $status === SmsManager::STATUS_SENT ? 'SENT' : 'FAILED',
            'attempts' => 1,
            'error' => $status === SmsManager::STATUS_SENT ? null : $detail,
            'sent_at' => $status === SmsManager::STATUS_SENT ? now() : null,
        ]);

        return back()->with(
            $status === SmsManager::STATUS_SENT ? 'success' : 'error',
            $detail,
        );
    }

    /**
     * عیب‌یابی هوشمند پنل افه: چند دامنه را امتحان می‌کند و آدرس درست را
     * اعلام می‌کند. در اولین ارسال موفق می‌ایستد تا پیامک تکراری نرود.
     */
    public function probe(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request);

        $mobile = Mobile::normalize((string) $request->input('mobile'));

        if ($mobile === null) {
            throw ValidationException::withMessages(['mobile' => 'شماره موبایل معتبر نیست.']);
        }

        $results = $this->sms->probeAfe(Setting::values(), $mobile);
        $working = collect($results)->firstWhere('ok', true);

        return back()->with('probe', [
            'results' => $results,
            'suggest' => $working ? preg_replace('#^www\.#i', '', (string) $working['host']) : null,
        ]);
    }

    /** اسرار هرگز داخل audit log نمی‌روند */
    private function redact(array $settings): array
    {
        foreach (Setting::SECRET_KEYS as $key) {
            if (($settings[$key] ?? '') !== '') {
                $settings[$key] = '***';
            }
        }

        return $settings;
    }

    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::SETTINGS_MANAGE), 403);
    }
}
