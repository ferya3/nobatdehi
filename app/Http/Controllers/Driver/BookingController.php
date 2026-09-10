<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Slot\OpeningPreview;
use App\Domain\Truck\PlateNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreAppointmentRequest;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;
use App\Models\TruckType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * نوبت‌گیری راننده.
 *
 * راننده روز و ساعت انتخاب نمی‌کند. سه چیز می‌دهد — خودرو، بار، هویت — و
 * سامانه در جواب می‌گوید نوبتش کِی است. کارخانه مطب دکتر نیست؛ صف است.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly OpeningPreview $preview,
        private readonly CreateAppointment $createAppointment,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $driver = $this->driver($request);
        $factory = $this->factory();

        if ($factory === null) {
            return redirect()->route('driver.home')->with('error', 'در حال حاضر نوبت‌دهی فعال نیست.');
        }

        $activeCount = $driver->activeAppointments()->count();

        if ($activeCount >= $factory->max_active_per_mobile) {
            return redirect()->route('driver.home')->with(
                'error',
                "با این شماره حداکثر {$factory->max_active_per_mobile} نوبت فعال می‌توانید داشته باشید.",
            );
        }

        $lastTruck = $driver->trucks()
            ->with('truckType')
            ->orderByPivot('last_used_at', 'desc')
            ->first();

        return Inertia::render('Driver/Booking/Create', [
            'driverName' => $driver->name,
            'driverNationalCode' => $driver->national_code,
            'lastTruck' => $lastTruck ? [
                'plate' => $lastTruck->plate(),
                'truck_type_id' => $lastTruck->truck_type_id,
            ] : null,

            // هر نوع خودرو با نوبتی که همین حالا به آن می‌رسد. مدت بارگیری
            // فقط یک عدد روی کارت نیست — همان است که جای نوبت را تعیین می‌کند.
            'truckTypes' => $this->preview->forTruckTypes(
                $factory,
                TruckType::where('is_active', true)->orderBy('sort_order')->get(),
            ),

            'products' => Product::where('factory_id', $factory->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'description']),
            'plateLetters' => PlateNumber::LETTERS,
        ]);
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $driver = $this->driver($request);
        $factory = $this->factory();

        if ($factory === null) {
            return redirect()->route('driver.home')->with('error', 'در حال حاضر نوبت‌دهی فعال نیست.');
        }

        $product = Product::findOrFail($request->integer('product_id'));

        $truck = Truck::fromPlate($request->plate(), $request->integer('truck_type_id'));

        if ($truck->truck_type_id !== $request->integer('truck_type_id')) {
            $truck->update(['truck_type_id' => $request->integer('truck_type_id')]);
        }

        // حواله به نام راننده صادر می‌شود؛ نام و کد ملی همان‌جا قطعی می‌شوند
        $identity = array_filter([
            'name' => $request->string('driver_name')->toString(),
            'national_code' => $request->string('national_code')->toString(),
        ]);

        if (array_diff_assoc($identity, $driver->only(array_keys($identity)))) {
            $driver->update($identity);
        }

        try {
            $appointment = ($this->createAppointment)(new NewAppointment(
                factory: $factory,
                driver: $driver,
                truck: $truck,
                product: $product,
                idempotencyKey: $request->string('idempotency_key')->toString(),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            ));
        } catch (BookingException $e) {
            // «جا نیست» به نوع خودرو می‌چسبد، چون تنها چیزی که راننده می‌تواند
            // عوضش کند تا جا باز شود، همان است.
            throw ValidationException::withMessages([
                $e->reason === 'no_opening' ? 'truck_type_id' : 'plate_two' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('driver.appointments.show', $appointment)
            ->with('success', 'نوبت شما با موفقیت ثبت شد.');
    }

    private function driver(Request $request): Driver
    {
        return $request->user('driver');
    }

    private function factory(): ?Factory
    {
        return Factory::where('is_active', true)->orderBy('id')->first();
    }
}
