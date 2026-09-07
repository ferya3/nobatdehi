<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Access\Roles;
use App\Models\Factory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

        $created = [];

        foreach ($users as $data) {
            $user = User::firstOrNew(['email' => $data['email']]);

            /*
             * رمز فقط موقع ساخت نوشته می‌شود.
             *
             * این seeder در هر به‌روزرسانی دوباره اجرا می‌شود؛ اگر رمز را هر
             * بار بازنویسی کند، رمزی که مدیر خودش گذاشته پاک می‌شود و
             * سامانه بی‌سروصدا به یک رمز ناشناخته برمی‌گردد.
             */
            if (! $user->exists) {
                $password = Str::password(16, symbols: false);

                $user->password = $password;
                $user->must_change_password = true;

                $created[$data['email']] = $password;
            }

            $user->fill([
                'factory_id' => $factory->id,
                'name' => $data['name'],
                'mobile' => $data['mobile'],
                'is_active' => true,
            ])->save();

            $user->syncRoles([$data['role']]);
        }

        $this->report($created);
    }

    /**
     * رمزهای تازه یک بار چاپ می‌شوند و تمام.
     *
     * @param  array<string, string>  $created
     */
    private function report(array $created): void
    {
        if ($created === []) {
            return;
        }

        $this->command?->newLine();
        $this->command?->warn('رمز اولیه‌ی کاربران — همین حالا یادداشت کنید، دوباره نشان داده نمی‌شود:');

        foreach ($created as $email => $password) {
            $this->command?->line("  {$email}  {$password}");
        }

        $this->command?->newLine();
        $this->command?->info('هر کاربر در اولین ورود مجبور به تغییر رمز می‌شود.');
    }
}
