<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_requests', function (Blueprint $table) {
            $table->id();
            $table->string('mobile', 11)->index();
            $table->string('code_hash');                 // هرگز کد خام ذخیره نمی‌شود
            $table->string('purpose', 32)->default('login');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->index(['mobile', 'created_at']);
        });

        // لاگ امنیتی — جدا از audit، برای بررسی نفوذ
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64)->index();   // login_failed، otp_failed، rate_limit ...
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('identifier')->nullable();   // موبایل یا ایمیل تلاش‌شده
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['identifier', 'created_at']);
        });

        // Audit — تغییرات حساس داده
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 64);
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('security_logs');
        Schema::dropIfExists('otp_requests');
    }
};
