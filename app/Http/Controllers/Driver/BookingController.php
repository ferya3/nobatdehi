<?php

declare(strict_types=1);

namespace App\Http\Controllers\Driver;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Domain\Slot\AppointmentScheduler;
use App\Domain\Slot\OpeningPreview;
use App\Domain\Slot\SlotGenerator;
use App\Domain\Truck\PlateNumber;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\StoreAppointmentRequest;
use App\Models\Driver;
use App\Models\Factory;
use App\Models\Product;
use App\Models\Truck;
use App\Models\TruckType;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * نوبت‌گیری راننده.
 *
 * پیش‌فرض همان است که بود: راننده سه چیز می‌دهد — خودرو، بار، هویت — و
 * سامانه در جواب می‌گوید نوبتش کِی است. کارخانه مطب دکتر نیست؛ صف است.
 *
 * ولی صف مالِ امروز است. راننده‌ای که این هفته روز دیگری کار دارد، می‌تواند
 * روزی از فردا به بعد و ساعتی حوالیِ آن انتخاب کند. ساعت باز هم انتخابِ او
 * نیست: یک کف است و زمان‌بند اولین جای خالی از آنجا به بعد را می‌دهد.
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly OpeningPreview $preview,
        private readonly CreateAppointment $createAppointment,
        private readonly AppointmentScheduler $scheduler,
        private readonly SlotGenerator $slots,
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

            // روزهایی که راننده می‌تواند انتخاب کند — از فردا تا انتهای افق.
            // امروز عمداً نیست: صفِ امروز را سامانه می‌چیند.
            'bookableDays' => $this->bookableDays($factory),
        ]);
    }

    /**
     * روزهای قابل انتخاب، با برچسب شمسی.
     *
     * تعطیل‌ها بیرون‌اند: روزی که کارخانه باز نیست، انتخابش فقط یک بن‌بست
     * تازه می‌سازد.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bookableDays(?Factory $factory): array
    {
        if ($factory === null) {
            return [];
        }

        $days = [];
        $today = CarbonImmutable::today();

        for ($offset = 1; $offset <= (int) $factory->booking_horizon_days; $offset++) {
            $date = $today->addDays($offset);

            if ($this->slots->planFor($factory, $date) === null) {
                continue;
            }

            $days[] = [
                'date' => $date->toDateString(),
                'jalali' => Jalali::date($date),
                'day_label' => Jalali::dayLabel($date),
                // روی چیپِ باریکِ گوشی، «شنبه» و «۲۱ شهریور» دو خط می‌شوند
                'weekday' => Jalali::weekday($date),
                'day_month' => Jalali::dayMonth($date),
            ];
        }

        return $days;
    }

    /**
     * ساعت‌های آزادِ یک روز برای یک نوع خودرو.
     *
     * راننده‌ای که روز خاصی می‌خواهد نباید ساعتی تایپ کند و بعد بشنود «جا
     * نیست». اینجا همان چیزی گفته می‌شود که زمان‌بند موقع ثبت هم خواهد گفت.
     */
    public function openings(Request $request): JsonResponse
    {
        $factory = $this->factory();

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after:today'],
            'truck_type_id' => ['required', Rule::exists('truck_types', 'id')->where('is_active', true)],
        ]);

        if ($factory === null) {
            return response()->json(['windows' => []]);
        }

        $date = CarbonImmutable::parse($validated['date'])->startOfDay();

        if ($date->greaterThan(CarbonImmutable::today()->addDays((int) $factory->booking_horizon_days))) {
            return response()->json(['windows' => []]);
        }

        $type = TruckType::findOrFail($validated['truck_type_id']);

        return response()->json([
            'windows' => $this->scheduler->windowsOn(
                $factory,
                $date,
                $type->loading_minutes ?? (int) $factory->avg_loading_minutes,
            ),
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
                preferredStart: $request->preferredStart(),
            ));
        } catch (BookingException $e) {
            // خطا به همان فیلدی می‌چسبد که راننده می‌تواند عوضش کند تا جا باز
            // شود: «آن ساعت پر است» به ساعت، «جا نیست» به نوع خودرو.
            $field = match ($e->reason) {
                'requested_full', 'day_too_soon' => 'preferred_time',
                'no_opening' => 'truck_type_id',
                default => 'plate_two',
            };

            throw ValidationException::withMessages([$field => $e->getMessage()]);
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
