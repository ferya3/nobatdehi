<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->default('Asia/Tehran');

            // تنظیمات نوبت‌دهی — از پنل مدیریت قابل تغییر، بدون Deploy
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('daily_capacity')->default(80);
            $table->unsignedSmallInteger('loading_lines')->default(3);
            $table->unsignedSmallInteger('avg_loading_minutes')->default(25);

            // چند روز جلوتر راننده می‌تواند نوبت بگیرد
            $table->unsignedSmallInteger('booking_horizon_days')->default(7);
            // حداقل فاصله از الان تا نوبت (دقیقه)
            $table->unsignedSmallInteger('booking_lead_minutes')->default(60);

            // محدودیت‌های ضد سوءاستفاده — قابل تنظیم
            $table->unsignedSmallInteger('max_active_per_mobile')->default(2);
            $table->unsignedSmallInteger('max_active_per_plate')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factories');
    }
};
