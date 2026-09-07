<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Domain\Access\Roles;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * هشدار در README کافی نبود: رمز پیش‌فرض تا وقتی سامانه جلویش را نگیرد
 * همان رمز می‌ماند.
 */
final class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
        $this->seed(UserSeeder::class);
    }

    private function manager(): User
    {
        return User::role(Roles::FACTORY_MANAGER)->firstOrFail();
    }

    #[Test]
    public function test_seeded_users_do_not_share_a_known_password(): void
    {
        foreach (User::all() as $user) {
            $this->assertFalse(
                Hash::check('password', $user->password),
                "کاربر {$user->email} هنوز رمز پیش‌فرضِ قابل حدس دارد.",
            );

            $this->assertTrue($user->must_change_password);
        }
    }

    #[Test]
    public function test_the_whole_panel_is_closed_until_the_password_changes(): void
    {
        $manager = $this->manager();

        foreach (['staff.home', 'staff.queue.index', 'staff.settings.edit', 'staff.reports'] as $route) {
            $this->actingAs($manager)
                ->get(route($route))
                ->assertRedirect(route('staff.password.edit'));
        }
    }

    #[Test]
    public function test_the_change_password_page_itself_stays_reachable(): void
    {
        $this->actingAs($this->manager())
            ->get(route('staff.password.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Staff/Password')->where('forced', true));
    }

    #[Test]
    public function test_changing_the_password_opens_the_panel(): void
    {
        $manager = $this->manager();
        $manager->forceFill(['password' => Hash::make('initial-secret-99')])->save();

        $this->actingAs($manager)
            ->put(route('staff.password.update'), [
                'current_password' => 'initial-secret-99',
                'password' => 'a-much-better-1',
                'password_confirmation' => 'a-much-better-1',
            ])
            ->assertRedirect(route('staff.home'));

        $manager->refresh();

        $this->assertFalse($manager->must_change_password);
        $this->assertNotNull($manager->password_changed_at);
        $this->assertTrue(Hash::check('a-much-better-1', $manager->password));

        // staff.home هر نقش را به صفحه‌ی خودش می‌فرستد؛ مهم این است که
        // دیگر به صفحه‌ی تغییر رمز برنگردد
        $this->actingAs($manager)
            ->get(route('staff.queue.index'))
            ->assertOk();
    }

    #[Test]
    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $manager = $this->manager();
        $manager->forceFill(['password' => Hash::make('initial-secret-99')])->save();

        $this->actingAs($manager)
            ->put(route('staff.password.update'), [
                'current_password' => 'not-it',
                'password' => 'a-much-better-1',
                'password_confirmation' => 'a-much-better-1',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($manager->refresh()->must_change_password);
    }

    #[Test]
    public function test_reusing_the_same_password_is_not_a_change(): void
    {
        $manager = $this->manager();
        $manager->forceFill(['password' => Hash::make('initial-secret-99')])->save();

        $this->actingAs($manager)
            ->put(route('staff.password.update'), [
                'current_password' => 'initial-secret-99',
                'password' => 'initial-secret-99',
                'password_confirmation' => 'initial-secret-99',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($manager->refresh()->must_change_password);
    }

    #[Test]
    public function test_a_short_password_is_refused(): void
    {
        $manager = $this->manager();
        $manager->forceFill(['password' => Hash::make('initial-secret-99')])->save();

        $this->actingAs($manager)
            ->put(route('staff.password.update'), [
                'current_password' => 'initial-secret-99',
                'password' => 'short1',
                'password_confirmation' => 'short1',
            ])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function test_a_driver_is_untouched_by_the_staff_rule(): void
    {
        $driver = $this->makeDriver('09123456789')->refresh();

        $this->actingAs($driver, 'driver')->get(route('driver.home'))->assertOk();
    }
}
