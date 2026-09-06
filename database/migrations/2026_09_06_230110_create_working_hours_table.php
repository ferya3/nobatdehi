<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('working_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();

            // 0 = شنبه ... 6 = جمعه (تقویم ایران)
            $table->unsignedTinyInteger('weekday');
            $table->boolean('is_open')->default(true);
            $table->time('opens_at')->default('07:00');
            $table->time('closes_at')->default('18:00');
            $table->unsignedSmallInteger('capacity_per_slot')->default(5);

            $table->timestamps();

            $table->unique(['factory_id', 'weekday']);
        });

        // استثناهای تقویم: تعطیلی رسمی یا ظرفیت ویژه یک روز خاص
        Schema::create('calendar_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->unsignedSmallInteger('capacity_per_slot')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['factory_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_exceptions');
        Schema::dropIfExists('working_hours');
    }
};
