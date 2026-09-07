<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff\Catalog;

use App\Domain\Access\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTruckTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::PRODUCTS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('truck_types', 'code'),
            ],
            'capacity_tons' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],

            // خالی گذاشتن عمداً مجاز است: یعنی «از محصول/کارخانه بگیر»
            'loading_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'grace_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],

            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'نام نوع کامیون',
            'code' => 'کد',
            'capacity_tons' => 'ظرفیت',
            'loading_minutes' => 'مدت بارگیری',
            'grace_minutes' => 'مهلت حضور',
            'sort_order' => 'ترتیب نمایش',
            'is_active' => 'وضعیت',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'کد فقط می‌تواند حرف انگلیسی، رقم، خط تیره و زیرخط داشته باشد.',
            'code.unique' => 'نوع کامیون دیگری با همین کد ثبت شده است.',
            'grace_minutes.min' => 'مهلت حضور کمتر از ۵ دقیقه عملاً یعنی حذف فوری نوبت.',
        ];
    }
}
