<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * آخرین باری که برنامه‌ی روی گوشیِ این راننده با سرور حرف زد.
 *
 * بدون این ستون، «چرا اعلان نمی‌رسد؟» جواب ندارد: معلوم نیست سرور اعلان
 * نساخته، یا ساخته و برنامه هرگز سراغش نیامده. این عدد آن دو حالت را از
 * هم جدا می‌کند و مستقیم در پنل دیده می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->timestamp('app_last_seen_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn('app_last_seen_at');
        });
    }
};
