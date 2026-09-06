<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Slot\AvailabilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreAppointmentRequest;
use App\Models\AppointmentSlot;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;
use App\Models\TruckType;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BookingController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availability,
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

        $selectedDate = $this->requestedDate($request, $factory);

        $lastTruck = $driver->trucks()
            ->with('truckType')
            ->orderByPivot('last_used_at', 'desc')
            ->first();

        return Inertia::render('Driver/Booking/Create', [
            'driverName' => $driver->name,
            'lastTruck' => $lastTruck ? [
                'plate' => $lastTruck->plate(),
                'truck_type_id' => $lastTruck->truck_type_id,
            ] : null,
            'truckTypes' => TruckType::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'capacity_tons']),
            'products' => Product::where('factory_id', $factory->id)
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'load_tons', 'description']),
            'days' => $this->availability->days($factory),
            'plateLetters' => \App\Domain\Truck\PlateNumber::LETTERS,

            // ساعت‌های یک روز با partial reload روی همین مسیر گرفته می‌شوند:
            // router.reload({ only: ['slots'], data: { date } })
            'selectedDate' => $selectedDate?->toDateString(),
            'slots' => $selectedDate
                ? $this->availability->slotsForDate($factory, $selectedDate)
                : [],
        ]);
    }

    public function store(StoreAppointmentRequest $request): RedirectResponse
    {
        $driver = $this->driver($request);
        $factory = $this->factory();

        if ($factory === null) {
            return redirect()->route('driver.home')->with('error', 'در حال حاضر نوبت‌دهی فعال نیست.');
        }

        $slot = AppointmentSlot::findOrFail($request->integer('slot_id'));
        $product = Product::findOrFail($request->integer('product_id'));

        $truck = Truck::fromPlate($request->plate(), $request->integer('truck_type_id'));

        if ($truck->truck_type_id !== $request->integer('truck_type_id')) {
            $truck->update(['truck_type_id' => $request->integer('truck_type_id')]);
        }

        if ($driver->name !== $request->string('driver_name')->toString()) {
            $driver->update(['name' => $request->string('driver_name')->toString()]);
        }

        try {
            $appointment = ($this->createAppointment)(new NewAppointment(
                factory: $factory,
                driver: $driver,
                truck: $truck,
                product: $product,
                slot: $slot,
                idempotencyKey: $request->string('idempotency_key')->toString(),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            ));
        } catch (BookingException $e) {
            throw ValidationException::withMessages([
                // خطای ظرفیت به فیلد ساعت می‌چسبد تا راننده بداند کجا را عوض کند
                in_array($e->reason, ['slot_full', 'slot_blocked', 'slot_past', 'slot_out_of_horizon'], true)
                    ? 'slot_id'
                    : 'plate_two' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('driver.appointments.show', $appointment)
            ->with('success', 'نوبت شما با موفقیت ثبت شد.');
    }

    /** تاریخ درخواستی، محدود به افق نوبت‌دهی */
    private function requestedDate(Request $request, Factory $factory): ?CarbonImmutable
    {
        $raw = $request->string('date')->toString();

        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        $date = CarbonImmutable::parse($raw)->startOfDay();
        $last = CarbonImmutable::today()->addDays($factory->booking_horizon_days);

        if ($date->lessThan(CarbonImmutable::today()) || $date->greaterThan($last)) {
            return null;
        }

        return $date;
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
