<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * چیزی که پلِ باسکول می‌فرستد.
 *
 * سقف‌های وزن عمداً از RecordWeightRequest بازترند: آنجا وزنِ یک کامیون
 * ثبت می‌شود و ۵۰۰ تا ۸۰ هزار کیلو منطقی است، ولی اینجا هر عددی که روی
 * نشان‌دهنده است ذخیره می‌شود — از جمله صفرِ باسکولِ خالی و عددِ منفیِ
 * باسکولی که تنظیمش به هم ریخته. همان‌ها هستند که نشان می‌دهند دستگاه
 * مشکل دارد؛ دور ریختنشان یعنی مشکل را ندیدن.
 */
class StoreScaleReadingRequest extends FormRequest
{
    /** توکن دستگاه را middleware بررسی کرده است */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scale' => ['required', 'string', 'max:64'],
            'weight_kg' => ['required', 'numeric', 'min:-100000', 'max:200000'],
            'unit' => ['nullable', 'string', 'max:8'],
            'stable' => ['nullable', 'boolean'],
            'device' => ['nullable', 'string', 'max:64'],
            'raw' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'scale.required' => 'نام باسکول فرستاده نشده است.',
            'weight_kg.required' => 'وزن فرستاده نشده است.',
        ];
    }
}
