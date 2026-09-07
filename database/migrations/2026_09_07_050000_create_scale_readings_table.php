<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * هر عددی که نشان‌دهنده‌ی باسکول فرستاده.
     *
     * وقتی سامانه جای نرم‌افزار ویندوزی باسکول را می‌گیرد، این جدول همان
     * چیزی است که آن نرم‌افزار داشت و ما نداشتیم: ردِ خام هر توزین، جدا از
     * اینکه به کدام حواله چسبیده باشد.
     *
     * عمداً روی loading_records ستون اضافه نکردیم: باسکول کامیونِ بی‌نوبت،
     * توزینِ آزمایشی و توزینی که اپراتور نصفه رها کرده را هم می‌بیند. اگر
     * فقط توزین‌های موفق را نگه داریم، سابقه‌ای ساخته‌ایم که همیشه بی‌عیب است.
     *
     * is_stable همان پرچمی است که نشان‌دهنده می‌دهد: عقربه ایستاده یا هنوز
     * نوسان دارد. عددِ ناپایدار ثبت نمی‌شود ولی ذخیره می‌شود — که بعداً
     * بشود دید باسکول چقدر طول می‌کشد تا آرام بگیرد.
     */
    public function up(): void
    {
        Schema::create('scale_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            // نام باسکولی که این عدد از آن آمده — «ورودی»، «خروجی»، ...
            $table->string('scale_name', 64);
            $table->string('device_name', 64)->nullable();

            $table->decimal('weight_kg', 10, 2);
            $table->string('unit', 8)->default('kg');
            $table->boolean('is_stable')->default(false);

            // رشته‌ی خام دستگاه، دست‌نخورده — برای وقتی که پارسر اشتباه کند
            $table->string('raw_frame', 128)->nullable();

            $table->timestamp('read_at');
            $table->timestamps();

            $table->index(['factory_id', 'scale_name', 'read_at']);
            $table->index('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scale_readings');
    }
};
