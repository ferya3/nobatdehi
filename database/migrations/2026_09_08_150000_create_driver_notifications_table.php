<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اعلان‌های راننده.
 *
 * پیامک هزینه دارد و برای هر خبری صرف نمی‌کند. این جدول کانال دوم است:
 * اعلان داخل برنامه‌ی اندروید، رایگان و بدون محدودیت طول.
 *
 * هر ردیف برای یک راننده است، حتی وقتی مدیر یک پیام را به صد نفر می‌فرستد.
 * دلیلش این است که «رسید» و «خوانده شد» برای هر نفر جداگانه معنا دارد؛
 * یک ردیفِ مشترک با فهرست گیرنده، همان لحظه که بخواهی بدانی چه کسی ندید،
 * بی‌فایده می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();

            // فرستنده: کارمندی که دکمه را زد، یا null وقتی خودِ سامانه فرستاده
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('body');

            // مسیر داخل همان دامنه، مثل /queue/appointments/12 — با کلیک باز می‌شود
            $table->string('path')->nullable();

            // دسته‌بندی برای گروه‌بندی اعلان‌ها روی گوشی
            $table->string('kind', 32)->default('general');

            // چه زمانی برنامه آن را گرفت، و چه زمانی راننده بازش کرد
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // پرس‌وجوی داغِ برنامه: «اعلان‌های نرسیده‌ی این راننده»
            $table->index(['driver_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_notifications');
    }
};
