<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Weighbridge\ScaleDevices;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * ثبت وزن — از باسکول یا با دست.
 *
 * تفاوت اصلی با نسخه‌ی قبلی: «مستقیم از باسکول» دیگر یک تیک در فرم نیست.
 * یا شناسه‌ی خواندنی از scale_readings فرستاده می‌شود — که سرور خودش وزنش
 * را برمی‌دارد و عددِ تایپ‌شده را اصلاً نمی‌خواند — یا اپراتور دستی وارد
 * می‌کند و باید بنویسد چرا. حالت سومی نیست.
 */
class RecordWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::WEIGHING_RECORD);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weight_kg' => Digits::toLatin((string) $this->input('weight_kg')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(['tare', 'gross'])],

            // خواندنِ باسکول. وقتی این باشد، weight_kg نادیده گرفته می‌شود.
            'reading_id' => ['nullable', 'integer', 'exists:scale_readings,id'],

            // ۵۰۰ کیلو تا ۸۰ تن: بیرونِ این بازه یعنی باسکول یا تایپ خراب است
            'weight_kg' => ['required_without:reading_id', 'nullable', 'numeric', 'min:500', 'max:80000'],

            // دلیل فقط وقتی لازم است که باسکولی وصل باشد و کسی دورش بزند.
            // روی نصبی که پل ندارد، تایپ‌کردن تنها راه است و پرسیدنِ «چرا
            // تایپ کردی» فقط فیلدی می‌سازد که با «test» پر می‌شود.
            'manual_reason' => [
                Rule::requiredIf(fn () => $this->bypassesConnectedScale()),
                'nullable', 'string', 'min:8', 'max:255',
            ],

            'photo' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /** ورود دستی در حالی که پل باسکول روشن است */
    private function bypassesConnectedScale(): bool
    {
        return $this->integer('reading_id') <= 0 && app(ScaleDevices::class)->enabled();
    }

    public function messages(): array
    {
        return [
            'weight_kg.required_without' => 'وزن را وارد کنید یا از باسکول بگیرید.',
            'weight_kg.min' => 'وزن واردشده برای یک کامیون خیلی کم است.',
            'weight_kg.max' => 'وزن واردشده برای یک کامیون خیلی زیاد است.',
            'manual_reason.required' => 'باسکول وصل است؛ برای ورود دستی وزن، نوشتن دلیل الزامی است.',
            'manual_reason.min' => 'دلیل ورود دستی را کامل بنویسید.',
            'photo.image' => 'فایل پیوست باید عکس باشد.',
            'photo.max' => 'حجم عکس بیش از حد مجاز است.',
        ];
    }
}
