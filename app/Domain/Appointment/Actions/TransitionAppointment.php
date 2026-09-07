<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Actions;

use App\Domain\Appointment\AppointmentStateMachine;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Domain\Appointment\TransitionPreconditions;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;

/**
 * تنها راه تغییر وضعیت یک نوبت.
 *
 * ساعتِ اعلام‌شده هرگز جابه‌جا نمی‌شود؛ ولی لغو، بازه را روی لاین آزاد
 * می‌کند و نوبتِ بعدی می‌تواند همان‌جا بنشیند. پس بازگرداندنِ یک لغو، دیگر
 * بی‌قید و شرط نیست: اگر جای خالی را کسی گرفته باشد، بازگردانی با پیامی
 * روشن رد می‌شود و نه با خطای دیتابیس.
 */
final class TransitionAppointment
{
    public function __construct(
        private readonly AppointmentStateMachine $machine,
        private readonly TransitionPreconditions $preconditions,
    ) {}

    public function __invoke(
        Appointment $appointment,
        AppointmentStatus $to,
        Actor $actor,
        ?string $reason = null,
        ?int $loadingPointId = null,
    ): Appointment {
        return DB::transaction(function () use ($appointment, $to, $actor, $reason, $loadingPointId) {
            /** @var Appointment $fresh */
            $fresh = Appointment::whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            $from = $fresh->status;

            if ($from === $to) {
                return $fresh;
            }

            $isRollback = $this->machine->isRollback($from, $to);

            if (! $this->machine->canTransition($from, $to, $actor->canRollback())) {
                throw InvalidStateTransition::between($from, $to);
            }

            // بازگردانی عمداً از شرط‌ها معاف است: کارِ اصلاحِ اشتباه، خودش
            // نباید پشت همان شرطی گیر کند که اشتباه را ساخته.
            if (! $isRollback) {
                $this->preconditions->assert($fresh, $to);
            }

            // برگرداندنِ نوبتی که لغو شده، یعنی پس‌گرفتنِ بازه‌ای که آزاد شده
            $this->assertLineStillFree($fresh, $from, $to);

            $fresh->status = $to;
            $fresh->loading_point_id = $loadingPointId ?? $fresh->loading_point_id;
            $this->stamp($fresh, $to);

            $this->stampCloser($fresh, $to, $actor, $reason);

            $fresh->save();

            $fresh->transitions()->create([
                'from_status' => $from,
                'to_status' => $to,
                'is_rollback' => $isRollback,
                'reason' => $reason,
                'user_id' => $actor->user?->id,
                'driver_id' => $actor->driver?->id,
                'actor_label' => $actor->user || $actor->driver ? null : $actor->label,
                'ip' => $actor->ip,
                'created_at' => now(),
            ]);

            // بعد از commit: Listener های صف‌شده باید وضعیت نهایی را ببینند
            DB::afterCommit(fn () => AppointmentTransitioned::dispatch($fresh->id, $from->value, $to->value));

            return $fresh;
        });
    }

    /**
     * دلیل و عاملِ بسته‌شدن نوبت.
     *
     * روی هر بازگشت به وضعیت فعال پاک می‌شود؛ وگرنه نوبتی که اپراتور دوباره
     * باز کرده، تا ابد «لغو توسط راننده» را در پنل نشان می‌دهد.
     */
    private function stampCloser(
        Appointment $appointment,
        AppointmentStatus $to,
        Actor $actor,
        ?string $reason,
    ): void {
        if ($to->isCancellation()) {
            $appointment->cancel_reason = $reason;
            $appointment->cancelled_by_type = $actor->type();
            $appointment->cancelled_by_name = $actor->name();

            return;
        }

        if ($to->isActive()) {
            $appointment->cancel_reason = null;
            $appointment->cancelled_by_type = null;
            $appointment->cancelled_by_name = null;
        }

        // COMPLETED: نوبت موفق بسته شده — چیزی برای پاک کردن یا نوشتن نیست.
    }

    /** زمان هر وضعیت را ثبت می‌کند — پایه‌ی تمام گزارش‌های زمانی. */
    /**
     * بازگرداندنِ یک نوبتِ بسته، فقط وقتی که جایش هنوز خالی باشد.
     *
     * لغو، بازه را روی لاین آزاد می‌کند و ممکن است راننده‌ی دیگری همان
     * ساعت را گرفته باشد. بدون این بررسی، بازگردانی به ایندکس یکتای
     * دیتابیس می‌خورد و اپراتور به‌جای دلیل، یک خطای ۵۰۰ می‌دید.
     */
    private function assertLineStillFree(
        Appointment $appointment,
        AppointmentStatus $from,
        AppointmentStatus $to,
    ): void {
        if ($from->holdsLine() || ! $to->holdsLine()) {
            return;
        }

        $taken = Appointment::where('factory_id', $appointment->factory_id)
            ->whereKeyNot($appointment->id)
            ->whereDate('date', $appointment->date->toDateString())
            ->where('line_no', $appointment->line_no)
            ->where('start_time', $appointment->start_time)
            ->whereNotIn('status', AppointmentStatus::releasedValues())
            ->exists();

        if ($taken) {
            throw TransitionBlocked::because(
                'این ساعت بعد از لغو به نوبت دیگری داده شده است. برای برگرداندن این راننده، نوبت تازه ثبت کنید.',
            );
        }
    }

    private function stamp(Appointment $appointment, AppointmentStatus $to): void
    {
        $column = match ($to) {
            AppointmentStatus::Waiting => 'waiting_at',
            AppointmentStatus::Called => 'called_at',
            AppointmentStatus::CheckedIn => 'checked_in_at',
            AppointmentStatus::Loading => 'loading_started_at',
            AppointmentStatus::Loaded => 'loading_completed_at',
            AppointmentStatus::Completed => 'completed_at',
            AppointmentStatus::Cancelled, AppointmentStatus::Rejected => 'cancelled_at',
            AppointmentStatus::NoShow => 'no_show_at',
            default => null,
        };

        if ($column !== null) {
            $appointment->{$column} = now();
        }
    }
}
