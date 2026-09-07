<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Gate\GateDevices;
use App\Domain\Gate\PlateCapture;
use App\Models\PlateReading;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * پاک‌کردن خواندن‌های قدیمی دوربین.
 *
 * دوربین پلاک‌خوان از هر خودرویی که رد شود عکس می‌گیرد — نه فقط کامیون‌های
 * نوبت‌دار. بدون این فرمان، دیسک پر می‌شود و بایگانیِ عکسِ هر رهگذر برای
 * همیشه می‌ماند؛ اولی مشکل عملیاتی است و دومی مشکل حقوقی.
 *
 * ردیفی که به یک نوبت گره خورده استثناست: آن عکس بخشی از سابقه‌ی ورودِ همان
 * کامیون است و با خودِ نوبت می‌ماند.
 */
class PrunePlateReadings extends Command
{
    protected $signature = 'plate-readings:prune {--days= : بازنویسی مدت نگهداری}';

    protected $description = 'حذف خواندن‌ها و عکس‌های پلاکِ قدیمی‌تر از مدت نگهداری';

    public function handle(GateDevices $devices): int
    {
        $days = (int) ($this->option('days') ?: $devices->retentionDays());

        if ($days < 1) {
            $this->error('مدت نگهداری باید حداقل یک روز باشد.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        $files = 0;

        PlateReading::whereNull('appointment_id')
            ->where('captured_at', '<', $cutoff)
            ->chunkById(200, function ($readings) use (&$deleted, &$files) {
                foreach ($readings as $reading) {
                    if ($reading->image_path !== null) {
                        $disk = Storage::disk($reading->image_disk ?? PlateCapture::DISK);

                        if ($disk->delete($reading->image_path)) {
                            $files++;
                        }
                    }

                    $reading->delete();
                    $deleted++;
                }
            });

        $this->info("حذف شد: {$deleted} خواندن، {$files} عکس (قدیمی‌تر از {$days} روز).");

        return self::SUCCESS;
    }
}
