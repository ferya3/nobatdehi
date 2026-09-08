<?php

declare(strict_types=1);

namespace App\Domain\Notification;

use App\Models\Driver;
use App\Models\DriverNotification;
use Illuminate\Support\Facades\DB;

/**
 * ساختِ اعلان برای راننده.
 *
 * چیزی اینجا «ارسال» نمی‌شود: ردیف ساخته می‌شود و برنامه‌ی روی گوشی، دفعه‌ی
 * بعد که سر می‌زند، آن را برمی‌دارد. دلیلش این است که راهِ push واقعی
 * (Firebase) از ایران در دسترس نیست — نه ثبت‌نامش و نه تضمینی که گوشیِ
 * راننده سرویس‌های گوگل داشته باشد.
 *
 * پس این کانال «به‌زودی» است و نه «فوری». هر خبری که واقعاً فوری است —
 * «نوبت شما فرا رسید» — پیامک هم دارد و باید داشته باشد.
 */
final class DriverNotifier
{
    /** یک اعلان برای یک راننده — مسیرِ خودکارِ سامانه */
    public function send(
        Driver $driver,
        string $title,
        string $body,
        ?string $path = null,
        string $kind = 'general',
        ?int $createdBy = null,
    ): DriverNotification {
        return DriverNotification::create([
            'driver_id' => $driver->id,
            'created_by' => $createdBy,
            'title' => $title,
            'body' => $body,
            'path' => $path,
            'kind' => $kind,
        ]);
    }

    /**
     * یک پیام برای گروهی از راننده‌ها.
     *
     * درج دسته‌ای است و نه حلقه‌ی create(): «همه‌ی راننده‌ها» در یک کارخانه‌ی
     * فعال می‌تواند چند هزار ردیف باشد و چند هزار INSERT جداگانه، درخواستِ
     * مدیر را دقیقه‌ای معطل می‌کند.
     *
     * @return int تعداد راننده‌هایی که اعلان برایشان ساخته شد
     */
    public function broadcast(
        Audience $audience,
        int $factoryId,
        string $title,
        string $body,
        ?string $path = null,
        ?string $mobile = null,
        ?int $createdBy = null,
        string $kind = 'general',
    ): int {
        $now = now();
        $total = 0;

        $audience->query($factoryId, $mobile)
            ->select('id')
            ->chunkById(500, function ($drivers) use (&$total, $title, $body, $path, $createdBy, $kind, $now) {
                $rows = $drivers->map(fn (Driver $driver) => [
                    'driver_id' => $driver->id,
                    'created_by' => $createdBy,
                    'title' => $title,
                    'body' => $body,
                    'path' => $path,
                    'kind' => $kind,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('driver_notifications')->insert($rows);

                $total += count($rows);
            });

        return $total;
    }
}
