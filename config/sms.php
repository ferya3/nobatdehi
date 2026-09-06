<?php

return [
    // ارائه‌دهنده‌ی فعال: log | kavenegar
    'provider' => env('SMS_PROVIDER', 'log'),

    'log_channel' => env('SMS_LOG_CHANNEL', 'stack'),

    // شماره‌هایی که پیام‌های مدیریتی (نوبت جدید) را دریافت می‌کنند
    'manager_recipients' => array_values(array_filter(
        explode(',', (string) env('SMS_MANAGER_RECIPIENTS', ''))
    )),

    'providers' => [
        'kavenegar' => [
            'api_key' => env('KAVENEGAR_API_KEY'),
            'sender' => env('KAVENEGAR_SENDER'),
            'timeout' => (int) env('SMS_TIMEOUT', 10),
        ],
    ],
];
