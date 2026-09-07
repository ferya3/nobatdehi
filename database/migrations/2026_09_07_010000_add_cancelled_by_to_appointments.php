<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * چه کسی نوبت را بست.
     *
     * appointment_transitions هم این را دارد، ولی صف و گزارش نباید برای هر
     * سطر تاریخچه بخوانند؛ این دو ستون همان‌جایی است که cancel_reason هست.
     * نام را snapshot می‌گیریم تا حذف یا تغییر نام کاربر، تاریخچه را عوض نکند.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('cancelled_by_type', 16)->nullable()->after('cancel_reason');
            $table->string('cancelled_by_name')->nullable()->after('cancelled_by_type');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['cancelled_by_type', 'cancelled_by_name']);
        });
    }
};
