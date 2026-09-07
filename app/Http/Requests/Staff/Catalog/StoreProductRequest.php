<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff\Catalog;

use App\Domain\Access\Permissions;
use App\Models\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
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
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                // کد داخل هر کارخانه یکتاست، نه در کل سامانه
                Rule::unique('products', 'code')->where('factory_id', $this->factoryId()),
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'load_tons' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'loading_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],

            // اولویت پیش‌فرضِ نوبت‌هایی که با این محصول ثبت می‌شوند
            'priority' => ['required', 'integer', 'min:0', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'نام محصول',
            'code' => 'کد محصول',
            'description' => 'توضیحات',
            'load_tons' => 'تناژ هر بارگیری',
            'loading_minutes' => 'مدت بارگیری',
            'sort_order' => 'ترتیب نمایش',
            'priority' => 'اولویت',
            'is_active' => 'وضعیت',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'کد محصول فقط می‌تواند حرف انگلیسی، رقم، خط تیره و زیرخط داشته باشد.',
            'code.unique' => 'محصول دیگری با همین کد در این کارخانه ثبت شده است.',
        ];
    }

    protected function factoryId(): int
    {
        return $this->user()->factory_id
            ?? Factory::where('is_active', true)->orderBy('id')->value('id');
    }
}
