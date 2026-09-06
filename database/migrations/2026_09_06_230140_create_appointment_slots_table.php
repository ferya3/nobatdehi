<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('reserved_count')->default(0);
            $table->boolean('is_blocked')->default(false);   // بستن دستی یک اسلات
            $table->string('blocked_reason')->nullable();
            $table->timestamps();

            $table->unique(['factory_id', 'date', 'start_time']);
            $table->index(['factory_id', 'date']);
        });

        // آخرین خط دفاع: حتی اگر باگی از قفل عبور کرد، دیتابیس ظرفیت منفی
        // یا بیش از حد را نمی‌پذیرد.
        DB::statement('
            ALTER TABLE appointment_slots
            ADD CONSTRAINT appointment_slots_capacity_check
            CHECK (reserved_count <= capacity)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_slots');
    }
};
