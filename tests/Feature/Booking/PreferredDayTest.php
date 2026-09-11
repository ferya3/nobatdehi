<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Slot\AppointmentScheduler;
use App\Domain\Slot\SlotGenerator;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\TruckType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * راننده‌ای که روزِ دیگری کار دارد.
 *
 * پیش‌فرض عوض نمی‌شود: صفِ امروز را سامانه می‌چیند و راننده ساعت نمی‌چیند.
 * ولی کسی که فردا یا هفته‌ی بعد کار دارد، باید بتواند بگوید کِی — وگرنه
 * مجبور است همان روز نوبت بگیرد و نیاید، که همان عدم‌حضوری است که ظرفیت
 * را می‌سوزاند.
 *
 * ساعت باز هم انتخابِ او نیست: یک *کف* است. زمان‌بند اولین جای خالیِ آن روز
 * از آن ساعت به بعد را می‌دهد، وگرنه هر کسی می‌توانست نوبتی روی لاینِ
 * اشغال بنشاند.
 */
final class PreferredDayTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);

        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();
    }

    /** فردایی که کارخانه باز است */
    private function nextOpenDay(): CarbonImmutable
    {
        $slots = app(SlotGenerator::class);
        $date = CarbonImmutable::today()->addDay();

        while ($slots->planFor($this->factory, $date) === null) {
            $date = $date->addDay();
        }

        return $date;
    }

    private function book(?CarbonImmutable $preferred, string $typeCode = 'teriler'): Appointment
    {
        $this->seq++;

        $truck = $this->makeTruck('1'.$this->seq, 'ب', '34'.$this->seq, '11');
        $truck->forceFill(['truck_type_id' => TruckType::where('code', $typeCode)->value('id')])->save();

        return app(CreateAppointment::class)(new NewAppointment(
            factory: $this->factory,
            driver: Driver::create(['mobile' => '0912000000'.$this->seq, 'name' => 'راننده']),
            truck: $truck->refresh(),
            product: $this->product,
            idempotencyKey: 'pref-'.$this->seq,
            ip: '127.0.0.1',
            preferredStart: $preferred,
        ));
    }

    #[Test]
    public function without_a_preference_nothing_changes(): void
    {
        // همان رفتار همیشگی: زودترین جای خالی، معمولاً امروز
        $appointment = $this->book(null);

        $this->assertTrue($appointment->date->isToday());
    }

    #[Test]
    public function a_driver_can_ask_for_a_later_day(): void
    {
        $day = $this->nextOpenDay();

        $appointment = $this->book($day->setTime(10, 0));

        $this->assertSame($day->toDateString(), $appointment->date->toDateString());
        $this->assertSame('10:00:00', (string) $appointment->start_time);
    }

    #[Test]
    public function the_hour_is_a_floor_and_not_a_promise(): void
    {
        $day = $this->nextOpenDay();

        // لاین‌ها را از ساعت ۱۰ تا ۱۱ پر می‌کنیم
        $lines = (int) $this->factory->loading_lines;

        for ($line = 1; $line <= $lines; $line++) {
            Appointment::create([
                'factory_id' => $this->factory->id,
                'number' => 900 + $line,
                'driver_id' => Driver::create(['mobile' => '0913000000'.$line, 'name' => 'پرکننده'])->id,
                'truck_id' => $this->makeTruck('9'.$line, 'ب', '99'.$line, '11')->id,
                'product_id' => $this->product->id,
                'date' => $day->toDateString(),
                'start_time' => '10:00:00',
                'end_time' => '11:00:00',
                'line_no' => $line,
                'status' => AppointmentStatus::Waiting,
            ]);
        }

        // راننده ساعت ۱۰ می‌خواهد؛ سامانه اولین جای خالی بعد از آن را می‌دهد
        $appointment = $this->book($day->setTime(10, 0));

        $this->assertSame($day->toDateString(), $appointment->date->toDateString());
        $this->assertSame('11:00:00', (string) $appointment->start_time);
    }

    #[Test]
    public function today_is_never_a_choice(): void
    {
        // صفِ امروز را سامانه می‌چیند؛ انتخاب ساعت در آن یعنی جلوی صف زدن
        $this->expectException(BookingException::class);
        $this->expectExceptionMessage('انتخاب روز از فردا به بعد ممکن است.');

        $this->book(CarbonImmutable::today()->setTime(10, 0));
    }

    #[Test]
    public function a_day_beyond_the_horizon_is_refused(): void
    {
        $this->expectException(BookingException::class);

        $this->book(
            CarbonImmutable::today()->addDays((int) $this->factory->booking_horizon_days + 1)->setTime(10, 0),
        );
    }

    #[Test]
    public function an_hour_with_nothing_left_after_it_is_refused_rather_than_pushed_to_another_day(): void
    {
        // راننده «فردا» خواسته؛ اگر فردا جا نشود باید همین را بشنود، نه
        // اینکه نوبتی روی پس‌فردا بگیرد و خبردار نشود
        $day = $this->nextOpenDay();

        [, $closesAt] = app(SlotGenerator::class)->planFor($this->factory, $day);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/جای خالی نیست/');

        $this->book($closesAt->subMinutes(5));
    }

    #[Test]
    public function the_windows_a_driver_is_shown_are_the_ones_the_scheduler_will_honour(): void
    {
        $day = $this->nextOpenDay();
        $scheduler = app(AppointmentScheduler::class);
        $minutes = (int) TruckType::where('code', 'teriler')->value('loading_minutes');

        $windows = $scheduler->windowsOn($this->factory, $day, $minutes);

        $this->assertNotEmpty($windows);

        // هر پنجره‌ای که «آزاد» اعلام شده، باید واقعاً قابل رزرو باشد
        $free = collect($windows)->firstWhere('available', true);

        $this->assertNotNull($free);

        $appointment = $this->book(CarbonImmutable::parse($day->toDateString().' '.$free['time']));

        $this->assertSame($free['starts_at'], substr((string) $appointment->start_time, 0, 5));
    }
}
