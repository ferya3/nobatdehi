<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ترتیب را سامانه اعلام می‌کند، نه راننده.
     *
     * تا اینجا راننده یک اسلات ظرفیتی را انتخاب می‌کرد و ساعت نوبت از همان
     * اسلات کپی می‌شد. حالا ساعت را زمان‌بند حساب می‌کند: هر نوبت از جایی
     * شروع می‌شود که نوبت قبلیِ همان لاین تمام شده.
     *
     * سه تغییر لازم دارد:
     *
     * line_no — کدام لاین بارگیری. بدون آن، کارخانه‌ی سه‌لاینه باید سه برابر
     * کندتر نوبت می‌داد.
     *
     * slot_id nullable — نوبت دیگر به اسلات گره نمی‌خورد. جدول اسلات‌ها
     * می‌ماند چون ساعات کاری و تعطیلی روز را نگه می‌دارد، ولی واحد رزرو
     * نیست.
     *
     * و یکتاییِ (کارخانه، روز، لاین، ساعت شروع) جای لایه‌ی سومِ قبلی را
     * می‌گیرد: CHECK روی reserved_count دیگر معنی ندارد وقتی چیزی رزرو
     * نمی‌شود، ولی «دو کامیون هم‌زمان روی یک لاین» همچنان باید در خودِ
     * دیتابیس غیرممکن باشد، نه فقط در کد.
     *
     * لغوشده‌ها هم در این یکتایی حساب می‌شوند و این عمدی است: جای خالیِ یک
     * نوبتِ لغوشده دوباره پر نمی‌شود، وگرنه ساعتی که به راننده‌ی بعدی اعلام
     * شده جابه‌جا می‌شد.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('appointments', 'line_no')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->unsignedSmallInteger('line_no')->default(1)->after('slot_id');
            });
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->change();
        });

        $this->spreadExistingAppointmentsAcrossLines();

        Schema::table('appointments', function (Blueprint $table) {
            $table->unique(['factory_id', 'date', 'line_no', 'start_time'], 'appointments_line_slot_unique');
            $table->index(['factory_id', 'date', 'line_no']);
        });
    }

    /**
     * نوبت‌های موجود را روی لاین‌ها پخش می‌کند.
     *
     * نوبت‌های قدیمی همه در یک اسلات نیم‌ساعته می‌نشستند: پنج کامیون، همه
     * «ساعت ۱۱:۳۰». با پیش‌فرضِ line_no = 1، همه‌شان یک کلید یکسان می‌سازند
     * و ساختن ایندکس یکتا شکست می‌خورد — که دقیقاً روی سرور اتفاق افتاد.
     *
     * راه‌حل عمداً ساعت کسی را جابه‌جا نمی‌کند: به‌جایش شماره‌ی لاین را از
     * روی ترتیبِ نوبت داخل همان ساعت می‌سازد. نتیجه به‌ازای هر (کارخانه،
     * روز، ساعت) یکتاست، پس ایندکس بی‌دردسر ساخته می‌شود و هیچ راننده‌ای
     * ساعتِ عوض‌شده نمی‌بیند.
     *
     * شماره‌ی لاین ممکن است از تعداد واقعی لاین‌های کارخانه بیشتر شود. این
     * اشکالی ندارد: زمان‌بند لاین‌های خارج از محدوده را روی لاین ۱ حساب
     * می‌کند، که فقط باعث می‌شود محتاط‌تر باشد و نوبت تازه را کمی دیرتر
     * بگذارد — نه اینکه با ردیف قدیمی تصادم کند.
     */
    private function spreadExistingAppointmentsAcrossLines(): void
    {
        if (DB::table('appointments')->doesntExist()) {
            return;
        }

        // row_number روی SQLite هم هست؛ ولی این پروژه فقط PostgreSQL دارد
        DB::statement(<<<'SQL'
            UPDATE appointments AS a
            SET line_no = r.rn
            FROM (
                SELECT
                    id,
                    row_number() OVER (
                        PARTITION BY factory_id, date, start_time
                        ORDER BY number, id
                    ) AS rn
                FROM appointments
            ) AS r
            WHERE a.id = r.id
              AND a.line_no IS DISTINCT FROM r.rn
        SQL);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_line_slot_unique');
            $table->dropIndex(['factory_id', 'date', 'line_no']);
            $table->dropColumn('line_no');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable(false)->change();
        });
    }
};
