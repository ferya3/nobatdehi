<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff\Catalog;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\Catalog\StoreProductRequest;
use App\Http\Requests\Staff\Catalog\UpdateProductRequest;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\Product;
use App\Support\Digits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $factory = $this->factory($request);

        // شمارش نوبت‌ها تعیین می‌کند کدام محصول قابل حذف است
        $used = Appointment::whereIn(
            'product_id',
            Product::where('factory_id', $factory->id)->select('id'),
        )
            ->selectRaw('product_id, count(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        return Inertia::render('Staff/Catalog/Products', [
            'products' => Product::where('factory_id', $factory->id)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'code' => $p->code,
                    'description' => $p->description,
                    'loading_minutes' => $p->loading_minutes,
                    'sort_order' => $p->sort_order,
                    'priority' => $p->priority,
                    'is_active' => $p->is_active,
                    'appointments_count' => (int) ($used[$p->id] ?? 0),
                ]),
            'defaultLoadingMinutes' => (int) $factory->avg_loading_minutes,
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $factory = $this->factory($request);

        $product = Product::create($request->validated() + ['factory_id' => $factory->id]);

        $this->audit->log(
            action: 'CREATE_PRODUCT',
            entity: $product,
            newValues: $request->validated(),
            request: $request,
        );

        return back()->with('success', "محصول «{$product->name}» اضافه شد.");
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $this->assertOwned($request, $product);

        $before = $product->only(array_keys($request->validated()));

        $product->update($request->validated());

        $this->audit->log(
            action: 'UPDATE_PRODUCT',
            entity: $product,
            oldValues: $before,
            newValues: $request->validated(),
            request: $request,
        );

        return back()->with('success', "محصول «{$product->name}» ذخیره شد.");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->assertOwned($request, $product);

        // نوبت‌های ثبت‌شده به محصول ارجاع دارند؛ حذفشان یعنی از بین بردن
        // تاریخچه. غیرفعال کردن همان کار را بدون این هزینه انجام می‌دهد.
        if (Appointment::where('product_id', $product->id)->exists()) {
            return back()->with(
                'error',
                "«{$product->name}» در نوبت‌های ثبت‌شده به کار رفته و حذف نمی‌شود؛ آن را غیرفعال کنید.",
            );
        }

        $name = $product->name;

        $this->audit->log(
            action: 'DELETE_PRODUCT',
            entity: $product,
            oldValues: $product->only(['name', 'code', 'loading_minutes', 'priority', 'is_active']),
            request: $request,
        );

        $product->loadingPoints()->detach();
        $product->delete();

        return back()->with('success', "محصول «{$name}» حذف شد.");
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::PRODUCTS_MANAGE), 403, 'برای مدیریت محصولات دسترسی ندارید.');
    }

    /** محصول کارخانه‌ی دیگر، از مسیر این کارخانه قابل ویرایش نیست */
    private function assertOwned(Request $request, Product $product): void
    {
        abort_unless($product->factory_id === $this->factory($request)->id, 404);
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
