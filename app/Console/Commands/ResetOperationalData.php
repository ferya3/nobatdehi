<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * صفر کردنِ کارِ انجام‌شده، بدون دست زدن به پیکربندی.
 *
 * بعد از یک دوره‌ی تست، کارخانه می‌خواهد نوبت‌ها و توزین‌های آزمایشی برود
 * ولی تنظیمات پیامک، توکن دستگاه‌ها، ساعات کاری، محصولات و کاربران پنل سر
 * جایشان بمانند. پاک کردنِ دستیِ جدول‌ها با psql همان کاری است که یک شب
 * خسته، جدول اشتباهی را می‌برد.
 *
 * مرزِ این فرمان یک جمله است: هرچه در جریانِ کار ساخته شده می‌رود، هرچه
 * کسی یک بار تنظیم کرده می‌ماند.
 */
class ResetOperationalData extends Command
{
    protected $signature = 'data:reset
        {--force : بدون پرسیدن}
        {--catalog : محصولات، انواع کامیون و لاین‌های بارگیری هم پاک شوند}
        {--dry-run : فقط بگو چه چیزی پاک می‌شود}';

    protected $description = 'پاک کردن نوبت‌ها و داده‌های عملیاتی، با نگه‌داشتن تنظیمات و کاربران';

    /**
     * آنچه در جریانِ کار ساخته شده — به ترتیبی که وابستگی‌ها اجازه می‌دهند.
     *
     * TRUNCATE ... CASCADE ترتیب را خودش حل می‌کند، ولی فهرست صریح یعنی
     * جدولی که فردا اضافه می‌شود، بی‌سروصدا در این پاک‌سازی نمی‌افتد.
     *
     * @var array<int, string>
     */
    private const OPERATIONAL = [
        'appointment_transitions',
        'loading_records',
        'appointments',
        'appointment_slots',
        'plate_readings',
        'scale_readings',
        'driver_truck',
        'trucks',
        'drivers',
        'sms_messages',
        'otp_requests',
        'security_logs',
        'audit_logs',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    /** کاتالوگ: یک بار تعریف می‌شود، ولی گاهی باید از نو تعریف شود */
    private const CATALOG = [
        'loading_point_product',
        'loading_points',
        'products',
        'truck_types',
    ];

    /**
     * آنچه هرگز پاک نمی‌شود، و دلیلش.
     *
     * فهرست برای خواندنِ آدم است، نه برای کد: کسی که این فرمان را اجرا
     * می‌کند باید پیش از زدن Enter بداند چه چیزی می‌ماند.
     *
     * @var array<string, string>
     */
    private const KEPT = [
        'settings' => 'تنظیمات پیامک، توکن بارکدخوان و باسکول، رواداری وزن',
        'factories' => 'مشخصات کارخانه و قواعد نوبت‌دهی',
        'working_hours' => 'ساعات کاری هفته',
        'calendar_exceptions' => 'تعطیلات ثبت‌شده',
        'users' => 'کاربران پنل با همان رمزها',
        'sms_templates' => 'متن پیامک‌ها',
    ];

    public function handle(): int
    {
        $tables = array_values(array_filter(
            $this->option('catalog') ? array_merge(self::OPERATIONAL, self::CATALOG) : self::OPERATIONAL,
            fn (string $table) => Schema::hasTable($table),
        ));

        $rows = [];
        $total = 0;

        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $total += $count;

            if ($count > 0) {
                $rows[] = [$table, number_format($count)];
            }
        }

        $this->newLine();
        $this->line('  <options=bold>پاک می‌شود:</>');

        if ($rows === []) {
            $this->line('  <fg=gray>چیزی برای پاک کردن نیست — همه‌ی جدول‌ها خالی‌اند.</>');
        } else {
            $this->table(['جدول', 'ردیف'], $rows);
        }

        $this->line('  <options=bold>دست‌نخورده می‌ماند:</>');

        foreach (self::KEPT as $table => $why) {
            $this->line("  <fg=green>•</> <options=bold>{$table}</> — {$why}");
        }

        if (! $this->option('catalog')) {
            $this->line('  <fg=green>•</> <options=bold>products، truck_types، loading_points</> — کاتالوگ (با --catalog پاک می‌شود)');
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment('  اجرای آزمایشی بود؛ چیزی پاک نشد.');

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('  کاری برای انجام نبود.');

            return self::SUCCESS;
        }

        $this->line('  مجموعاً <options=bold>'.number_format($total).'</> ردیف.');
        $this->newLine();

        // پیش‌فرضِ «نه»: این فرمان اگر بی‌حضورِ آدم اجرا شود — مثلاً وسط یک
        // اسکریپت — باید هیچ کاری نکند، نه اینکه سکوت را رضایت بگیرد.
        if (! $this->option('force') && ! $this->confirm('پاک شود؟ این کار برگشت‌پذیر نیست.', false)) {
            $this->comment('  لغو شد.');

            return self::SUCCESS;
        }

        // یک تراکنش: یا همه‌ی جدول‌ها پاک می‌شوند یا هیچ‌کدام. نصفه‌کاره
        // ماندنش یعنی نوبت‌هایی که راننده‌شان دیگر وجود ندارد.
        DB::transaction(function () use ($tables) {
            $quoted = implode(', ', array_map(fn (string $t) => '"'.$t.'"', $tables));

            // RESTART IDENTITY تا شماره‌ها از یک شروع شوند، CASCADE برای
            // جدولی که شاید فردا به اینها کلید خارجی بدهد.
            DB::statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");
        });

        $this->info('  داده‌های عملیاتی پاک شد.');

        // کش تنظیمات و صفحه‌ها به داده‌ی رفته اشاره می‌کنند
        $this->call('cache:clear');

        // بدون اسلات، اولین راننده‌ای که نوبت می‌خواهد جواب نمی‌گیرد
        $this->call('slots:generate');

        return self::SUCCESS;
    }
}
