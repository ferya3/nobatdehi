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
 * تناژِ مورد انتظار مالِ این کامیون است، نه فقط این محصول.
 *
 * تناژ روی محصول تعریف می‌شود («هر بارگیریِ این محصول ۳۰ تن») ولی کامیون
 * است که آن را می‌برد. یک تکِ ده‌تنی هرگز به سی تن نمی‌رسد؛ سنجیدنش با
 * عددِ محصول یعنی هر بارگیریِ تک «مغایرت» می‌شود و چند روز بعد کسی دیگر
 * این هشدار را جدی نمی‌گیرد.
 */
final class ExpectedTonnageTest extends TestCase
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

    private function appointmentWith(?string $truckTypeCode, ?float $productTons): Appointment
    {
        $product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();
        $product->update(['load_tons' => $productTons]);

        $truck = Truck::fromPlate(PlateNumber::make('12', 'ب', '345', '11'));

        if ($truckTypeCode !== null) {
            $truck->forceFill(['truck_type_id' => TruckType::where('code', $truckTypeCode)->value('id')])->save();
        }

        $appointment = new Appointment;
        $appointment->setRelation('product', $product->refresh());
        $appointment->setRelation('truck', $truck->refresh()->load('truckType'));
        $appointment->setRelation('factory', $this->factory);

        return $appointment;
    }

    #[Test]
    public function a_small_truck_is_never_expected_to_carry_a_big_waybill(): void
    {
        // ظرفیت «تک» ۱۰ تن است و تناژ محصول ۳۰ تن
        $appointment = $this->appointmentWith('tak', 30);

        $this->assertSame(10000.0, $this->weighing->expectedNetKg($appointment));
    }

    #[Test]
    public function a_truck_big_enough_is_judged_against_the_waybill(): void
    {
        $appointment = $this->appointmentWith('teriler', 30);

        $this->assertSame(30000.0, $this->weighing->expectedNetKg($appointment));
    }

    #[Test]
    public function a_small_truck_loading_its_share_is_not_a_discrepancy(): void
    {
        // همان چیزی که در عمل دیده شد: تک با ۱۰ تن بار، «مغایرت ۲۰ تنی»
        $appointment = $this->appointmentWith('tak', 30);

        $result = $this->weighing->evaluate($appointment, 8000, 18000);

        $this->assertFalse($result->hasDiscrepancy());
        $this->assertTrue($result->isClear());
    }

    #[Test]
    public function a_short_load_on_a_matching_truck_is_still_a_discrepancy(): void
    {
        // این عمدی است: تریلیِ سی‌تنی که شش تن برده، واقعاً کسری دارد
        $appointment = $this->appointmentWith('teriler', 30);

        $result = $this->weighing->evaluate($appointment, 2000, 8000);

        $this->assertTrue($result->hasDiscrepancy());
        $this->assertSame(6000.0, $result->netKg);
        $this->assertSame(30000.0, $result->expectedKg);
    }

    #[Test]
    public function a_product_with_no_stated_tonnage_is_left_uncompared(): void
    {
        // کارخانه نگفته چقدر انتظار دارد؛ سامانه هم از خودش عددی نمی‌سازد
        $appointment = $this->appointmentWith('teriler', null);

        $this->assertNull($this->weighing->expectedNetKg($appointment));

        $result = $this->weighing->evaluate($appointment, 14000, 20000);

        $this->assertFalse($result->hasDiscrepancy());
        $this->assertTrue($result->isClear());
    }

    #[Test]
    public function overload_is_still_measured_against_the_truck(): void
    {
        // سقفِ ظرفیت جای خودش می‌ماند: تکِ ده‌تنی با دوازده تن، اضافه‌بار است
        $appointment = $this->appointmentWith('tak', 30);

        $result = $this->weighing->evaluate($appointment, 8000, 20000);

        $this->assertTrue($result->isOverload);
        $this->assertFalse($result->isClear());
    }
}
