<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * اجبار به تغییر رمز در اولین ورود.
     *
     * هشدار در README کافی نبود: کاربران نمونه با رمز ثابت ساخته می‌شوند و
     * عملاً همان رمز می‌ماند. حالا تا رمز عوض نشود، هیچ صفحه‌ای از پنل باز
     * نمی‌شود.
     *
     * برای نصب‌های موجود روی true می‌رود: اگر رمزها را عوض کرده باشند یک بار
     * اضافه رمز می‌گذارند، و اگر نکرده باشند دقیقاً همان چیزی است که لازم است.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });

        DB::table('users')->update(['must_change_password' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'password_changed_at']);
        });
    }
};
