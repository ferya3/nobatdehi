<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * با چه دستگاهی ورود ثبت شد.
     *
     * gate_entry_method از قبل می‌گفت «با QR یا دستی». حالا که هم بارکدخوان
     * هست و هم پلاک‌خوان، همان ستون کافی نیست: در ممیزی فرق دارد که پلاک را
     * دوربین خوانده باشد یا نگهبان تیک زده باشد. دومی قابل تعارف است، اولی نه.
     *
     * gate_plate_reading_id به همان خواندنی اشاره می‌کند که راهبند را باز کرد —
     * یعنی عکسِ همان لحظه. خواندن‌های دیگرِ همین کامیون در plate_readings
     * می‌مانند ولی تصمیم‌گیر نبوده‌اند.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // camera = دوربین مرورگر، barcode = بارکدخوان سخت‌افزاری
            $table->string('gate_scan_source', 16)->nullable()->after('gate_entry_method');
            // anpr | station | guard
            $table->string('gate_plate_source', 16)->nullable()->after('gate_observed_plate');
            $table->foreignId('gate_plate_reading_id')->nullable()->after('gate_plate_source')
                ->constrained('plate_readings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gate_plate_reading_id');
            $table->dropColumn(['gate_scan_source', 'gate_plate_source']);
        });
    }
};
