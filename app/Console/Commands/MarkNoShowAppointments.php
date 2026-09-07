<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\Digits;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * نوبت‌های امروز که مهلت حضورشان تمام شده را «عدم حضور» ثبت می‌کند.
 *
 * این همان چیزی است که ظرفیت را در همان روز آزاد می‌کند؛ appointments:expire
 * فقط شب‌ها و برای روزهای گذشته اجرا می‌شود و برای اداره‌ی صفِ جاری دیر است.
 *
 * مهلت هر نوبت از نوع کامیونش می‌آید و اگر تعریف نشده باشد، از کارخانه.
 */
class MarkNoShowAppointments extends Command
{
    protected $signature = 'appointments:no-show {--dry-run : فقط گزارش بده}';

    protected $description = 'ثبت خودکار عدم حضور برای نوبت‌هایی که مهلتشان گذشته';

    public function handle(TransitionAppointment $transition): int
    {
        $now = CarbonImmutable::now();

        $candidates = Appointment::query()
            ->with(['factory', 'truck.truckType'])
            ->whereDate('date', $now->toDateString())
            ->whereIn('status', [
                AppointmentStatus::Booked->value,
                AppointmentStatus::Waiting->value,
                AppointmentStatus::Called->value,
            ])
            ->orderBy('start_time')
            ->get()
            // مهلت هر نوبت به نوع کامیونش وابسته است، پس فیلتر در PHP انجام
            // می‌شود؛ تعداد نوبت‌های باز یک روز در ابعاد چند صد است، نه میلیون.
            ->filter(fn (Appointment $a) => $now->greaterThan($a->noShowDueAt()));

        if ($candidates->isEmpty()) {
            $this->info('نوبتی از مهلت حضورش نگذشته بود.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($candidates as $appointment) {
                $this->line(sprintf(
                    'نوبت %s — ساعت %s — مهلت %s دقیقه',
                    $appointment->label(),
                    $appointment->timeRange(),
                    Digits::toPersian((string) $appointment->graceMinutes()),
                ));
            }

            $this->info($candidates->count().' نوبت عدم حضور می‌خورد.');

            return self::SUCCESS;
        }

        $marked = 0;

        foreach ($candidates as $appointment) {
            try {
                $transition(
                    $appointment,
                    AppointmentStatus::NoShow,
                    Actor::system(),
                    'مهلت '.Digits::toPersian((string) $appointment->graceMinutes()).' دقیقه‌ای حضور گذشت',
                );
                $marked++;
            } catch (Throwable $e) {
                $this->warn("نوبت {$appointment->label()}: {$e->getMessage()}");
            }
        }

        $this->info("{$marked} نوبت عدم حضور ثبت شد.");

        return self::SUCCESS;
    }
}
