<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\SecurityLogger;
use App\Domain\Sms\SmsService;
use App\Models\SecurityLog;
use App\Models\Setting;
use App\Support\Digits;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * لاگ امنیتی تا وقتی کسی نگاهش نکند، فقط یک جدول است.
 *
 * این فرمان الگوهایی را می‌بیند که یک نفر نمی‌سازد: ده‌ها تلاش ناموفق ورود
 * از یک IP، توکن دستگاهِ غلط که پشت سر هم می‌آید، یا مغایرت پلاک که چند بار
 * در یک ساعت تکرار شده. هرکدام یک پیامک به مدیران می‌فرستد.
 */
class AlertSecurityEvents extends Command
{
    protected $signature = 'security:alert {--dry-run : فقط گزارش بده}';

    protected $description = 'هشدار پیامکی برای الگوهای مشکوک در لاگ امنیتی';

    /** پنجره‌ی بررسی */
    private const WINDOW_MINUTES = 15;

    /** یک هشدار برای هر الگو در این مدت تکرار نمی‌شود */
    private const COOLDOWN_MINUTES = 60;

    /**
     * چند بار در پنجره، «مشکوک» است.
     *
     * آستانه‌ها عمداً فرق دارند: رمز اشتباه تایپ‌کردن عادی است، ولی توکن
     * دستگاهِ غلط یعنی یا پیکربندی خراب است یا کسی دارد امتحان می‌کند.
     *
     * @var array<string, array{threshold:int, label:string}>
     */
    private const WATCHED = [
        SecurityLogger::LOGIN_FAILED => ['threshold' => 15, 'label' => 'ورود ناموفق به پنل'],
        SecurityLogger::OTP_FAILED => ['threshold' => 25, 'label' => 'کد یک‌بارمصرف اشتباه'],
        SecurityLogger::PERMISSION_DENIED => ['threshold' => 10, 'label' => 'دسترسی رد شده'],
        SecurityLogger::GATE_PLATE_MISMATCH => ['threshold' => 3, 'label' => 'مغایرت پلاک در گیت'],
        SecurityLogger::QR_REPLAY => ['threshold' => 5, 'label' => 'استفاده‌ی دوباره از کد باطل'],
        SecurityLogger::GATE_NO_QR => ['threshold' => 5, 'label' => 'تلاش ورود بدون اسکن'],
    ];

    public function handle(SmsService $sms): int
    {
        $since = now()->subMinutes(self::WINDOW_MINUTES);
        $recipients = $this->managerRecipients();
        $sent = 0;

        foreach (self::WATCHED as $event => $rule) {
            $count = SecurityLog::where('event', $event)->where('created_at', '>=', $since)->count();

            if ($count < $rule['threshold']) {
                continue;
            }

            $line = sprintf(
                'هشدار امنیتی — %s: %s مورد در %s دقیقه‌ی گذشته.',
                $rule['label'],
                Digits::toPersian((string) $count),
                Digits::toPersian((string) self::WINDOW_MINUTES),
            );

            $this->line($line);

            if ($this->option('dry-run')) {
                continue;
            }

            $key = "security-alert:{$event}";

            if (Cache::has($key)) {
                continue;
            }

            foreach ($recipients as $mobile) {
                $sms->queue($mobile, $line);
            }

            Cache::put($key, true, now()->addMinutes(self::COOLDOWN_MINUTES));
            $sent++;
        }

        $this->info($sent === 0 ? 'هشدار تازه‌ای نبود.' : "{$sent} هشدار ثبت شد.");

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function managerRecipients(): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', Setting::get('sms_manager_recipients')),
        )));
    }
}
