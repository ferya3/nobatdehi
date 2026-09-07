<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * تنظیمات کلید-مقدار.
 *
 * کلیدهای حساس (رمز پنل پیامک، کلید API) در حالت ذخیره با Crypt رمزنگاری
 * می‌شوند تا در دیتابیس متن خام نمانند.
 *
 * ساختار و کلیدها عمداً همان payroll-saas است، چون هدف این است که همان پنل
 * پیامکی با همان تنظیمات کار کند.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'settings.all';

    /** کلیدهایی که رمزنگاری‌شده ذخیره می‌شوند */
    public const SECRET_KEYS = ['sms_password', 'sms_api_key', 'gate_anpr_token'];

    public const DEFAULTS = [
        'sms_enabled' => '1',
        'sms_provider' => 'afe',
        'sms_api_key' => '',
        'sms_sender' => '',
        'sms_username' => '',
        'sms_password' => '',
        'sms_custom_url' => '',
        'sms_custom_method' => 'GET',
        'sms_afe_domain' => '',
        'sms_optout' => '',

        // شماره‌هایی که پیام «نوبت جدید» را می‌گیرند — با کاما جدا
        'sms_manager_recipients' => '',

        // دستگاه‌های گیت: بارکدخوان، دوربین ایستگاه، دوربین پلاک‌خوان شبکه‌ای
        'gate_barcode_enabled' => '1',
        'gate_station_camera_enabled' => '1',
        'gate_anpr_enabled' => '0',
        'gate_anpr_token' => '',
        'gate_anpr_min_confidence' => '70',
        'gate_reading_retention_days' => '30',
    ];

    /** همه‌ی تنظیمات (پیش‌فرض + ذخیره‌شده) با رمزگشایی اسرار */
    public static function values(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            $out = self::DEFAULTS;

            foreach (static::query()->get() as $row) {
                if (array_key_exists($row->key, $out)) {
                    $out[$row->key] = (string) $row->value;
                }
            }

            foreach (self::SECRET_KEYS as $key) {
                if (($out[$key] ?? '') !== '') {
                    try {
                        $out[$key] = Crypt::decryptString((string) $out[$key]);
                    } catch (Throwable) {
                        // مقدار قدیمی رمزنشده — دست‌نخورده بماند
                    }
                }
            }

            return $out;
        });
    }

    public static function get(string $key, string $default = ''): string
    {
        return (string) (self::values()[$key] ?? $default);
    }

    /** @param array<string, string|null> $values */
    public static function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            $value = (string) ($value ?? '');

            if (in_array($key, self::SECRET_KEYS, true)) {
                // فیلد خالی یعنی «عوض نکن»، نه «پاک کن» — فرم رمز را نشان نمی‌دهد
                if ($value === '') {
                    continue;
                }

                $value = Crypt::encryptString($value);
            }

            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        self::forget();
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * مقادیر امن برای نمایش در فرم: اسرار هرگز به Frontend نمی‌روند،
     * فقط این که «تنظیم شده‌اند یا نه».
     *
     * @return array<string, mixed>
     */
    public static function forDisplay(): array
    {
        $values = self::values();

        foreach (self::SECRET_KEYS as $key) {
            $values[$key.'_is_set'] = ($values[$key] ?? '') !== '';
            $values[$key] = '';
        }

        return $values;
    }
}
