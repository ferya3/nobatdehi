<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * کدام خواندنِ باسکول، این وزن را ساخت.
     *
     * tare_source و gross_source از قبل «device» یا «manual» را نگه می‌داشتند،
     * ولی «device» فقط یک ادعای اپراتور بود: عدد را خودش تایپ می‌کرد و در
     * فرم می‌گفت از باسکول آمده. با این ستون، ادعا به ارجاع تبدیل می‌شود —
     * یا ردیفی در scale_readings هست یا نیست.
     *
     * manual_reason برای وقتی است که باسکول واقعاً خراب است. مثل استثنای
     * گیت: راهِ دستی بسته نمی‌شود، ولی بی‌سروصدا هم نمی‌ماند.
     */
    public function up(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->foreignId('tare_reading_id')->nullable()->after('tare_source')
                ->constrained('scale_readings')->nullOnDelete();
            $table->string('tare_manual_reason')->nullable()->after('tare_reading_id');

            $table->foreignId('gross_reading_id')->nullable()->after('gross_source')
                ->constrained('scale_readings')->nullOnDelete();
            $table->string('gross_manual_reason')->nullable()->after('gross_reading_id');
        });
    }

    public function down(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tare_reading_id');
            $table->dropConstrainedForeignId('gross_reading_id');
            $table->dropColumn(['tare_manual_reason', 'gross_manual_reason']);
        });
    }
};
