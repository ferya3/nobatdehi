<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('truck_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');            // تک، جفت، تریلی، خاور، کامیون
            $table->string('code', 32)->unique();
            $table->decimal('capacity_tons', 6, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 64);
            $table->text('description')->nullable();
            $table->decimal('load_tons', 6, 2)->nullable();     // تناژ هر بارگیری
            $table->unsignedSmallInteger('loading_minutes')->nullable(); // زمان بارگیری این محصول
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['factory_id', 'code']);
        });

        Schema::create('loading_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->string('name');                       // لاین ۱، لاین ۲ ...
            $table->string('code', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['factory_id', 'code']);
        });

        // کدام محصول از کدام لاین بارگیری می‌شود
        Schema::create('loading_point_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loading_point_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->unique(['loading_point_id', 'product_id'], 'lpp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loading_point_product');
        Schema::dropIfExists('loading_points');
        Schema::dropIfExists('products');
        Schema::dropIfExists('truck_types');
    }
};
