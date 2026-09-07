<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ردپای ورود از گیت.
     *
     * قانون «بدون QR ورود ممنوع» فقط وقتی معنی دارد که بشود بعداً ثابت کرد
     * هر ورود از کدام راه ثبت شده. entry_method همان ستونی است که در ممیزی
     * نشان می‌دهد کدام کامیون بدون اسکن وارد شده و چه کسی اجازه‌اش را داده.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('gate_entry_method', 16)->nullable()->after('checked_in_at');
            $table->string('gate_observed_plate', 32)->nullable()->after('gate_entry_method');
            $table->string('gate_override_reason')->nullable()->after('gate_observed_plate');
            $table->foreignId('gate_override_by_user_id')->nullable()->after('gate_override_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gate_override_by_user_id');
            $table->dropColumn(['gate_entry_method', 'gate_observed_plate', 'gate_override_reason']);
        });
    }
};
