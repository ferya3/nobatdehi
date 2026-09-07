<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Actions;

use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Models\Appointment;
use App\Events\AppointmentCreated;
use App\Models\AppointmentSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * صدور نوبت.
 *
 * تنها جایی که یک ردیف appointments ساخته می‌شود. سه لایه دفاع در برابر
 * تخصیص بیش از ظرفیت دارد:
 *
 *   ۱. قفل Advisory روی (کارخانه، روز) — تمام نوبت‌گیری‌های آن روز را سریالی می‌کند
 *   ۲. SELECT ... FOR UPDATE روی ردیف اسلات
 *   ۳. CHECK (reserved_count <= capacity) در خود PostgreSQL
 *
 * لایه‌ی سوم هیچ‌وقت نباید فعال شود؛ اگر شد یعنی یکی از دو لایه‌ی قبل دور زده
 * شده و بهتر است تراکنش بترکد تا اینکه ظرفیت منفی شود.
 */
final class CreateAppointment
{
    public function __invoke(NewAppointment $data): Appointment
    {
        // مسیر سریع Idempotency: کلیک دوم و سوم راننده نباید تراکنش باز کند.
        if ($existing = $this->findByIdempotencyKey($data)) {
            return $existing;
        }

        return DB::transaction(function () use ($data) {
            $this->lockDay($data->factory->id, $data->slot->date);

            // دوباره داخل قفل: دو درخواست هم‌زمان با یک کلید، فقط یکی نوبت می‌سازد.
            if ($existing = $this->findByIdempotencyKey($data)) {
                return $existing;
            }

            $slot = AppointmentSlot::whereKey($data->slot->id)->lockForUpdate()->firstOrFail();

            $this->assertSlotBookable($data, $slot);
            $this->assertActorsAllowed($data);
            $this->assertLimits($data, $slot);

            $appointment = Appointment::create([
                'factory_id' => $data->factory->id,
                'number' => $this->nextNumber($data->factory->id, $slot->date),
                'driver_id' => $data->driver->id,
                'truck_id' => $data->truck->id,
                'product_id' => $data->product->id,
                // اولویت از محصول snapshot می‌شود، نه اینکه هر بار خوانده شود:
                // تغییر اولویت یک محصول نباید ترتیب صفِ دیروز را عوض کند.
                'priority' => (int) $data->product->priority,
                'slot_id' => $slot->id,
                'date' => $slot->date,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'status' => AppointmentStatus::Booked,
                'idempotency_key' => $data->idempotencyKey,
                'created_by_user_id' => $data->createdByUserId,
                'created_ip' => $data->ip,
                'user_agent' => $data->userAgent ? mb_substr($data->userAgent, 0, 512) : null,
                'note' => $data->note,
            ]);

            $slot->increment('reserved_count');

            $data->driver->trucks()->syncWithoutDetaching([
                $data->truck->id => ['last_used_at' => now()],
            ]);

            $appointment->transitions()->create([
                'from_status' => null,
                'to_status' => AppointmentStatus::Booked,
                'driver_id' => $data->createdByUserId ? null : $data->driver->id,
                'user_id' => $data->createdByUserId,
                'actor_label' => $data->createdByUserId ? null : 'راننده',
                'ip' => $data->ip,
                'created_at' => now(),
            ]);

            // بعد از commit اجرا می‌شود: Listener صف‌شده نباید نوبتی را بخواند
            // که تراکنشش هنوز بسته نشده.
            DB::afterCommit(fn () => AppointmentCreated::dispatch($appointment->id));

            return $appointment;
        });
    }

    private function findByIdempotencyKey(NewAppointment $data): ?Appointment
    {
        if ($data->idempotencyKey === null) {
            return null;
        }

        return Appointment::where('idempotency_key', $data->idempotencyKey)->first();
    }

    /**
     * قفل تراکنشی روی یک روز از یک کارخانه.
     *
     * با این قفل، شمارش ظرفیت روزانه و تخصیص شماره‌ی نوبت هم امن می‌شوند، نه
     * فقط ظرفیت یک اسلات. برای ۸۰ کامیون در روز سریالی‌کردن هزینه‌ای ندارد.
     */
    private function lockDay(int $factoryId, mixed $date): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // قفل ردیف اسلات و CHECK دیتابیس همچنان برقرارند
        }

        $day = (int) floor(CarbonImmutable::parse((string) $date)->getTimestamp() / 86400);

        DB::selectOne('SELECT pg_advisory_xact_lock(?::int, ?::int)', [$factoryId, $day]);
    }

    private function assertSlotBookable(NewAppointment $data, AppointmentSlot $slot): void
    {
        if ($slot->factory_id !== $data->factory->id) {
            throw BookingException::slotMismatch();
        }

        if ($slot->is_blocked) {
            throw BookingException::slotBlocked();
        }

        if ($slot->reserved_count >= $slot->capacity) {
            throw BookingException::slotFull();
        }

        $earliest = CarbonImmutable::now()->addMinutes($data->factory->booking_lead_minutes);

        if ($slot->startsAt()->lessThan($earliest)) {
            throw BookingException::slotPast();
        }

        $horizon = CarbonImmutable::today()->addDays($data->factory->booking_horizon_days)->endOfDay();

        if ($slot->startsAt()->greaterThan($horizon)) {
            throw BookingException::slotOutOfHorizon($data->factory->booking_horizon_days);
        }

        if (! $data->product->is_active || $data->product->factory_id !== $data->factory->id) {
            throw BookingException::productUnavailable();
        }
    }

    private function assertActorsAllowed(NewAppointment $data): void
    {
        if ($data->driver->is_blocked) {
            throw BookingException::driverBlocked($data->driver->blocked_reason);
        }

        if ($data->truck->is_blocked) {
            throw BookingException::truckBlocked($data->truck->blocked_reason);
        }
    }

    private function assertLimits(NewAppointment $data, AppointmentSlot $slot): void
    {
        $active = AppointmentStatus::activeValues();

        $driverActive = Appointment::where('driver_id', $data->driver->id)
            ->whereIn('status', $active)
            ->count();

        if ($driverActive >= $data->factory->max_active_per_mobile) {
            throw BookingException::mobileLimit($data->factory->max_active_per_mobile);
        }

        $truckActive = Appointment::where('truck_id', $data->truck->id)
            ->whereIn('status', $active)
            ->count();

        if ($truckActive >= $data->factory->max_active_per_plate) {
            throw BookingException::plateLimit($data->factory->max_active_per_plate);
        }

        $dayTotal = Appointment::where('factory_id', $data->factory->id)
            ->whereDate('date', $slot->date)
            ->whereIn('status', $active)
            ->count();

        if ($dayTotal >= $data->factory->daily_capacity) {
            throw BookingException::dailyCapacityReached();
        }
    }

    /** شماره‌ی نوبت: دنباله‌ی روزانه‌ی هر کارخانه، امن زیر قفل روز */
    private function nextNumber(int $factoryId, mixed $date): int
    {
        $max = Appointment::where('factory_id', $factoryId)
            ->whereDate('date', $date)
            ->max('number');

        return (int) $max + 1;
    }
}
