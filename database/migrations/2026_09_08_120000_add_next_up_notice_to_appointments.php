<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «نفر بعدی» یک بار خبردار می‌شود، نه هر بار.
     *
     * کارخانه‌ی چندلاینه پشت سر هم بارگیری شروع می‌کند و بدون این ستون،
     * راننده‌ی بعدی برای هر کدام یک پیامک می‌گرفت. زمان‌مهر نگه داشته می‌شود
     * و نه یک پرچم، چون بعداً باید بشود گفت چه ساعتی خبر داده شده.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('next_up_notified_at')->nullable()->after('called_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('next_up_notified_at');
        });
    }
};
