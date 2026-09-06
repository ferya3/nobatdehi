<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();      // appointment.created ...
            $table->string('title');
            $table->text('body');                      // با متغیرهای {number} {plate} ...
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->string('to', 20);
            $table->text('body');
            $table->string('template_key', 64)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 16)->default('QUEUED'); // QUEUED SENT FAILED
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->nullableMorphs('related');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('sms_templates');
    }
};
