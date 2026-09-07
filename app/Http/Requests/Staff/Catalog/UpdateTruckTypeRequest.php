<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff\Catalog;

use Illuminate\Validation\Rule;

class UpdateTruckTypeRequest extends StoreTruckTypeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('truck_types', 'code')->ignore($this->route('truckType')?->id),
            ],
        ]);
    }
}
