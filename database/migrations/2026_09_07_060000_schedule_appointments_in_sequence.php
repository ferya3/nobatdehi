<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
     * دو تغییر لازم دارد:
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
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('line_no')->default(1)->after('slot_id');
        });

        // نوبت‌های موجود همه روی لاین ۱ می‌نشینند
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('slot_id')->nullable()->change();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unique(['factory_id', 'date', 'line_no', 'start_time'], 'appointments_line_slot_unique');
            $table->index(['factory_id', 'date', 'line_no']);
        });
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
