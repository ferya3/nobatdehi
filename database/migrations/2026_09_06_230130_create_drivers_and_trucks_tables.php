<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 11)->unique();       // 09xxxxxxxxx نرمال‌شده
            $table->string('name')->nullable();
            $table->string('national_code', 10)->nullable();
            $table->timestamp('mobile_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('trucks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('truck_type_id')->nullable()->constrained()->nullOnDelete();

            // پلاک ایرانی: ۱۲ ب ۳۴۵ ایران ۶۷
            $table->string('plate_two', 2);      // دو رقم سمت راست پلاک
            $table->string('plate_letter', 8);   // حرف
            $table->string('plate_three', 3);    // سه رقم
            $table->string('plate_iran', 2);     // کد ایران

            // کلید نرمال‌شده برای جستجو و یکتایی: 12-ب-345-67
            $table->string('plate_key', 32)->unique();

            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason')->nullable();
            $table->timestamps();
        });

        // یک راننده می‌تواند چند کامیون داشته باشد و یک کامیون چند راننده
        Schema::create('driver_truck', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('truck_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_used_at')->nullable();

            $table->unique(['driver_id', 'truck_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_truck');
        Schema::dropIfExists('trucks');
        Schema::dropIfExists('drivers');
    }
};
