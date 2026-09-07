<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordWeightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::WEIGHING_RECORD);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weight_kg' => Digits::toLatin((string) $this->input('weight_kg')),
        ]);
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', Rule::in(['tare', 'gross'])],

            // ۵۰۰ کیلو تا ۸۰ تن: بیرونِ این بازه یعنی باسکول یا تایپ خراب است
            'weight_kg' => ['required', 'numeric', 'min:500', 'max:80000'],

            // «device» یعنی مقدار مستقیم از نمایشگر باسکول آمده، نه از دست
            'source' => ['required', Rule::in(['device', 'manual'])],

            'photo' => ['nullable', 'image', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'weight_kg.required' => 'وزن را وارد کنید.',
            'weight_kg.min' => 'وزن واردشده برای یک کامیون خیلی کم است.',
            'weight_kg.max' => 'وزن واردشده برای یک کامیون خیلی زیاد است.',
            'photo.image' => 'فایل پیوست باید عکس باشد.',
            'photo.max' => 'حجم عکس بیش از حد مجاز است.',
        ];
    }
}
