<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Permissions;
use App\Domain\Access\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::keys() as $key) {
            Permission::findOrCreate($key, 'web');
        }

        foreach (Roles::matrix() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
