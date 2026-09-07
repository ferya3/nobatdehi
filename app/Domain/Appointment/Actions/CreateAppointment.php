<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Actions;

use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Slot\AppointmentScheduler;
use App\Events\AppointmentCreated;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * صدور نوبت.
 *
 * تنها جایی که یک ردیف appointments ساخته می‌شود. ساعت نوبت اینجا حساب
 * می‌شود و نه در درخواست: راننده ساعت انتخاب نمی‌کند، سامانه اعلام می‌کند.
 *
 * دو لایه دفاع در برابر دو کامیون روی یک لاین در یک لحظه:
 *
 *   ۱. قفل Advisory روی (کارخانه، روز) — تمام نوبت‌گیری‌های آن روز را سریالی
 *      می‌کند، پس زمان‌بند همیشه صفِ کامل و تازه را می‌بیند
 *   ۲. UNIQUE (کارخانه، روز، لاین، ساعت شروع) در خود PostgreSQL
 *
 * لایه‌ی دوم هیچ‌وقت نباید فعال شود؛ اگر شد یعنی لایه‌ی اول دور زده شده و
 * بهتر است تراکنش بترکد تا اینکه دو کامیون هم‌زمان روی یک لاین بنشینند.
 */
final class CreateAppointment
{
    public function __construct(private readonly AppointmentScheduler $scheduler) {}

    public function __invoke(NewAppointment $data): Appointment
    {
        // مسیر سریع Idempotency: کلیک دوم و سوم راننده نباید تراکنش باز کند.
        if ($existing = $this->findByIdempotencyKey($data)) {
            return $existing;
        }

        return DB::transaction(function () use ($data) {
            // قفل روی روزِ *امروز* گرفته می‌شود و نه روزِ نوبت: هنوز نمی‌دانیم
            // نوبت به کدام روز می‌افتد، و همین را زمان‌بند تعیین می‌کند. قفلِ
            // امروز کافی است چون هر نوبت‌گیری از امروز شروع به گشتن می‌کند.
            $this->lockDay($data->factory->id, CarbonImmutable::today());

            // دوباره داخل قفل: دو درخواست هم‌زمان با یک کلید، فقط یکی نوبت می‌سازد.
            if ($existing = $this->findByIdempotencyKey($data)) {
                return $existing;
            }

            $this->assertActorsAllowed($data);
            $this->assertProductAvailable($data);
            $this->assertLimits($data);

            $opening = $this->scheduler->nextOpening($data->factory, $this->loadingMinutes($data));

            if ($opening === null) {
                throw BookingException::noOpening((int) $data->factory->booking_horizon_days);
            }

            $appointment = Appointment::create([
                'factory_id' => $data->factory->id,
                'number' => $this->nextNumber($data->factory->id, $opening->date->toDateString()),
                'driver_id' => $data->driver->id,
                'truck_id' => $data->truck->id,
                'product_id' => $data->product->id,
                // اولویت از محصول snapshot می‌شود، نه اینکه هر بار خوانده شود:
                // تغییر اولویت یک محصول نباید ترتیب صفِ دیروز را عوض کند.
                'priority' => (int) $data->product->priority,
                'date' => $opening->date->toDateString(),
                'start_time' => $opening->startTime(),
                'end_time' => $opening->endTime(),
                'line_no' => $opening->line,
                'status' => AppointmentStatus::Booked,
                'idempotency_key' => $data->idempotencyKey,
                'created_by_user_id' => $data->createdByUserId,
                'created_ip' => $data->ip,
                'user_agent' => $data->userAgent ? mb_substr($data->userAgent, 0, 512) : null,
                'note' => $data->note,
            ]);

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
     * قفل تراکنشی روی نوبت‌گیریِ یک کارخانه.
     *
     * با این قفل، زمان‌بند همیشه صفِ کامل را می‌بیند و دو راننده‌ی هم‌زمان
     * یک ساعت نمی‌گیرند. تخصیص شماره‌ی نوبت هم زیر همین قفل امن می‌شود.
     * برای ۸۰ کامیون در روز، سریالی‌کردن هزینه‌ای ندارد.
     */
    private function lockDay(int $factoryId, mixed $date): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // یکتاییِ (کارخانه، روز، لاین، ساعت) همچنان برقرار است
        }

        $day = (int) floor(CarbonImmutable::parse((string) $date)->getTimestamp() / 86400);

        DB::selectOne('SELECT pg_advisory_xact_lock(?::int, ?::int)', [$factoryId, $day]);
    }

    private function assertProductAvailable(NewAppointment $data): void
    {
        if (! $data->product->is_active || $data->product->factory_id !== $data->factory->id) {
            throw BookingException::productUnavailable();
        }
    }

    /**
     * چقدر طول می‌کشد این کامیون بارگیری شود.
     *
     * همان زنجیره‌ی Appointment::expectedLoadingMinutes() ولی پیش از ساخته
     * شدن نوبت: نوع کامیون دقیق‌ترین را می‌داند، بعد محصول، و در آخر میانگین
     * کارخانه به‌عنوان تور ایمنی.
     */
    private function loadingMinutes(NewAppointment $data): int
    {
        return $data->truck->truckType?->loading_minutes
            ?? $data->product->loading_minutes
            ?? (int) $data->factory->avg_loading_minutes;
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

    private function assertLimits(NewAppointment $data): void
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

        // سقف روزانه اینجا بررسی نمی‌شود: هنوز معلوم نیست نوبت به کدام روز
        // می‌افتد. زمان‌بند روزِ پر را رد می‌کند و سراغ روز بعد می‌رود.
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
