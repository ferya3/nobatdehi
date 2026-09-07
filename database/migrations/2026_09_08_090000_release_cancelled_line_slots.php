<?php

use App\Domain\Appointment\Enums\AppointmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * بازه‌ی نوبتِ لغوشده باید دوباره قابل استفاده باشد.
     *
     * یکتاییِ (کارخانه، روز، لاین، ساعت شروع) لغوشده‌ها را هم می‌شمرد. نیت
     * درست بود — «دو کامیون هم‌زمان روی یک لاین» باید در خود دیتابیس ناممکن
     * باشد — ولی نتیجه‌اش این شد که هر لغو، یک بازه از آن روز را برای همیشه
     * می‌سوزاند. کارخانه‌ای که صبح چند نوبت لغو شده باشد، اولین نوبتِ بعدی را
     * ساعت‌ها دیرتر از ساعت باز شدن اعلام می‌کرد.
     *
     * ایندکس جزئی نام تازه‌ای دارد تا با همان قید قدیمی اشتباه گرفته نشود، و
     * همان تضمین را نگه می‌دارد و فقط دامنه‌اش را درست می‌کند:
     * دو نوبتِ *زنده* هرگز روی یک لاین و یک ساعت نمی‌نشینند، ولی نوبتِ
     * لغوشده جای کسی را نمی‌گیرد.
     *
     * تکمیل‌شده همچنان جا را نگه می‌دارد: آن کامیون واقعاً آن بازه را گرفت.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // ایندکس جزئی فقط روی PostgreSQL؛ این پروژه هم چیز دیگری ندارد
        }

        // در مهاجرت قبلی به‌صورت constraint ساخته شده بود و نه ایندکس ساده،
        // پس اول باید constraint برداشته شود وگرنه Postgres اجازه‌ی drop نمی‌دهد
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_line_slot_unique');
        DB::statement('DROP INDEX IF EXISTS appointments_line_slot_unique');

        // اگر اجرای قبلی نیمه‌کاره مانده باشد، ایندکس تازه از قبل هست و
        // CREATE دوباره کل مهاجرت را می‌خواباند
        DB::statement('DROP INDEX IF EXISTS appointments_live_line_slot_unique');

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX appointments_live_line_slot_unique
                ON appointments (factory_id, date, line_no, start_time)
                WHERE status NOT IN ({$this->releasedList()})
        SQL);
    }

    /**
     * برگشت باید قیدِ قبلی را دقیقاً به همان شکلِ قبلی بسازد.
     *
     * مهاجرت ۰۶۰۰۰۰ در down خودش dropUnique می‌زند و آن روی PostgreSQL به
     * «ALTER TABLE ... DROP CONSTRAINT» ترجمه می‌شود. اگر اینجا به‌جای
     * constraint یک ایندکس ساده بسازیم، rollback زنجیره‌ای در همان‌جا
     * می‌شکند و دیتابیس نیمه‌کاره می‌ماند.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS appointments_live_line_slot_unique');

        // قیدِ قبلی لغوشده‌ها را هم می‌شمرد؛ اگر بعد از این مهاجرت بازه‌ای
        // دوباره استفاده شده باشد، برگشت بدون از دست رفتن داده ممکن نیست.
        if ($this->hasDuplicateSlots()) {
            throw new RuntimeException(
                'برگشت ممکن نیست: بازه‌هایی هستند که بعد از لغو دوباره به نوبت تازه داده شده‌اند. '
                .'قید قدیمی آن‌ها را تکراری می‌بیند.',
            );
        }

        DB::statement(
            'ALTER TABLE appointments
                ADD CONSTRAINT appointments_line_slot_unique
                UNIQUE (factory_id, date, line_no, start_time)'
        );
    }

    /** «'CANCELLED', 'NO_SHOW', ...» — از روی همان enum که کد با آن کار می‌کند */
    private function releasedList(): string
    {
        return collect(AppointmentStatus::releasedValues())
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');
    }

    private function hasDuplicateSlots(): bool
    {
        return DB::table('appointments')
            ->selectRaw('1')
            ->groupBy('factory_id', 'date', 'line_no', 'start_time')
            ->havingRaw('count(*) > 1')
            ->exists();
    }
};
