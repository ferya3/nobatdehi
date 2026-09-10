<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Truck\PlateNumber;
use App\Domain\Weighbridge\WeighingService;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;
use App\Models\TruckType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * باسکول شناور: خالص هرچیزی است که باسکول می‌گوید.
 *
 * تناژ با کامیون می‌آید و یک سقف است، نه یک الزام. خاوری که نصفِ ظرفیتش بار
 * زده هیچ اشکالی ندارد — بارِ نصفه یک انتخاب است، نه یک خطا.
 *
 * قانون قبلی، تناژِ محصول را الزام می‌گرفت. نتیجه‌اش این بود که هر بارگیریِ
 * کوچک قفل می‌شد و اخطاری می‌رفت که چند روز بعد کسی جدی‌اش نمی‌گرفت.
 */
final class FloatingScaleTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private WeighingService $weighing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->weighing = app(WeighingService::class);
    }

    private function truck(?string $typeCode): Appointment
    {
        $truck = Truck::fromPlate(PlateNumber::make('12', 'ب', '345', '11'));

        if ($typeCode !== null) {
            $truck->forceFill(['truck_type_id' => TruckType::where('code', $typeCode)->value('id')])->save();
        }

        $appointment = new Appointment;
        $appointment->setRelation('truck', $truck->refresh()->load('truckType'));
        $appointment->setRelation(
            'product',
            Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail(),
        );
        $appointment->setRelation('factory', $this->factory);

        return $appointment;
    }

    #[Test]
    public function the_capacity_comes_from_the_truck_not_the_product(): void
    {
        // ظرفیت خاور ۶ تن است و تریلی ۳۰ تن — یک محصول، دو عدد
        $this->assertSame(6000.0, $this->weighing->capacityKg($this->truck('khavar')));
        $this->assertSame(30000.0, $this->weighing->capacityKg($this->truck('teriler')));
    }

    #[Test]
    public function a_half_loaded_truck_is_perfectly_fine(): void
    {
        // خاورِ شش‌تنی که سه تن بار زده: باسکول همین را می‌گوید و تمام
        $result = $this->weighing->evaluate($this->truck('khavar'), 3000, 6000);

        $this->assertSame(3000.0, $result->netKg);
        $this->assertTrue($result->isClear());
        $this->assertFalse($result->hasDiscrepancy());
    }

    #[Test]
    public function a_big_truck_taking_a_small_load_is_also_fine(): void
    {
        // همان چیزی که پیش از این «مغایرت ۲۴ تنی» اعلام می‌شد
        $result = $this->weighing->evaluate($this->truck('teriler'), 2000, 8000);

        $this->assertSame(6000.0, $result->netKg);
        $this->assertTrue($result->isClear());
    }

    #[Test]
    public function the_net_weight_is_only_ever_full_minus_empty(): void
    {
        $result = $this->weighing->evaluate($this->truck('teriler'), 13750, 41500);

        $this->assertSame(27750.0, $result->netKg);
    }

    #[Test]
    public function going_over_the_trucks_capacity_still_blocks(): void
    {
        // این یکی قابل مذاکره نیست: کامیون اضافه‌بار حق خروج ندارد
        $result = $this->weighing->evaluate($this->truck('khavar'), 3000, 10000);

        $this->assertSame(7000.0, $result->netKg);
        $this->assertTrue($result->isOverload);
        $this->assertSame(1000.0, $result->overloadKg);
        $this->assertFalse($result->isClear());
        $this->assertSame('اضافه‌بار', $result->discrepancyLabel());
    }

    #[Test]
    public function exactly_at_capacity_is_not_an_overload(): void
    {
        $result = $this->weighing->evaluate($this->truck('khavar'), 3000, 9000);

        $this->assertSame(6000.0, $result->netKg);
        $this->assertFalse($result->isOverload);
        $this->assertTrue($result->isClear());
    }

    #[Test]
    public function a_truck_with_no_type_has_no_ceiling(): void
    {
        // نوعش تعریف نشده، پس سامانه سقفی از خودش نمی‌سازد
        $appointment = $this->truck(null);

        $this->assertNull($this->weighing->capacityKg($appointment));

        $result = $this->weighing->evaluate($appointment, 3000, 90000);

        $this->assertTrue($result->isClear());
    }

    #[Test]
    public function a_full_weight_below_the_empty_one_is_still_refused(): void
    {
        // این ایرادِ بار نیست، عددِ اشتباه تایپ‌شده است
        $result = $this->weighing->evaluate($this->truck('khavar'), 6000, 4000);

        $this->assertFalse($result->isClear());
        $this->assertFalse($result->hasDiscrepancy());
    }
}
