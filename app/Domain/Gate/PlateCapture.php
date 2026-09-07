<?php

declare(strict_types=1);

namespace App\Domain\Gate;

use App\Domain\Truck\PlateNumber;
use App\Events\PlateRead;
use App\Models\Factory;
use App\Models\PlateReading;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * ثبت یک خواندنِ پلاک، از هر دو راهی که ممکن است برسد.
 *
 * دو مسیر ورودی دارد و عمداً یک خروجی: چه دوربین شبکه‌ای خودش POST کند و چه
 * نگهبان از ایستگاه عکس بگیرد، هر دو به یک ردیف plate_readings می‌رسند. اگر
 * هرکدام جدول و منطق خودش را داشت، «سابقه‌ی ورود» دو روایت می‌شد که فقط
 * وقتی به هم نمی‌خوانند معلوم می‌شود.
 */
final class PlateCapture
{
    /** عکس‌های پلاک روی دیسک خصوصی می‌نشینند، نه public */
    public const DISK = 'local';

    public function __construct(private readonly GateDevices $devices) {}

    /**
     * ثبت خواندن.
     *
     * $rawPlate همان چیزی است که دستگاه فرستاده — دست‌نخورده ذخیره می‌شود.
     * plate_key نتیجه‌ی نرمال‌سازی است و اگر رشته پلاک نباشد null می‌ماند؛
     * آن ردیف هم ثبت می‌شود، چون «دوربین چیزی دید» خودش داده است.
     */
    public function record(
        Factory $factory,
        string $source,
        ?string $rawPlate,
        ?UploadedFile $image = null,
        ?string $imageData = null,
        ?int $confidence = null,
        ?string $lane = null,
        ?string $deviceName = null,
        ?CarbonImmutable $capturedAt = null,
        ?User $capturedBy = null,
    ): PlateReading {
        $path = $this->storeImage($factory, $image, $imageData);

        $reading = PlateReading::create([
            'factory_id' => $factory->id,
            'source' => $source,
            'device_name' => $deviceName ? mb_substr($deviceName, 0, 64) : null,
            'lane' => $lane ? mb_substr($lane, 0, 32) : null,
            'raw_plate' => $rawPlate !== null ? mb_substr($rawPlate, 0, 64) : null,
            'plate_key' => PlateNumber::normalizeKey($rawPlate),
            'confidence' => $this->clampConfidence($confidence),
            'image_path' => $path,
            'image_disk' => $path ? self::DISK : null,
            'captured_by_user_id' => $capturedBy?->id,
            'captured_at' => $capturedAt ?? now(),
        ]);

        // پنل نگهبانی باید همان لحظه بداند دوربین چه خوانده، نه با تأخیر polling
        PlateRead::dispatch($reading);

        return $reading;
    }

    /**
     * ذخیره‌ی عکس.
     *
     * دو شکل ورودی چون دو دنیای متفاوت‌اند: مرورگر multipart می‌فرستد و
     * دوربین‌های شبکه‌ای معمولاً base64 داخل JSON. هیچ‌کدام نباید مسیرِ
     * دیگری را مجبور کند خودش را عوض کند.
     *
     * خطای ذخیره‌سازی، خواندن را از بین نمی‌برد: پلاکِ خوانده‌شده مهم‌تر از
     * عکس است و ردیف بدون عکس بهتر از هیچ ردیف است.
     */
    private function storeImage(Factory $factory, ?UploadedFile $image, ?string $imageData): ?string
    {
        $directory = 'plates/'.$factory->id.'/'.now()->format('Y-m-d');

        try {
            if ($image !== null) {
                return $image->store($directory, self::DISK) ?: null;
            }

            $binary = $this->decodeImage($imageData);

            if ($binary === null) {
                return null;
            }

            $path = $directory.'/'.Str::ulid()->toBase32().'.jpg';

            return Storage::disk(self::DISK)->put($path, $binary) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * base64 دوربین را به بایت تبدیل می‌کند.
     *
     * سقف حجم اینجا هم هست و نه فقط در Request: این متد از مسیر دستگاه صدا
     * زده می‌شود و دستگاهی که خراب شده باشد، تا وقتی دیسک پر نشود می‌فرستد.
     */
    private function decodeImage(?string $imageData): ?string
    {
        if ($imageData === null || $imageData === '') {
            return null;
        }

        // بعضی دوربین‌ها data URI کامل می‌فرستند
        if (str_contains($imageData, ',') && str_starts_with($imageData, 'data:')) {
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
        }

        $binary = base64_decode(trim($imageData), true);

        if ($binary === false || $binary === '') {
            return null;
        }

        if (strlen($binary) > $this->devices->maxImageBytes()) {
            return null;
        }

        // فایلی که با هیچ امضای تصویری شروع نشود، عکس نیست
        return $this->looksLikeImage($binary) ? $binary : null;
    }

    private function looksLikeImage(string $binary): bool
    {
        $signatures = [
            "\xFF\xD8\xFF",              // JPEG
            "\x89PNG\r\n\x1A\n",         // PNG
            'GIF87a', 'GIF89a',          // GIF
            'RIFF',                      // WebP
        ];

        foreach ($signatures as $signature) {
            if (str_starts_with($binary, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function clampConfidence(?int $confidence): ?int
    {
        if ($confidence === null) {
            return null;
        }

        return max(0, min(100, $confidence));
    }
}
