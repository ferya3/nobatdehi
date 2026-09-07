<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * باسکول اول و دوم.
     *
     * جدول از قبل سه ستون وزن داشت و هیچ‌کس در آن نمی‌نوشت. چیزی که کم بود،
     * همان چیزی است که «حذف دستکاری» را ممکن می‌کند: چه کسی، کِی، از چه
     * منبعی (دست یا خودِ باسکول)، و عکسِ لحظه‌ی توزین.
     *
     * net_weight_kg عمداً وارد نمی‌شود؛ سرور از پر منهای خالی حساب می‌کند.
     */
    public function up(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->timestamp('tare_weighed_at')->nullable()->after('empty_weight_kg');
            $table->string('tare_source', 16)->nullable()->after('tare_weighed_at');
            $table->string('tare_photo_path')->nullable()->after('tare_source');
            $table->foreignId('tare_by_user_id')->nullable()->after('tare_photo_path')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('gross_weighed_at')->nullable()->after('loaded_weight_kg');
            $table->string('gross_source', 16)->nullable()->after('gross_weighed_at');
            $table->string('gross_photo_path')->nullable()->after('gross_source');
            $table->foreignId('gross_by_user_id')->nullable()->after('gross_photo_path')
                ->constrained('users')->nullOnDelete();

            // اختلاف وزن خالص با تناژ حواله — مبنای «مغایرت» و «اضافه‌بار»
            $table->decimal('expected_net_kg', 10, 2)->nullable()->after('net_weight_kg');
            $table->decimal('variance_kg', 10, 2)->nullable()->after('expected_net_kg');
            $table->boolean('is_overload')->default(false)->after('variance_kg');

            $table->string('exit_permit_number')->nullable()->unique()->after('waybill_number');
            $table->timestamp('exit_permit_issued_at')->nullable()->after('exit_permit_number');
        });

        Schema::table('factories', function (Blueprint $table) {
            // مغایرت مجاز وزن خالص نسبت به تناژ حواله (درصد)
            $table->decimal('weight_tolerance_percent', 5, 2)->default(3.00)->after('no_show_grace_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tare_by_user_id');
            $table->dropConstrainedForeignId('gross_by_user_id');
            $table->dropColumn([
                'tare_weighed_at', 'tare_source', 'tare_photo_path',
                'gross_weighed_at', 'gross_source', 'gross_photo_path',
                'expected_net_kg', 'variance_kg', 'is_overload',
                'exit_permit_number', 'exit_permit_issued_at',
            ]);
        });

        Schema::table('factories', function (Blueprint $table) {
            $table->dropColumn('weight_tolerance_percent');
        });
    }
};
