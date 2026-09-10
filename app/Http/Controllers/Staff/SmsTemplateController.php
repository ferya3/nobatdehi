<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Domain\Sms\SmsTemplates;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateSmsTemplateRequest;
use App\Models\SmsTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * متن پیامک‌ها، دستِ کارخانه.
 *
 * «به بارگیری مراجعه کنید» در کارخانه‌ای درست است که راننده مستقیم سرِ لاین
 * می‌رود، و در کارخانه‌ای که اول باید از نگهبانی رد شود غلط. تا وقتی این
 * متن‌ها در کد بودند، هر تفاوتِ کوچکِ رویه یک تیکت می‌شد.
 *
 * متغیرها ولی دستِ برنامه می‌مانند: هر قالب فقط چیزهایی را می‌شناسد که
 * سامانه موقع فرستادنش دارد. اگر اپراتور {plate} را جایی بنویسد که پلاکی
 * نیست، همان {plate} خام برای راننده می‌رود — پس صفحه باید همان لحظه
 * جلویش را بگیرد.
 */
class SmsTemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorizeSettings($request);

        $known = SmsTemplates::all();

        $templates = SmsTemplate::orderBy('key')->get()->map(fn (SmsTemplate $t) => [
            'id' => $t->id,
            'key' => $t->key,
            'title' => $t->title,
            'body' => $t->body,
            'is_active' => (bool) $t->is_active,
            'variables' => collect($known[$t->key]['variables'] ?? [])
                ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
                ->values(),
            'default_body' => $known[$t->key]['body'] ?? null,
        ]);

        return Inertia::render('Staff/SmsTemplates', [
            'templates' => $templates,
        ]);
    }

    public function update(UpdateSmsTemplateRequest $request, SmsTemplate $template): RedirectResponse
    {
        $before = $template->only(['body', 'is_active']);

        $template->update([
            'body' => $request->string('body')->toString(),
            'is_active' => $request->boolean('is_active'),
        ]);

        $this->audit->log(
            action: 'UPDATE_SMS_TEMPLATE',
            entity: $template,
            oldValues: $before,
            newValues: $template->only(['body', 'is_active']),
            request: $request,
        );

        return back()->with('success', 'متن پیامک «'.$template->title.'» ذخیره شد.');
    }

    /** برگرداندن یک قالب به متنِ پیش‌فرضِ سامانه */
    public function reset(Request $request, SmsTemplate $template): RedirectResponse
    {
        $this->authorizeSettings($request);

        $default = SmsTemplates::all()[$template->key]['body'] ?? null;

        if ($default === null) {
            return back()->with('error', 'برای این قالب متن پیش‌فرضی تعریف نشده است.');
        }

        $before = $template->only(['body']);

        $template->update(['body' => $default]);

        $this->audit->log(
            action: 'RESET_SMS_TEMPLATE',
            entity: $template,
            oldValues: $before,
            newValues: ['body' => $default],
            request: $request,
        );

        return back()->with('success', 'متن به حالت پیش‌فرض برگشت.');
    }

    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::SETTINGS_MANAGE), 403, 'برای این بخش دسترسی ندارید.');
    }
}
