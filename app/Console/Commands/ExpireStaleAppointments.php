<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * نوبت‌های روزهای گذشته که هیچ‌وقت به کارخانه نرسیدند را می‌بندد.
 *
 * بدون این، ظرفیت آن روزها برای همیشه اشغال می‌ماند و شمارش «نوبت فعال» هر
 * راننده هم اشتباه می‌شود.
 */
class ExpireStaleAppointments extends Command
{
    protected $signature = 'appointments:expire {--dry-run : فقط گزارش بده}';

    protected $description = 'بستن نوبت‌های سررسیدگذشته‌ای که به کارخانه نرسیدند';

    public function handle(TransitionAppointment $transition): int
    {
        $cutoff = CarbonImmutable::today();

        $stale = Appointment::query()
            ->whereDate('date', '<', $cutoff->toDateString())
            ->whereIn('status', [
                AppointmentStatus::Booked->value,
                AppointmentStatus::Waiting->value,
                AppointmentStatus::Called->value,
            ])
            ->get();

        if ($stale->isEmpty()) {
            $this->info('نوبت سررسیدگذشته‌ای نبود.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$stale->count()} نوبت منقضی می‌شد.");

            return self::SUCCESS;
        }

        $expired = 0;

        foreach ($stale as $appointment) {
            try {
                $transition(
                    $appointment,
                    AppointmentStatus::Expired,
                    Actor::system(),
                    'سررسید نوبت گذشت و کامیون مراجعه نکرد',
                );
                $expired++;
            } catch (Throwable $e) {
                $this->warn("نوبت #{$appointment->number}: {$e->getMessage()}");
            }
        }

        $this->info("{$expired} نوبت منقضی شد.");

        return self::SUCCESS;
    }
}
