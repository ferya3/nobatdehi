<?php

return [
    // ۶ رقم: ۵ رقم یعنی صد هزار حالت، که با نرخِ مجاز فعلی در چند ساعت
    // قابل جاروب کردن است. ۶ رقم همان تلاش را ده برابر گران می‌کند.
    'length' => (int) env('OTP_LENGTH', 6),

    // اعتبار کد (دقیقه)
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 2),

    // حداکثر تلاش برای وارد کردن یک کد
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    /*
     * سقفِ IP عمداً کم است.
     *
     * کارخانه‌ای که همه‌ی رانندگانش پشت یک NAT باشند به عدد بالاتری نیاز
     * دارد و می‌تواند در .env بالا ببرد — ولی پیش‌فرضِ باز، تصمیمی است که
     * هیچ‌کس آگاهانه نگرفته.
     */

    // فاصله‌ی لازم تا ارسال مجدد (ثانیه)
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),

    'rate_limits' => [
        // حداکثر درخواست کد در ساعت — به ازای شماره موبایل
        'per_mobile_hourly' => (int) env('OTP_PER_MOBILE_HOURLY', 5),
        // حداکثر درخواست کد در ساعت — به ازای IP
        'per_ip_hourly' => (int) env('OTP_PER_IP_HOURLY', 10),
        // حداکثر تلاش تأیید ناموفق در ساعت — به ازای IP
        'verify_per_ip_hourly' => (int) env('OTP_VERIFY_PER_IP_HOURLY', 15),
    ],

    /*
     * برگرداندن کد در پاسخ فقط برای توسعه است.
     *
     * حتی اگر کسی سهواً در production روشنش کند، شرط محیط جلویش را
     * می‌گیرد — یک تنظیم اشتباه در .env نباید OTP را بی‌اثر کند.
     */
    'expose_in_response' => (bool) env('OTP_EXPOSE_IN_RESPONSE', false)
        && env('APP_ENV', 'production') !== 'production',
];
