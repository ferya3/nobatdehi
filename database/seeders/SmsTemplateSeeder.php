<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

class SmsTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'key' => 'appointment.created.driver',
                'title' => 'تأیید نوبت برای راننده',
                'body' => "نوبت بارگیری شما ثبت شد.\nشماره نوبت: {number}\nتاریخ: {date}\nساعت: {time}\nنوع بار: {product}\nمدت بارگیری: حدود {loading_minutes} دقیقه\nتخمین پایان کار: {ends_at}\n{factory}",
            ],
            [
                'key' => 'appointment.created.manager',
                'title' => 'اطلاع نوبت جدید به مدیر',
                'body' => "نوبت جدید بارگیری\nشماره نوبت: {number}\nپلاک: {plate}\nراننده: {driver}\nنوع بار: {product}\nتاریخ: {date}\nساعت: {time}",
            ],
            [
                'key' => 'appointment.called',
                'title' => 'فراخوان راننده',
                'body' => "نوبت شما فرا رسید.\nشماره نوبت: {number}\nلطفاً به {loading_point} مراجعه کنید.",
            ],
            [
                'key' => 'appointment.cancelled',
                'title' => 'لغو نوبت',
                'body' => "نوبت شماره {number} در تاریخ {date} ساعت {time} لغو شد.",
            ],
            [
                'key' => 'otp.login',
                'title' => 'کد ورود',
                'body' => "کد ورود شما: {code}\nاین کد تا {minutes} دقیقه معتبر است.",
            ],
        ];

        foreach ($templates as $template) {
            SmsTemplate::updateOrCreate(['key' => $template['key']], $template + ['is_active' => true]);
        }
    }
}
