<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Sms\SmsTemplates;
use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * قالب‌های پیامک را می‌سازد، ولی متنی را که کارخانه عوض کرده بازنویسی نمی‌کند.
 *
 * این seeder در هر به‌روزرسانی دوباره اجرا می‌شود. اگر متن را هر بار
 * بازنویسی کند، «به نگهبانی مراجعه کنید» که مدیر خودش نوشته، بی‌سروصدا به
 * متن پیش‌فرض برمی‌گردد و هیچ‌کس تا رسیدنِ اولین پیامکِ اشتباه نمی‌فهمد.
 */
class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SmsTemplates::all() as $key => $template) {
            $row = SmsTemplate::firstOrNew(['key' => $key]);

            // عنوان همیشه به‌روز می‌شود — نامِ قالب است، نه متنِ پیامک
            $row->title = $template['title'];

            if (! $row->exists) {
                $row->body = $template['body'];
                $row->is_active = true;
            }

            $row->save();
        }
    }
}
