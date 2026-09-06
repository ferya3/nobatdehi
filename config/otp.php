<?php

return [
    'length' => (int) env('OTP_LENGTH', 5),

    // اعتبار کد (دقیقه)
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 2),

    // حداکثر تلاش برای وارد کردن یک کد
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),

    // فاصله‌ی لازم تا ارسال مجدد (ثانیه)
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 60),

    'rate_limits' => [
        // حداکثر درخواست کد در ساعت — به ازای شماره موبایل
        'per_mobile_hourly' => (int) env('OTP_PER_MOBILE_HOURLY', 5),
        // حداکثر درخواست کد در ساعت — به ازای IP
        'per_ip_hourly' => (int) env('OTP_PER_IP_HOURLY', 20),
        // حداکثر تلاش تأیید ناموفق در ساعت — به ازای IP
        'verify_per_ip_hourly' => (int) env('OTP_VERIFY_PER_IP_HOURLY', 30),
    ],

    // در محیط توسعه کد در پاسخ برگردانده می‌شود تا بدون پنل پیامکی هم بشود تست کرد
    'expose_in_response' => (bool) env('OTP_EXPOSE_IN_RESPONSE', false),
];
