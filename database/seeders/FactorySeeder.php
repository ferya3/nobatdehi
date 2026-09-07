<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Factory;
use App\Models\LoadingPoint;
use App\Models\Product;
use App\Models\TruckType;
use App\Models\WorkingHour;
use Illuminate\Database\Seeder;

class FactorySeeder extends Seeder
{
    public function run(): void
    {
        $factory = Factory::updateOrCreate(
            ['slug' => 'main'],
            [
                'name' => 'کارخانه اصلی',
                'phone' => '02100000000',
                'timezone' => 'Asia/Tehran',
                'slot_minutes' => 30,
                'daily_capacity' => 80,
                // یک خط بارگیری: محافظه‌کارانه‌ترین پیش‌فرض. عدد بزرگ‌تر یعنی
                // چند کامیون روی یک ساعت نوبت می‌گیرند، و اگر کارخانه واقعاً
                // آن‌قدر خط نداشته باشد همه‌شان صبح جلوی در جمع می‌شوند.
                'loading_lines' => 1,
                'avg_loading_minutes' => 25,
                'no_show_grace_minutes' => 60,
                'booking_horizon_days' => 7,
                'booking_lead_minutes' => 60,
                'max_active_per_mobile' => 2,
                'max_active_per_plate' => 1,
                'is_active' => true,
            ],
        );

        // 0 = شنبه ... 6 = جمعه — جمعه تعطیل
        foreach (range(0, 6) as $weekday) {
            WorkingHour::updateOrCreate(
                ['factory_id' => $factory->id, 'weekday' => $weekday],
                [
                    'is_open' => $weekday !== 6,
                    'opens_at' => '07:00',
                    'closes_at' => '18:00',
                    'capacity_per_slot' => 5,
                ],
            );
        }

        // زمان‌ها نقطه‌ی شروع‌اند و از پنل «انواع کامیون» تغییر می‌کنند:
        // هرچه کامیون بزرگ‌تر، بارگیری طولانی‌تر و مهلت حضور سخاوتمندانه‌تر.
        $truckTypes = [
            ['code' => 'tak', 'name' => 'تک', 'capacity_tons' => 10, 'loading_minutes' => 20, 'grace_minutes' => 45, 'sort_order' => 1],
            ['code' => 'joft', 'name' => 'جفت', 'capacity_tons' => 18, 'loading_minutes' => 25, 'grace_minutes' => 60, 'sort_order' => 2],
            ['code' => 'teriler', 'name' => 'تریلی', 'capacity_tons' => 30, 'loading_minutes' => 40, 'grace_minutes' => 90, 'sort_order' => 3],
            ['code' => 'khavar', 'name' => 'خاور', 'capacity_tons' => 6, 'loading_minutes' => 15, 'grace_minutes' => 45, 'sort_order' => 4],
            ['code' => 'kamion', 'name' => 'کامیون', 'capacity_tons' => 22, 'loading_minutes' => 30, 'grace_minutes' => 60, 'sort_order' => 5],
        ];

        foreach ($truckTypes as $type) {
            TruckType::updateOrCreate(['code' => $type['code']], $type);
        }

        $products = [
            ['code' => 'A', 'name' => 'محصول A', 'load_tons' => 30, 'loading_minutes' => 25, 'sort_order' => 1],
            ['code' => 'B', 'name' => 'محصول B', 'load_tons' => 20, 'loading_minutes' => 20, 'sort_order' => 2],
        ];

        $productModels = [];

        foreach ($products as $product) {
            $productModels[] = Product::updateOrCreate(
                ['factory_id' => $factory->id, 'code' => $product['code']],
                $product + ['factory_id' => $factory->id, 'is_active' => true],
            );
        }

        foreach (range(1, $factory->loading_lines) as $line) {
            $point = LoadingPoint::updateOrCreate(
                ['factory_id' => $factory->id, 'code' => "line-{$line}"],
                ['name' => "لاین {$line}", 'is_active' => true],
            );

            $point->products()->syncWithoutDetaching(
                collect($productModels)->pluck('id')->all()
            );
        }
    }
}
