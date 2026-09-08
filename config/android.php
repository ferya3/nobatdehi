<?php

declare(strict_types=1);

return [
    /*
     * اثر انگشتِ SHA-256 کلیدی که APK با آن امضا شده.
     *
     * اندروید فقط با این می‌فهمد که برنامه‌ی نصب‌شده واقعاً مالِ همین دامنه
     * است. بدون آن، لینکِ پیامک در مرورگر باز می‌شود و راننده باید دوباره
     * وارد شود.
     *
     * از خروجیِ این فرمان برداشته می‌شود:
     *   keytool -list -v -keystore nobat.keystore -alias nobat
     *
     * چند مقدار را با کاما جدا کنید (مثلاً کلید فروشگاه و کلید خودتان).
     */
    'fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANDROID_FINGERPRINTS', '')),
    ))),

    'package' => env('ANDROID_PACKAGE', 'ir.nobatdehi.driver'),
];
