<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Roles;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $factory = Factory::where('slug', 'main')->firstOrFail();

        $users = [
            ['name' => 'مدیر سامانه', 'email' => 'admin@example.test', 'mobile' => '09120000001', 'role' => Roles::SUPER_ADMIN],
            ['name' => 'مدیر کارخانه', 'email' => 'manager@example.test', 'mobile' => '09120000002', 'role' => Roles::FACTORY_MANAGER],
            ['name' => 'اپراتور صف', 'email' => 'operator@example.test', 'mobile' => '09120000003', 'role' => Roles::OPERATOR],
            ['name' => 'نگهبانی', 'email' => 'gate@example.test', 'mobile' => '09120000004', 'role' => Roles::GATE],
            ['name' => 'باسکول', 'email' => 'scale@example.test', 'mobile' => '09120000005', 'role' => Roles::WEIGHBRIDGE],
            ['name' => 'انبار', 'email' => 'warehouse@example.test', 'mobile' => '09120000006', 'role' => Roles::WAREHOUSE],
            ['name' => 'مدیرعامل', 'email' => 'ceo@example.test', 'mobile' => '09120000007', 'role' => Roles::CEO],
        ];

        foreach ($users as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'factory_id' => $factory->id,
                    'name' => $data['name'],
                    'mobile' => $data['mobile'],
                    'password' => 'password',
                    'is_active' => true,
                ],
            );

            $user->syncRoles([$data['role']]);
        }
    }
}
