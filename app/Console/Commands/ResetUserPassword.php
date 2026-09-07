<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * رمز یک کاربر پنل را از روی سرور عوض می‌کند.
 *
 * رمزهای اولیه تصادفی‌اند و فقط یک بار — موقع نصب — چاپ می‌شوند. اگر آن
 * خروجی گم شود، بدون این فرمان راه ورودی به پنل نمی‌ماند و تنها چاره‌ی
 * باقی‌مانده دست بردن مستقیم در دیتابیس است.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'user:password
                            {email? : ایمیل کاربر — اگر ندهید فهرست کاربران نشان داده می‌شود}
                            {--password= : رمز دلخواه؛ خالی بگذارید تا رمز تصادفی ساخته شود}
                            {--keep-flag : اجبار به تغییر رمز در ورود بعد را دست نزن}';

    protected $description = 'ساخت رمز تازه برای یک کاربر پنل';

    public function handle(): int
    {
        $email = $this->argument('email');

        if ($email === null) {
            $this->table(
                ['ایمیل', 'نام', 'نقش', 'فعال'],
                User::with('roles')->orderBy('id')->get()->map(fn (User $user) => [
                    $user->email,
                    $user->name,
                    $user->roles->pluck('name')->implode('، '),
                    $user->is_active ? 'بله' : 'خیر',
                ])->all(),
            );

            $this->newLine();
            $this->line('  php artisan user:password <email>');

            return self::SUCCESS;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("کاربری با ایمیل {$email} پیدا نشد.");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));

        if (mb_strlen($password) < 12) {
            $this->error('رمز باید دست‌کم ۱۲ نویسه باشد.');

            return self::FAILURE;
        }

        $user->password = $password;

        // کاربر باید در همان ورودِ بعد رمز را عوض کند؛ رمزی که از ترمینال
        // رد شده و در تاریخچه‌ی شل مانده، رمزِ ماندگاری نیست.
        if (! $this->option('keep-flag')) {
            $user->must_change_password = true;
        }

        $user->is_active = true;
        $user->save();

        $this->newLine();
        $this->warn('رمز تازه — همین حالا یادداشت کنید، دوباره نشان داده نمی‌شود:');
        $this->line("  {$user->email}  {$password}");
        $this->newLine();

        if (! $this->option('keep-flag')) {
            $this->info('در اولین ورود، تغییر رمز اجباری است.');
        }

        return self::SUCCESS;
    }
}
