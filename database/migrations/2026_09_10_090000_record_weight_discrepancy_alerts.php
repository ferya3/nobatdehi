<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * اخطارِ مغایرت وزن، ثبت‌شده روی همان توزین.
 *
 * دو کار می‌کند: پنل صف می‌داند کنار کدام کامیون اخطار بگذارد، و پیامکِ
 * مدیر فقط یک بار می‌رود. بدون ستون دوم، هر بار که اپراتور دوباره وزن
 * می‌کند یک پیامک تازه می‌رفت و چند روز بعد کسی دیگر نمی‌خواندشان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->string('discrepancy_kind', 16)->nullable()->after('is_overload');
            $table->timestamp('discrepancy_alerted_at')->nullable()->after('discrepancy_kind');
        });
    }

    public function down(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->dropColumn(['discrepancy_kind', 'discrepancy_alerted_at']);
        });
    }
};
