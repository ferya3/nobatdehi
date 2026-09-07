<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Actions;

use App\Domain\Appointment\AppointmentStateMachine;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Events\AppointmentTransitioned;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use Illuminate\Support\Facades\DB;

/**
 * تنها راه تغییر وضعیت یک نوبت.
 *
 * قرارداد ظرفیت: appointment_slots.reserved_count همیشه برابر تعداد نوبت‌های
 * «فعال» آن اسلات است. پس هر خروج از مجموعه‌ی فعال ظرفیت را آزاد و هر بازگشت
 * به آن، ظرفیت را دوباره اشغال می‌کند — و بازگشت می‌تواند به‌دلیل پرشدن اسلات
 * شکست بخورد. این دقیقاً همان حالتی است که در آن باید شکست بخورد.
 */
final class TransitionAppointment
{
    public function __construct(private readonly AppointmentStateMachine $machine) {}

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

            $this->syncCapacity($fresh, $from, $to);

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

    /** ظرفیت اسلات را با ورود به/خروج از مجموعه‌ی فعال هماهنگ می‌کند. */
    private function syncCapacity(Appointment $appointment, AppointmentStatus $from, AppointmentStatus $to): void
    {
        if ($from->isActive() === $to->isActive()) {
            return;
        }

        /** @var AppointmentSlot $slot */
        $slot = AppointmentSlot::whereKey($appointment->slot_id)->lockForUpdate()->firstOrFail();

        if ($to->isActive()) {
            if ($slot->reserved_count >= $slot->capacity) {
                throw BookingException::slotFull();
            }

            $slot->increment('reserved_count');

            return;
        }

        if ($slot->reserved_count > 0) {
            $slot->decrement('reserved_count');
        }
    }

    /** زمان هر وضعیت را ثبت می‌کند — پایه‌ی تمام گزارش‌های زمانی. */
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
