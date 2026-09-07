<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * هر خواندنِ پلاک، چه به نوبتی وصل شود چه نشود.
     *
     * عمداً جدولِ جدا است و ستونی روی appointments نیست: دوربین پلاک‌خوان
     * کامیونِ بی‌نوبت، ماشین شخصی و خواندنِ ناموفق را هم می‌بیند، و دقیقاً
     * همان‌ها هستند که حراست بعد از یک حادثه دنبالشان می‌گردد. اگر فقط
     * خواندن‌های موفق را نگه داریم، سابقه‌ای ساخته‌ایم که همیشه بی‌عیب است.
     *
     * plate_key وقتی null است که رشته‌ی دستگاه اصلاً پلاک نبوده — آن ردیف
     * هم می‌ماند، چون «دوربین چیزی خواند که ما نفهمیدیم» خودش یک واقعیت است.
     */
    public function up(): void
    {
        Schema::create('plate_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('factory_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();

            // anpr = دوربین شبکه‌ای خودش فرستاد، station = نگهبان از ایستگاه عکس گرفت
            $table->string('source', 16);
            $table->string('device_name', 64)->nullable();
            $table->string('lane', 32)->nullable();

            // رشته‌ی خام دستگاه، دست‌نخورده — برای وقتی که نرمال‌سازی اشتباه کند
            $table->string('raw_plate', 64)->nullable();
            $table->string('plate_key', 32)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();

            $table->string('image_path')->nullable();
            $table->string('image_disk', 32)->nullable();

            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at');
            $table->timestamps();

            // پرس‌وجوی همیشگیِ گیت: «تازه‌ترین خواندن‌های این کارخانه»
            $table->index(['factory_id', 'captured_at']);
            // تطبیق پلاک با نوبت
            $table->index(['factory_id', 'plate_key', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plate_readings');
    }
};
