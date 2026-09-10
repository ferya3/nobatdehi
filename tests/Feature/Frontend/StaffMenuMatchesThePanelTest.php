<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Domain\Access\Roles;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * منو باید همان چیزی را نشان بدهد که واقعاً باز می‌شود — نه بیشتر، نه کمتر.
 *
 * دو خرابیِ متفاوت که هر دو بی‌صدا هستند:
 *
 * لینکی که باز نمی‌شود — کاربر روی «باسکول» می‌زند و صفحه‌ی «دسترسی ندارید»
 * می‌گیرد. یعنی دسترسیِ منو با دسترسیِ کنترلر یکی نیست.
 *
 * صفحه‌ای که لینک ندارد — یک بار همین اتفاق افتاد: اپراتور هم dashboard.view
 * داشت هم queue.view، و منو «مدیریت صف» را فقط به کسی نشان می‌داد که داشبورد
 * نداشت. صفحه بود، دسترسی بود، ولی هیچ‌کس راهی به آن نمی‌دید.
 */
final class StaffMenuMatchesThePanelTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    /**
     * صفحه‌هایی که عمداً در منو نیستند، با دلیلشان.
     *
     * هر کدام از جای دیگری باز می‌شود؛ اگر روزی صفحه‌ای بی‌دلیل اینجا اضافه
     * شود، همان لحظه باید توضیحش نوشته شود.
     */
    private const REACHED_FROM_ELSEWHERE = [
        'staff.home' => 'خودش کاربر را به داشبورد یا صف می‌فرستد',
        'staff.login' => 'پیش از ورود',
        'staff.password.edit' => 'اجبارِ تغییر رمز، با middleware',
        'staff.queue.show' => 'از روی ردیف‌های صف باز می‌شود',
        'staff.reports.export' => 'دکمه‌ی خروجی داخل خود گزارش‌ها',
        'staff.gate.readings' => 'صدا زده می‌شود، نه دیده',
        'staff.gate.reading-image' => 'عکسِ داخل صفحه‌ی نگهبانی',
        'staff.weighbridge.readings' => 'صدا زده می‌شود، نه دیده',
    ];

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
    }

    /**
     * منوی StaffLayout، همان‌طور که در کد نوشته شده.
     *
     * @return array<int, array{label: string, name: string, permission: string}>
     */
    private function menu(): array
    {
        $code = (string) file_get_contents(resource_path('js/layouts/StaffLayout.vue'));

        preg_match_all(
            "/\{\s*label:\s*'([^']+)',\s*name:\s*'([^']+)',\s*pattern:\s*'[^']*',\s*permission:\s*'([^']+)'\s*\}/",
            $code,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'منوی StaffLayout خوانده نشد — شکل تعریفش عوض شده است.');

        return array_map(fn (array $m) => [
            'label' => $m[1],
            'name' => $m[2],
            'permission' => $m[3],
        ], $matches);
    }

    private function person(string $role): User
    {
        $user = User::create([
            'name' => 'کاربر '.$role,
            'email' => str_replace('-', '', $role).'@menu.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role, 'web'));

        return $user->fresh();
    }

    /** @return array<int, string> */
    private function roles(): array
    {
        return array_keys(Roles::matrix());
    }

    #[Test]
    public function every_menu_link_a_role_is_shown_actually_opens(): void
    {
        $broken = [];

        foreach ($this->roles() as $role) {
            $user = $this->person($role);

            foreach ($this->menu() as $item) {
                if (! $user->can($item['permission'])) {
                    continue;
                }

                $status = $this->actingAs($user, 'web')->get(route($item['name']))->getStatusCode();

                if ($status !== 200) {
                    $broken[] = "{$role}: «{$item['label']}» در منو هست ولی {$item['name']} پاسخ {$status} می‌دهد.";
                }
            }
        }

        $this->assertSame([], $broken, "لینکِ منو باز نمی‌شود:\n".implode("\n", $broken));
    }

    #[Test]
    public function every_panel_page_a_role_can_open_has_a_link_in_the_menu(): void
    {
        $inMenu = array_column($this->menu(), 'name');
        $orphans = [];

        $pages = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'staff.')
                && in_array('GET', $route->methods(), true)
                // صفحه‌های با پارامتر از جای دیگری باز می‌شوند
                && $route->parameterNames() === [])
            ->map(fn ($route) => (string) $route->getName())
            ->reject(fn (string $name) => isset(self::REACHED_FROM_ELSEWHERE[$name]))
            ->values();

        foreach ($this->roles() as $role) {
            $user = $this->person($role);

            foreach ($pages as $name) {
                if (in_array($name, $inMenu, true)) {
                    continue;
                }

                if ($this->actingAs($user, 'web')->get(route($name))->getStatusCode() === 200) {
                    $orphans[] = "{$role}: {$name} باز می‌شود ولی هیچ لینکی در منو ندارد.";
                }
            }
        }

        $this->assertSame([], $orphans, "صفحه‌ی بی‌لینک:\n".implode("\n", $orphans));
    }

    #[Test]
    public function a_role_that_can_do_the_work_of_a_station_can_reach_it(): void
    {
        // منو با یک دسترسی تصمیم می‌گیرد، ولی کنترلرِ لاین بارگیری دو دسترسی
        // را قبول می‌کند. کسی که فقط «پایان بارگیری» دارد، صفحه برایش باز
        // است ولی لینکی نمی‌بیند.
        $blind = [];

        foreach ($this->roles() as $role) {
            $user = $this->person($role);

            $canWork = $user->can('queue.start-loading') || $user->can('queue.complete-loading');
            $seesLink = collect($this->menu())
                ->contains(fn (array $item) => $item['name'] === 'staff.loading.index'
                    && $user->can($item['permission']));

            if ($canWork && ! $seesLink) {
                $blind[] = "{$role}: کارِ لاین بارگیری را می‌تواند انجام دهد ولی لینکش را نمی‌بیند.";
            }
        }

        $this->assertSame([], $blind, implode("\n", $blind));
    }
}
