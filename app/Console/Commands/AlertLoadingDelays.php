<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Sms\SmsService;
use App\Models\Appointment;
use App\Models\Setting;
use App\Support\Digits;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * کامیون‌هایی که بارگیری‌شان از مدت مورد انتظار گذشته.
 *
 * هشدار برای هر کامیون فقط یک بار فرستاده می‌شود؛ زنگی که هر پنج دقیقه
 * تکرار شود، بعد از یک روز خاموش می‌شود — یا خودِ گیرنده خاموشش می‌کند.
 */
class AlertLoadingDelays extends Command
{
    protected $signature = 'loading:alert-delays {--dry-run : فقط گزارش بده}';

    protected $description = 'هشدار تأخیر بارگیری به مدیران';

    /** هشدارِ یک نوبت تا پایان روز دوباره فرستاده نمی‌شود */
    private const SENT_TTL_HOURS = 12;

    public function handle(SmsService $sms): int
    {
        $late = Appointment::with(['truck.truckType', 'product', 'loadingPoint', 'factory'])
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->where('status', AppointmentStatus::Loading->value)
            ->get()
            ->filter(fn (Appointment $a) => $a->isLoadingLate());

        if ($late->isEmpty()) {
            $this->info('بارگیری با تأخیری نبود.');

            return self::SUCCESS;
        }

        $recipients = $this->managerRecipients();
        $sent = 0;

        foreach ($late as $appointment) {
            $key = "loading-delay-alert:{$appointment->ulid}";

            if (Cache::has($key)) {
                continue;
            }

            $elapsed = (int) $appointment->loading_started_at->diffInMinutes(now());

            $line = sprintf(
                'تأخیر بارگیری — نوبت %s، پلاک %s، %s دقیقه روی لاین (انتظار: %s دقیقه).',
                $appointment->label(),
                $appointment->truck?->plate()?->short() ?? '—',
                Digits::toPersian((string) $elapsed),
                Digits::toPersian((string) $appointment->expectedLoadingMinutes()),
            );

            $this->line($line);

            if ($this->option('dry-run')) {
                continue;
            }

            foreach ($recipients as $mobile) {
                $sms->queue($mobile, $line, related: $appointment);
            }

            Cache::put($key, true, now()->addHours(self::SENT_TTL_HOURS));
            $sent++;
        }

        $this->info($this->option('dry-run')
            ? $late->count().' بارگیری با تأخیر پیدا شد.'
            : "{$sent} هشدار تازه ثبت شد.");

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
