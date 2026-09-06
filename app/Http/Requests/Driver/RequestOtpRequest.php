<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Support\Mobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:20'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! Mobile::isValid($this->input('mobile'))) {
                    $validator->errors()->add('mobile', 'شماره موبایل معتبر نیست.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => 'شماره موبایل را وارد کنید.',
        ];
    }

    public function mobile(): string
    {
        return (string) Mobile::normalize($this->input('mobile'));
    }
}
