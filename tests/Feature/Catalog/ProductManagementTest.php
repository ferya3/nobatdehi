<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Models\Factory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class ProductManagementTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
    }

    private function manager(): User
    {
        $user = User::create([
            'name' => 'مدیر کارخانه',
            'email' => 'manager@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName(Roles::FACTORY_MANAGER));

        return $user->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'سیمان تیپ ۲',
            'code' => 'CEM2',
            'description' => null,
            'load_tons' => '28.5',
            'loading_minutes' => 35,
            'sort_order' => 9,
            'priority' => 0,
            'is_active' => true,
        ], $overrides);
    }

    #[Test]
    public function test_a_manager_can_add_a_product(): void
    {
        $this->actingAs($this->manager())
            ->post(route('staff.products.store'), $this->payload())
            ->assertRedirect();

        $product = Product::where('code', 'CEM2')->firstOrFail();

        $this->assertSame('سیمان تیپ ۲', $product->name);
        $this->assertSame(35, $product->loading_minutes);
        $this->assertSame($this->factory->id, $product->factory_id);
    }

    #[Test]
    public function test_renaming_a_product_is_exactly_what_the_panel_is_for(): void
    {
        $product = Product::where('factory_id', $this->factory->id)->firstOrFail();

        $this->actingAs($this->manager())
            ->put(route('staff.products.update', $product), $this->payload([
                'name' => 'سیمان پرتلند',
                'code' => $product->code,
            ]))
            ->assertRedirect();

        $this->assertSame('سیمان پرتلند', $product->refresh()->name);
    }

    #[Test]
    public function test_a_duplicate_code_inside_the_same_factory_is_rejected(): void
    {
        $existing = Product::where('factory_id', $this->factory->id)->firstOrFail();

        $this->actingAs($this->manager())
            ->post(route('staff.products.store'), $this->payload(['code' => $existing->code]))
            ->assertSessionHasErrors('code');
    }

    #[Test]
    public function test_a_product_used_by_an_appointment_is_not_deleted(): void
    {
        $product = Product::where('factory_id', $this->factory->id)->firstOrFail();

        app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '11'),
            $this->futureSlot($this->factory),
            $product,
        ));

        $this->actingAs($this->manager())
            ->delete(route('staff.products.destroy', $product))
            ->assertSessionHas('error');

        $this->assertModelExists($product);
    }

    #[Test]
    public function test_an_unused_product_is_deleted(): void
    {
        $product = Product::create([
            'factory_id' => $this->factory->id,
            'name' => 'محصول آزمایشی',
            'code' => 'TMP',
            'sort_order' => 50,
            'is_active' => true,
        ]);

        $this->actingAs($this->manager())
            ->delete(route('staff.products.destroy', $product))
            ->assertSessionHas('success');

        $this->assertModelMissing($product);
    }

    #[Test]
    public function test_an_operator_cannot_touch_the_catalog(): void
    {
        $operator = User::create([
            'name' => 'اپراتور',
            'email' => 'op@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);
        $operator->assignRole(Role::findByName(Roles::OPERATOR));

        $this->actingAs($operator->fresh())
            ->get(route('staff.products.index'))
            ->assertForbidden();

        $this->actingAs($operator->fresh())
            ->post(route('staff.products.store'), $this->payload())
            ->assertForbidden();
    }

    #[Test]
    public function test_a_deactivated_product_disappears_from_the_booking_form(): void
    {
        $product = Product::where('factory_id', $this->factory->id)->firstOrFail();

        $this->actingAs($this->manager())
            ->put(route('staff.products.update', $product), $this->payload([
                'name' => $product->name,
                'code' => $product->code,
                'is_active' => false,
            ]))
            ->assertRedirect();

        $driver = $this->makeDriver('09123456789')->refresh();

        $this->actingAs($driver, 'driver')
            ->get(route('driver.booking.create'))
            ->assertInertia(fn ($page) => $page->where(
                'products',
                fn ($products) => collect($products)->every(fn ($p) => $p['id'] !== $product->id),
            ));
    }
}
