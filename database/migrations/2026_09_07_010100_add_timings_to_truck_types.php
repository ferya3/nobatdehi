<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * زمان‌بندی هر نوع کامیون.
     *
     * تریلی و خاور نه به یک اندازه بار می‌زنند و نه یک اندازه مهلت حضور
     * منطقی دارند؛ بدون این دو ستون، تخمین صف و آزادسازی ظرفیت مجبورند با
     * یک عدد متوسط برای همه کار کنند.
     */
    public function up(): void
    {
        Schema::table('truck_types', function (Blueprint $table) {
            // مدت معمول بارگیری این نوع کامیون؛ null یعنی «از محصول/کارخانه بگیر»
            $table->unsignedSmallInteger('loading_minutes')->nullable()->after('capacity_tons');

            // مهلت حضور بعد از شروع ساعت نوبت، پیش از ثبت خودکار عدم حضور
            $table->unsignedSmallInteger('grace_minutes')->nullable()->after('loading_minutes');
        });

        Schema::table('factories', function (Blueprint $table) {
            // پیش‌فرض کارخانه برای انواعی که مهلت اختصاصی ندارند
            $table->unsignedSmallInteger('no_show_grace_minutes')->default(60)->after('avg_loading_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('truck_types', function (Blueprint $table) {
            $table->dropColumn(['loading_minutes', 'grace_minutes']);
        });

        Schema::table('factories', function (Blueprint $table) {
            $table->dropColumn('no_show_grace_minutes');
        });
    }
};
