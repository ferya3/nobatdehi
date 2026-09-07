<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Slot\SlotGenerator;
use App\Domain\Truck\PlateNumber;
use App\Models\AppointmentSlot;
use App\Models\Driver;
use App\Models\Factory;
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

    protected function booking(
        Factory $factory,
        Driver $driver,
        Truck $truck,
        AppointmentSlot $slot,
        ?Product $product = null,
        ?string $idempotencyKey = null,
    ): NewAppointment {
        return new NewAppointment(
            factory: $factory,
            driver: $driver,
            truck: $truck,
            product: $product ?? Product::where('factory_id', $factory->id)->firstOrFail(),
            slot: $slot,
            idempotencyKey: $idempotencyKey,
            ip: '127.0.0.1',
        );
    }
}
