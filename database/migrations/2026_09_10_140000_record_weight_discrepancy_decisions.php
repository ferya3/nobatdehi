<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تصمیمِ مدیریت درباره‌ی یک توزینِ مغایر.
 *
 * تا پیش از این سامانه می‌گفت «تا تعیین تکلیف برگه صادر نمی‌شود» و هیچ جایی
 * برای تعیین تکلیف نداشت. کامیون می‌ماند تا کسی دست به دیتابیس ببرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            // approved یا rejected
            $table->string('discrepancy_decision', 16)->nullable()->after('discrepancy_alerted_at');
            $table->text('discrepancy_decision_reason')->nullable()->after('discrepancy_decision');
            $table->timestamp('discrepancy_decided_at')->nullable()->after('discrepancy_decision_reason');
            $table->foreignId('discrepancy_decided_by_user_id')->nullable()->after('discrepancy_decided_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loading_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discrepancy_decided_by_user_id');
            $table->dropColumn(['discrepancy_decision', 'discrepancy_decision_reason', 'discrepancy_decided_at']);
        });
    }
};
