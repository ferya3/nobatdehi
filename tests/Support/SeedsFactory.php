<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Slot\SlotGenerator;
use App\Domain\Weighbridge\ExitPermit;
use App\Domain\Truck\PlateNumber;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Truck;
use Carbon\CarbonImmutable;
use Database\Seeders\FactorySeeder;
use Database\Seeders\RoleSeeder;

trait SeedsFactory
{
    protected function seedFactory(): Factory
    {
        $this->seed(RoleSeeder::class);
        $this->seed(FactorySeeder::class);

        return Factory::where('slug', 'main')->firstOrFail();
    }

    protected function makeDriver(string $mobile): Driver
    {
        return Driver::create(['mobile' => $mobile, 'name' => 'راننده '.$mobile]);
    }

    protected function makeTruck(string $two, string $letter, string $three, string $iran): Truck
    {
        return Truck::fromPlate(PlateNumber::make($two, $letter, $three, $iran));
    }

    /** اولین اسلات قابل رزرو در فردا — همیشه خارج از محدوده‌ی lead time است */
    protected function futureSlot(Factory $factory, int $index = 0): AppointmentSlot
    {
        $date = CarbonImmutable::tomorrow();

        while ((new SlotGenerator())->planFor($factory, $date) === null) {
            $date = $date->addDay();
        }

        (new SlotGenerator())->generateForDate($factory, $date);

        return AppointmentSlot::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->orderBy('start_time')
            ->skip($index)
            ->firstOrFail();
    }

    /**
     * زمان را روی صبحِ اولین روزِ کاری قفل می‌کند.
     *
     * بدون این، تستِ «اسلات امروز» شب‌ها یا جمعه‌ها می‌افتاد — نه به‌خاطر باگ،
     * که چون آن ساعت اصلاً اسلاتی وجود ندارد.
     */
    protected function freezeOnWorkingMorning(Factory $factory): CarbonImmutable
    {
        $date = CarbonImmutable::today();

        while ((new SlotGenerator())->planFor($factory, $date) === null) {
            $date = $date->addDay();
        }

        $moment = $date->setTime(7, 0);

        $this->travelTo($moment);

        return $moment;
    }

    /** اسلاتی از «امروز» که هنوز به فاصله‌ی مجاز رزرو نرسیده است */
    protected function todaySlot(Factory $factory, int $index = 0): AppointmentSlot
    {
        $today = CarbonImmutable::today();

        (new SlotGenerator())->generateForDate($factory, $today);

        $earliest = CarbonImmutable::now()->addMinutes((int) $factory->booking_lead_minutes);

        return AppointmentSlot::where('factory_id', $factory->id)
            ->whereDate('date', $today->toDateString())
            ->where('start_time', '>', $earliest->format('H:i:s'))
            ->orderBy('start_time')
            ->skip($index)
            ->firstOrFail();
    }

    /**
     * باسکول اول را پر می‌کند.
     *
     * از فاز باسکول به بعد، «شروع بارگیری» شرط دارد؛ تست‌هایی که فقط
     * می‌خواهند از این مرحله رد شوند، این را صدا می‌زنند نه اینکه شرط را
     * دور بزنند.
     */
    protected function recordTare(Appointment $appointment, float $kg = 14000): LoadingRecord
    {
        return LoadingRecord::updateOrCreate(
            ['appointment_id' => $appointment->id],
            ['empty_weight_kg' => $kg, 'tare_weighed_at' => now(), 'tare_source' => 'device'],
        );
    }

    /** باسکول دوم و برگه‌ی خروج — شرط ثبت خروج */
    protected function recordGross(Appointment $appointment, float $kg = 34000): LoadingRecord
    {
        $record = LoadingRecord::firstOrCreate(['appointment_id' => $appointment->id]);

        $record->forceFill([
            'loaded_weight_kg' => $kg,
            'gross_weighed_at' => now(),
            'gross_source' => 'device',
            'net_weight_kg' => $kg - (float) $record->empty_weight_kg,
        ])->save();

        ExitPermit::issue($appointment, $record);

        return $record->refresh();
    }

    /**
     * درخواست نوبت — بدون ساعت.
     *
     * پارامتر اسلات حذف شد چون دیگر کسی اسلات انتخاب نمی‌کند؛ زمان‌بند
     * سمت سرور تعیینش می‌کند.
     */
    protected function booking(
        Factory $factory,
        Driver $driver,
        Truck $truck,
        ?Product $product = null,
        ?string $idempotencyKey = null,
    ): NewAppointment {
        return new NewAppointment(
            factory: $factory,
            driver: $driver,
            truck: $truck,
            product: $product ?? Product::where('factory_id', $factory->id)->firstOrFail(),
            idempotencyKey: $idempotencyKey,
            ip: '127.0.0.1',
        );
    }
}
