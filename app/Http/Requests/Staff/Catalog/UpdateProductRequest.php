<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff\Catalog;

use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('products', 'code')
                    ->where('factory_id', $this->factoryId())
                    ->ignore($this->route('product')?->id),
            ],
        ]);
    }
}
