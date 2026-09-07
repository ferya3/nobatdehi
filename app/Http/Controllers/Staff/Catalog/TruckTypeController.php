<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff\Catalog;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\Catalog\StoreTruckTypeRequest;
use App\Http\Requests\Staff\Catalog\UpdateTruckTypeRequest;
use App\Models\Factory;
use App\Models\Truck;
use App\Models\TruckType;
use App\Support\Digits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * انواع کامیون بین کارخانه‌ها مشترک‌اند (پلاک و ناوگان ملی است، نه کارخانه‌ای)،
 * پس برخلاف محصولات، factory_id ندارند.
 */
class TruckTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $factory = $this->factory($request);

        $trucks = Truck::selectRaw('truck_type_id, count(*) as total')
            ->whereNotNull('truck_type_id')
            ->groupBy('truck_type_id')
            ->pluck('total', 'truck_type_id');

        return Inertia::render('Staff/Catalog/TruckTypes', [
            'truckTypes' => TruckType::orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (TruckType $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'code' => $t->code,
                    'capacity_tons' => Digits::trimDecimal($t->capacity_tons),
                    'loading_minutes' => $t->loading_minutes,
                    'grace_minutes' => $t->grace_minutes,
                    'sort_order' => $t->sort_order,
                    'is_active' => $t->is_active,
                    'trucks_count' => (int) ($trucks[$t->id] ?? 0),
                ]),
            'defaults' => [
                'loading_minutes' => (int) $factory->avg_loading_minutes,
                'grace_minutes' => (int) $factory->no_show_grace_minutes,
            ],
        ]);
    }

    public function store(StoreTruckTypeRequest $request): RedirectResponse
    {
        $type = TruckType::create($request->validated());

        $this->audit->log(
            action: 'CREATE_TRUCK_TYPE',
            entity: $type,
            newValues: $request->validated(),
            request: $request,
        );

        return back()->with('success', "نوع کامیون «{$type->name}» اضافه شد.");
    }

    public function update(UpdateTruckTypeRequest $request, TruckType $truckType): RedirectResponse
    {
        $before = $truckType->only(array_keys($request->validated()));

        $truckType->update($request->validated());

        $this->audit->log(
            action: 'UPDATE_TRUCK_TYPE',
            entity: $truckType,
            oldValues: $before,
            newValues: $request->validated(),
            request: $request,
        );

        return back()->with('success', "نوع کامیون «{$truckType->name}» ذخیره شد.");
    }

    public function destroy(Request $request, TruckType $truckType): RedirectResponse
    {
        $this->authorizeManage($request);

        // کلید خارجی nullOnDelete است: حذف، نوع کامیونِ ناوگان ثبت‌شده را
        // بی‌سروصدا خالی می‌کند. غیرفعال کردن، گزینه‌ی درست است.
        if (Truck::where('truck_type_id', $truckType->id)->exists()) {
            return back()->with(
                'error',
                "«{$truckType->name}» به کامیون‌های ثبت‌شده وصل است و حذف نمی‌شود؛ آن را غیرفعال کنید.",
            );
        }

        $name = $truckType->name;

        $this->audit->log(
            action: 'DELETE_TRUCK_TYPE',
            entity: $truckType,
            oldValues: $truckType->only(['name', 'code', 'capacity_tons', 'loading_minutes', 'grace_minutes']),
            request: $request,
        );

        $truckType->delete();

        return back()->with('success', "نوع کامیون «{$name}» حذف شد.");
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless(
            $request->user()?->can(Permissions::PRODUCTS_MANAGE),
            403,
            'برای مدیریت انواع کامیون دسترسی ندارید.',
        );
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
