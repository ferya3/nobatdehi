<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

/**
 * چیزی که دوربین پلاک‌خوان می‌فرستد.
 *
 * قواعد عمداً سهل‌گیرند: دوربین‌های مختلف نام فیلدهای متفاوتی دارند و اگر
 * سرور سخت‌گیری کند، نتیجه‌اش این است که خواندن‌ها بی‌صدا دور ریخته می‌شوند
 * و کسی تا روزِ حادثه نمی‌فهمد. هر چه رسید ثبت می‌شود؛ تصمیمِ «این پلاک
 * راهبند را باز می‌کند یا نه» جای دیگری گرفته می‌شود.
 */
class StoreAnprReadingRequest extends FormRequest
{
    /** توکن دستگاه را middleware بررسی کرده است */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * فقط نام فیلدها یکدست می‌شود، نه محتوایشان.
     *
     * رشته‌ی پلاک عمداً دست‌نخورده می‌ماند — حتی ارقام فارسی‌اش. نرمال‌سازی
     * کارِ PlateNumber است و نتیجه‌اش در ستون جدا می‌نشیند؛ اگر همین‌جا
     * دستکاری‌اش کنیم، دیگر راهی نیست بفهمیم دستگاه واقعاً چه فرستاده بود
     * وقتی نرمال‌سازی اشتباه کرده باشد.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'plate' => $this->input('plate', $this->input('plateNumber')),
            'confidence' => $this->input('confidence', $this->input('score')),
            'image' => $this->input('image', $this->input('plateImage')),
        ]);
    }

    public function rules(): array
    {
        return [
            'plate' => ['nullable', 'string', 'max:64'],
            'confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'lane' => ['nullable', 'string', 'max:32'],
            'device' => ['nullable', 'string', 'max:64'],
            'captured_at' => ['nullable', 'string', 'max:40'],

            // base64 داخل JSON یا فایل در multipart — هر دو پذیرفته می‌شوند
            'image' => ['nullable', 'string'],
            'image_file' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /**
     * زمانِ خودِ دستگاه، اگر قابل فهم باشد.
     *
     * ساعتِ دوربین‌ها معمولاً کج است؛ زمانِ نامعتبر یعنی «همین حالا» و نه
     * خطا — یک خواندن نباید به‌خاطر ساعتِ اشتباهِ دستگاه دور ریخته شود.
     */
    public function capturedAt(): ?CarbonImmutable
    {
        $raw = $this->string('captured_at')->trim()->toString();

        if ($raw === '') {
            return null;
        }

        try {
            $at = CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }

        // زمانِ آینده یا خیلی دور، یعنی ساعت دستگاه تنظیم نیست
        return $at->isFuture() || $at->lessThan(now()->subDay()) ? null : $at;
    }
}
