<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * برچیدنِ اعلانِ درون‌برنامه‌ای.
 *
 * قابلیت ساخته شد، روی سرور رفت، و کار نکرد آن‌طور که باید. برداشته شد تا
 * از نو و با طرحِ دیگری نوشته شود.
 *
 * فایل‌های مهاجرتِ اصلی با revert از مخزن رفتند، ولی سروری که آن‌ها را
 * اجرا کرده بود همچنان جدول و ستون را دارد. این مهاجرت همان‌ها را
 * برمی‌دارد — و روی نصب تازه که هیچ‌وقت ساخته نشدند، بی‌صدا رد می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('driver_notifications');

        if (Schema::hasColumn('drivers', 'app_last_seen_at')) {
            Schema::table('drivers', function (Blueprint $table) {
                $table->dropColumn('app_last_seen_at');
            });
        }
    }

    /**
     * برگشتی در کار نیست.
     *
     * ساختنِ دوباره‌ی جدولِ خالی چیزی را برنمی‌گرداند: داده‌اش رفته و کدی
     * هم که ازش استفاده کند دیگر وجود ندارد. طرحِ بعدی، مهاجرتِ خودش را
     * می‌آورد.
     */
    public function down(): void
    {
        // عمداً خالی
    }
};
