<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Devices\DeviceTokens;
use App\Models\Setting;

/**
 * تنظیمات دستگاه‌های گیت.
 *
 * مثل تنظیمات پیامک در دیتابیس است و نه در .env: عوض‌کردن توکن دوربین یا
 * خاموش‌کردن بارکدخوان نباید به SSH و ری‌استارت سرویس نیاز داشته باشد. توکن
 * هم مثل رمز پنل پیامک رمزنگاری‌شده ذخیره می‌شود.
 */
final class GateDevices
{
    public const TOKEN_SETTING = 'gate_anpr_token';

    public function __construct(private readonly DeviceTokens $tokens) {}

    /** سقف حجم عکسِ دوربین — دستگاهِ خراب تا پرشدن دیسک می‌فرستد */
    private const MAX_IMAGE_KB = 4096;

    public function anprEnabled(): bool
    {
        return Setting::get('gate_anpr_enabled', '0') === '1';
    }

    public function barcodeEnabled(): bool
    {
        return Setting::get('gate_barcode_enabled', '1') === '1';
    }

    public function stationCameraEnabled(): bool
    {
        return Setting::get('gate_station_camera_enabled', '1') === '1';
    }

    /**
     * پلاکِ خوانده‌شده با اطمینانِ کمتر از این، تصمیم‌گیر نیست.
     *
     * زیر آستانه یعنی «دوربین مطمئن نیست» و نه «پلاک غلط است» — آنجا حرفِ
     * آخر را نگهبان می‌زند. اگر خواندنِ نامطمئن را مثل خواندنِ قطعی حساب
     * کنیم، اولین باران کل گیت را قفل می‌کند.
     */
    public function minConfidence(): int
    {
        return max(0, min(100, (int) Setting::get('gate_anpr_min_confidence', '70')));
    }

    /** چند روز عکس و خواندن نگه داشته شود */
    public function retentionDays(): int
    {
        return max(1, (int) Setting::get('gate_reading_retention_days', '30'));
    }

    public function maxImageBytes(): int
    {
        return self::MAX_IMAGE_KB * 1024;
    }

    // توکن‌ها یک جا نگهداری می‌شوند تا دوربین و باسکول یک مکانیزم داشته
    // باشند، نه دو تا که با هم فرق کوچکی دارند و همان فرق روزی مسئله شود.

    public function token(): string
    {
        return $this->tokens->token(DeviceTokens::GATE);
    }

    public function hasToken(): bool
    {
        return $this->tokens->hasToken(DeviceTokens::GATE);
    }

    public function tokenMatches(?string $candidate): bool
    {
        return $this->tokens->matches(DeviceTokens::GATE, $candidate);
    }

    /** توکن تازه می‌سازد و ذخیره می‌کند — مقدارِ خام فقط همین یک بار برمی‌گردد */
    public function rotateToken(): string
    {
        return $this->tokens->rotate(DeviceTokens::GATE);
    }
}
