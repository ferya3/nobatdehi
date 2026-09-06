<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * داشبورد Horizon صف‌ها و payload کارها را نشان می‌دهد — از جمله شماره
 * موبایل‌هایی که در job پیامک هستند. دسترسی به آن باید تنگ باشد.
 */
final class HorizonAccessTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
        $this->seed(\Database\Seeders\UserSeeder::class);
    }

    #[Test]
    public function only_the_super_admin_can_open_horizon(): void
    {
        $this->assertTrue(
            Gate::forUser(User::role(Roles::SUPER_ADMIN)->firstOrFail())->allows('viewHorizon'),
        );

        foreach ([Roles::FACTORY_MANAGER, Roles::OPERATOR, Roles::GATE, Roles::CEO] as $role) {
            $this->assertFalse(
                Gate::forUser(User::role($role)->firstOrFail())->allows('viewHorizon'),
                "نقش {$role} نباید به Horizon دسترسی داشته باشد.",
            );
        }
    }

    #[Test]
    public function a_guest_cannot_open_horizon(): void
    {
        $this->assertFalse(Gate::allows('viewHorizon'));
    }
}
