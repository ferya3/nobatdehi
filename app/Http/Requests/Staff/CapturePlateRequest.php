<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * عکسی که نگهبان از ایستگاه، از پلاک کامیون می‌گیرد.
 *
 * پلاک اختیاری است: در اغلب موارد فقط عکس ثبت می‌شود و تطبیق را نگهبان
 * انجام می‌دهد. اگر دوربینِ ایستگاه خودش OCR داشته باشد، همان مقدار را هم
 * می‌فرستد و آن‌وقت سرور مقایسه می‌کند.
 */
class CapturePlateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::QUEUE_CHECKIN);
    }

    public function rules(): array
    {
        return [
            'image' => ['required', 'image', 'max:4096'],
            'plate' => ['nullable', 'string', 'max:64'],
            'appointment' => ['nullable', 'string', 'exists:appointments,ulid'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'عکس پلاک ثبت نشد. دوباره تلاش کنید.',
            'image.image' => 'فایل ارسالی عکس نیست.',
            'image.max' => 'حجم عکس بیش از حد مجاز است.',
        ];
    }
}
