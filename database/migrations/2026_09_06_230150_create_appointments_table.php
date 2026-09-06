<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();                 // شناسه عمومی برای URL و QR
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();

            // شماره نوبت: دنباله‌ی روزانه‌ی هر کارخانه (#128)
            $table->unsignedInteger('number');

            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('truck_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('slot_id')->constrained('appointment_slots')->restrictOnDelete();
            $table->foreignId('loading_point_id')->nullable()->constrained()->nullOnDelete();

            // از اسلات کپی می‌شوند تا گزارش‌گیری به join وابسته نباشد
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');

            $table->string('status', 16)->default('BOOKED');

            // QR: فقط hash توکن امضاشده ذخیره می‌شود، نه خود توکن
            $table->string('qr_token_hash', 64)->nullable();
            $table->timestamp('qr_used_at')->nullable();

            // زمان هر انتقال — پایه‌ی تمام گزارش‌های زمانی
            $table->timestamp('waiting_at')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('loading_started_at')->nullable();
            $table->timestamp('loading_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('no_show_at')->nullable();

            $table->string('cancel_reason')->nullable();
            $table->text('note')->nullable();

            // منشأ درخواست
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->unique(['factory_id', 'date', 'number']);
            $table->unique('idempotency_key');
            $table->index(['factory_id', 'date', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index(['truck_id', 'status']);
            $table->index(['slot_id', 'status']);
        });

        DB::statement("
            ALTER TABLE appointments
            ADD CONSTRAINT appointments_status_check
            CHECK (status IN (
                'BOOKED','WAITING','CALLED','CHECKED_IN','LOADING','LOADED','COMPLETED',
                'CANCELLED','NO_SHOW','REJECTED','EXPIRED'
            ))
        ");

        // تاریخچه‌ی کامل انتقال وضعیت — منبع حقیقت برای گزارش و بازرسی
        Schema::create('appointment_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->boolean('is_rollback')->default(false);
            $table->string('reason')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_label')->nullable();   // «سامانه» برای انتقال خودکار
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['appointment_id', 'created_at']);
        });

        // ثبت بارگیری و وزن — باسکول
        Schema::create('loading_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loading_point_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('empty_weight_kg', 10, 2)->nullable();
            $table->decimal('loaded_weight_kg', 10, 2)->nullable();
            $table->decimal('net_weight_kg', 10, 2)->nullable();
            $table->string('waybill_number')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('appointment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loading_records');
        Schema::dropIfExists('appointment_transitions');
        Schema::dropIfExists('appointments');
    }
};
