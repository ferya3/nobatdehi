<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * اولویت در صف.
     *
     * اولویت عمداً فقط *داخل یک ساعت* اثر دارد، نه بین ساعت‌ها: اولویتی که
     * بتواند نوبت ساعت ۱۴ را جلوی نوبت ساعت ۸ بیندازد، کل نوبت‌دهی را
     * بی‌معنی می‌کند. مقدار بزرگ‌تر یعنی زودتر.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')->default(0)->after('sort_order');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')->default(0)->after('product_id');
            $table->string('priority_reason')->nullable()->after('priority');
        });

        // ترتیب صف: ساعت، بعد اولویت، بعد شماره‌ی نوبت
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['factory_id', 'date', 'start_time', 'priority'], 'appointments_queue_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_queue_order_index');
            $table->dropColumn(['priority', 'priority_reason']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
