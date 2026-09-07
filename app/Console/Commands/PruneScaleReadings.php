<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Weighbridge\ScaleDevices;
use App\Models\ScaleReading;
use Illuminate\Console\Command;

/**
 * پاک‌کردن عددهای قدیمیِ باسکول.
 *
 * پل هر تغییرِ وزن را می‌فرستد؛ یک باسکولِ شلوغ در روز چند ده هزار ردیف
 * می‌سازد که اکثرشان لحظه‌ی بالا رفتن کامیون روی سکو هستند. بدون این
 * فرمان، جدول در چند ماه از خودِ نوبت‌ها بزرگ‌تر می‌شود.
 *
 * ردیفی که به حواله‌ای گره خورده استثناست: آن عدد، وزنِ ثبت‌شده‌ی همان
 * توزین است و با خودِ حواله می‌ماند.
 */
class PruneScaleReadings extends Command
{
    protected $signature = 'scale-readings:prune {--days= : بازنویسی مدت نگهداری}';

    protected $description = 'حذف عددهای باسکول که به حواله‌ای گره نخورده‌اند و از مدت نگهداری گذشته‌اند';

    public function handle(ScaleDevices $scales): int
    {
        $days = (int) ($this->option('days') ?: $scales->retentionDays());

        if ($days < 1) {
            $this->error('مدت نگهداری باید حداقل یک روز باشد.');

            return self::FAILURE;
        }

        $deleted = ScaleReading::whereNull('appointment_id')
            ->where('read_at', '<', now()->subDays($days))
            ->delete();

        $this->info("حذف شد: {$deleted} خواندن باسکول (قدیمی‌تر از {$days} روز).");

        return self::SUCCESS;
    }
}
