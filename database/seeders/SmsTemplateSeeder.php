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
                'key' => 'appointment.next-up',
                'title' => 'نوبت شما نزدیک است — نفر بعدی',
                'body' => "راننده محترم {driver}\nنوبت جلوتر از شما شروع به بارگیری کرد.\nتا حدود {eta} دیگر نوبت شماست (حدود ساعت {at}).\nشماره نوبت شما: {number}\n{factory}",
            ],
            [
                'key' => 'appointment.cancelled',
                'title' => 'لغو نوبت — اطلاع به راننده',
                'body' => 'نوبت شماره {number} در تاریخ {date} ساعت {time} لغو شد.',
            ],
            [
                'key' => 'appointment.cancelled.manager',
                'title' => 'لغو نوبت — اطلاع به مدیر',
                'body' => "لغو نوبت بارگیری\nشماره نوبت: {number}\nپلاک: {plate}\nراننده: {driver}\nنوع بار: {product}\nتاریخ: {date}\nساعت: {time}\n{by}\nدلیل: {reason}",
            ],
            [
                'key' => 'weight.discrepancy.manager',
                'title' => 'مغایرت وزن — اطلاع به مدیر',
                'body' => "هشدار {kind}\nشماره نوبت: {number}\nپلاک: {plate}\nراننده: {driver}\nنوع بار: {product}\nوزن خالص: {net} کیلوگرم\nتناژ حواله: {expected} کیلوگرم\nاختلاف: {variance} کیلوگرم\nبرگه خروج صادر نشد.",
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
