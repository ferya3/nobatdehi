<?php

declare(strict_types=1);

namespace App\Domain\Sms;

/**
 * فهرست پیامک‌های سامانه — کلید، عنوان، متنِ پیش‌فرض، و متغیرهایش.
 *
 * متنِ پیامک‌ها را کارخانه عوض می‌کند، نه برنامه‌نویس: «به نگهبانی مراجعه
 * کنید» یا «به باسکول برو» چیزی است که هر کارخانه با ادبیات خودش می‌گوید.
 * ولی متغیرها را برنامه تعیین می‌کند؛ اگر اپراتور {plate} را در قالبی
 * بنویسد که پلاکی ندارد، همان {plate} خام برای راننده می‌رود.
 *
 * پس یک فهرست، سه مصرف: seeder از آن متنِ اولیه را برمی‌دارد، صفحه‌ی
 * تنظیمات از آن می‌گوید کدام متغیرها مجازند، و آزمون از آن مطمئن می‌شود
 * قالبی که کد صدا می‌زند واقعاً وجود دارد.
 */
final class SmsTemplates
{
    /**
     * @return array<string, array{title: string, body: string, variables: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'appointment.created.driver' => [
                'title' => 'تأیید نوبت برای راننده',
                'body' => "نوبت بارگیری شما ثبت شد.\nشماره نوبت: {number}\nتاریخ: {date}\nساعت: {time}\nنوع بار: {product}\nمدت بارگیری: حدود {loading_minutes} دقیقه\nتخمین پایان کار: {ends_at}\n{factory}",
                'variables' => [
                    'number' => 'شماره نوبت',
                    'date' => 'تاریخ نوبت',
                    'time' => 'ساعت نوبت',
                    'product' => 'نوع بار',
                    'loading_minutes' => 'مدت بارگیری به دقیقه',
                    'ends_at' => 'ساعت تخمینی پایان',
                    'factory' => 'نام کارخانه',
                ],
            ],

            'appointment.created.manager' => [
                'title' => 'اطلاع نوبت جدید به مدیر',
                'body' => "نوبت جدید بارگیری\nشماره نوبت: {number}\nپلاک: {plate}\nراننده: {driver}\nنوع بار: {product}\nتاریخ: {date}\nساعت: {time}",
                'variables' => [
                    'number' => 'شماره نوبت',
                    'plate' => 'شماره پلاک',
                    'driver' => 'نام راننده',
                    'product' => 'نوع بار',
                    'date' => 'تاریخ نوبت',
                    'time' => 'ساعت نوبت',
                ],
            ],

            'appointment.called' => [
                'title' => 'فراخوان راننده',
                'body' => "نوبت شما فرا رسید.\nشماره نوبت: {number}\nلطفاً به نگهبانی مراجعه کنید.",
                'variables' => [
                    'number' => 'شماره نوبت',
                    'loading_point' => 'نام لاین بارگیری',
                    'plate' => 'شماره پلاک',
                ],
            ],

            // بعد از تأیید پلاک در گیت: کامیون وارد شده و اولین ایستگاهش باسکول است
            'appointment.checked-in' => [
                'title' => 'ورود ثبت شد — راهیِ باسکول اول',
                'body' => "ورود شما ثبت شد.\nشماره نوبت: {number}\nلطفاً برای توزین خالی به باسکول مراجعه کنید.",
                'variables' => [
                    'number' => 'شماره نوبت',
                    'plate' => 'شماره پلاک',
                    'product' => 'نوع بار',
                ],
            ],

            'appointment.next-up' => [
                'title' => 'نوبت شما نزدیک است — نفر بعدی',
                'body' => "راننده محترم {driver}\nنوبت جلوتر از شما شروع به بارگیری کرد.\nتا حدود {eta} دیگر نوبت شماست (حدود ساعت {at}).\nشماره نوبت شما: {number}\n{factory}",
                'variables' => [
                    'driver' => 'نام راننده',
                    'eta' => 'زمان تقریبی تا نوبت',
                    'at' => 'ساعت تقریبی',
                    'number' => 'شماره نوبت',
                    'factory' => 'نام کارخانه',
                ],
            ],

            // بعد از پایان بارگیری: ایستگاه بعدی باسکول دوم است
            'appointment.loaded' => [
                'title' => 'پایان بارگیری — راهیِ باسکول دوم',
                'body' => "بارگیری شما تمام شد.\nشماره نوبت: {number}\nلطفاً برای توزین پر به باسکول مراجعه کنید.",
                'variables' => [
                    'number' => 'شماره نوبت',
                    'plate' => 'شماره پلاک',
                    'product' => 'نوع بار',
                    'loading_point' => 'نام لاین بارگیری',
                ],
            ],

            'appointment.cancelled' => [
                'title' => 'لغو نوبت — اطلاع به راننده',
                'body' => 'نوبت شماره {number} در تاریخ {date} ساعت {time} لغو شد.',
                'variables' => [
                    'number' => 'شماره نوبت',
                    'date' => 'تاریخ نوبت',
                    'time' => 'ساعت نوبت',
                    'plate' => 'شماره پلاک',
                    'driver' => 'نام راننده',
                    'product' => 'نوع بار',
                    'by' => 'لغوکننده',
                    'reason' => 'دلیل لغو',
                ],
            ],

            'appointment.cancelled.manager' => [
                'title' => 'لغو نوبت — اطلاع به مدیر',
                'body' => "لغو نوبت بارگیری\nشماره نوبت: {number}\nپلاک: {plate}\nراننده: {driver}\nنوع بار: {product}\nتاریخ: {date}\nساعت: {time}\n{by}\nدلیل: {reason}",
                'variables' => [
                    'number' => 'شماره نوبت',
                    'plate' => 'شماره پلاک',
                    'driver' => 'نام راننده',
                    'product' => 'نوع بار',
                    'date' => 'تاریخ نوبت',
                    'time' => 'ساعت نوبت',
                    'by' => 'لغوکننده',
                    'reason' => 'دلیل لغو',
                ],
            ],

            'weight.discrepancy.manager' => [
                'title' => 'مغایرت وزن — اطلاع به مدیر',
                'body' => "هشدار {kind}\nشماره نوبت: {number}\nپلاک: {plate}\nراننده: {driver}\nنوع بار: {product}\nوزن خالص: {net} کیلوگرم\nتناژ حواله: {expected} کیلوگرم\nاختلاف: {variance} کیلوگرم\nبرگه خروج صادر نشد.",
                'variables' => [
                    'kind' => 'نوع اخطار (اضافه‌بار یا مغایرت وزن)',
                    'number' => 'شماره نوبت',
                    'plate' => 'شماره پلاک',
                    'driver' => 'نام راننده',
                    'product' => 'نوع بار',
                    'net' => 'وزن خالص',
                    'expected' => 'تناژ حواله',
                    'variance' => 'اختلاف',
                    'date' => 'تاریخ نوبت',
                    'reason' => 'متن کامل مغایرت',
                ],
            ],

            'otp.login' => [
                'title' => 'کد ورود',
                'body' => "کد ورود شما: {code}\nاین کد تا {minutes} دقیقه معتبر است.",
                'variables' => [
                    'code' => 'کد یک‌بارمصرف',
                    'minutes' => 'مدت اعتبار به دقیقه',
                ],
            ],
        ];
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, string> */
    public static function variablesFor(string $key): array
    {
        return self::all()[$key]['variables'] ?? [];
    }
}
