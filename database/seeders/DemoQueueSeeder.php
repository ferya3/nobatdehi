<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Slot\SlotGenerator;
use App\Domain\Truck\PlateNumber;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Models\Product;
use App\Models\Truck;
use App\Models\TruckType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * صف نمونه برای امروز — فقط برای محیط توسعه و نمایش.
 *
 * عمداً از CreateAppointment استفاده نمی‌کند: آن اکشن به‌درستی نوبت روی ساعت
 * گذشته را رد می‌کند، و اینجا دقیقاً به یک روز نیمه‌تمام نیاز داریم تا پنل
 * اپراتور با داده‌ی واقع‌نما دیده شود. انتقال‌های وضعیت اما از مسیر واقعی
 * می‌گذرند تا تاریخچه و زمان‌ها درست باشند.
 */
class DemoQueueSeeder extends Seeder
{
    private const DRIVERS = [
        ['علی رضایی', '09121001001', '12', 'ب', '345'],
        ['محمد کریمی', '09121001002', '45', 'ج', '231'],
        ['حسن مرادی', '09121001003', '65', 'د', '982'],
        ['رضا نیکو', '09121001004', '21', 'س', '123'],
        ['سعید احمدی', '09121001005', '33', 'ص', '514'],
        ['جواد قاسمی', '09121001006', '78', 'ط', '667'],
        ['امیر توکلی', '09121001007', '19', 'ع', '204'],
        ['مهدی سلطانی', '09121001008', '52', 'ق', '890'],
        ['ناصر بیات', '09121001009', '84', 'ل', '351'],
        ['کاظم فرهادی', '09121001010', '27', 'م', '478'],
    ];

    /** مسیر هر نوبت تا کجا جلو رفته باشد */
    private const JOURNEYS = [
        [],
        [S::Waiting],
        [S::Waiting],
        [S::Waiting, S::Called],
        [S::Waiting, S::Called, S::CheckedIn],
        [S::Waiting, S::Called, S::CheckedIn, S::Loading],
        [S::Waiting, S::Called, S::CheckedIn, S::Loading, S::Loaded, S::Completed],
        [S::Waiting, S::Called, S::CheckedIn, S::Loading, S::Loaded, S::Completed],
        [S::Waiting, S::NoShow],
        [S::Cancelled],
    ];

    public function run(): void
    {
        $factory = Factory::where('slug', 'main')->firstOrFail();
        $operator = User::role(\App\Domain\Access\Roles::OPERATOR)->firstOrFail();
        $line = LoadingPoint::where('factory_id', $factory->id)->firstOrFail();
        $products = Product::where('factory_id', $factory->id)->get();
        $truckTypes = TruckType::orderBy('sort_order')->get();

        $today = CarbonImmutable::today();
        (new SlotGenerator())->generateForDate($factory, $today);

        $slots = AppointmentSlot::where('factory_id', $factory->id)
            ->whereDate('date', $today->toDateString())
            ->orderBy('start_time')
            ->get();

        if ($slots->isEmpty()) {
            $this->command?->warn('امروز روز کاری نیست؛ داده‌ی نمونه ساخته نشد.');

            return;
        }

        $transition = app(TransitionAppointment::class);
        $actor = Actor::user($operator);
        $number = (int) Appointment::where('factory_id', $factory->id)
            ->whereDate('date', $today->toDateString())
            ->max('number');

        foreach (self::DRIVERS as $index => [$name, $mobile, $two, $letter, $three]) {
            $driver = Driver::updateOrCreate(['mobile' => $mobile], ['name' => $name]);

            $truck = Truck::fromPlate(
                PlateNumber::make($two, $letter, $three, '67'),
                $truckTypes[$index % $truckTypes->count()]->id,
            );

            $slot = $slots[$index % $slots->count()];

            $appointment = Appointment::create([
                'factory_id' => $factory->id,
                'number' => ++$number,
                'driver_id' => $driver->id,
                'truck_id' => $truck->id,
                'product_id' => $products[$index % $products->count()]->id,
                'slot_id' => $slot->id,
                'date' => $slot->date,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'status' => S::Booked,
                'created_ip' => '127.0.0.1',
            ]);

            $slot->increment('reserved_count');
            $driver->trucks()->syncWithoutDetaching([$truck->id => ['last_used_at' => now()]]);

            $appointment->transitions()->create([
                'to_status' => S::Booked,
                'driver_id' => $driver->id,
                'actor_label' => 'راننده',
                'created_at' => now(),
            ]);

            foreach (self::JOURNEYS[$index] ?? [] as $status) {
                $appointment = $transition(
                    $appointment->fresh(),
                    $status,
                    $actor,
                    match ($status) {
                        S::NoShow => 'راننده مراجعه نکرد',
                        S::Cancelled => 'انصراف راننده',
                        default => null,
                    },
                    $status === S::Loading ? $line->id : null,
                );
            }

            $this->backdate($appointment->fresh(), $index);
        }

        $this->command?->info('صف نمونه‌ی امروز ساخته شد: '.count(self::DRIVERS).' نوبت.');
    }


    /**
     * زمان‌های واقع‌نما.
     *
     * seeder همه‌ی انتقال‌ها را در چند میلی‌ثانیه انجام می‌دهد، پس بدون این،
     * «متوسط انتظار» و «متوسط بارگیری» در گزارش صفر می‌شوند و صفحه‌ی گزارش
     * چیزی برای نشان دادن ندارد.
     */
    private function backdate(\App\Models\Appointment $appointment, int $index): void
    {
        if ($appointment->checked_in_at === null) {
            return;
        }

        $wait = 12 + ($index * 7) % 35;          // ۱۲ تا ۴۶ دقیقه انتظار
        $loading = 18 + ($index * 5) % 25;       // ۱۸ تا ۴۲ دقیقه بارگیری

        $checkedIn = $appointment->startsAt()->addMinutes(5 + $index);

        $times = ['checked_in_at' => $checkedIn];

        if ($appointment->loading_started_at !== null) {
            $times['loading_started_at'] = $checkedIn->addMinutes($wait);
        }

        if ($appointment->loading_completed_at !== null) {
            $times['loading_completed_at'] = $checkedIn->addMinutes($wait + $loading);
        }

        if ($appointment->completed_at !== null) {
            $times['completed_at'] = $checkedIn->addMinutes($wait + $loading + 6);
        }

        $appointment->forceFill($times)->save();
    }
}
