<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Support\Digits;
use App\Support\Mobile;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => preg_replace('/\D+/', '', Digits::toLatin((string) $this->input('code'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:20'],
            'code' => ['required', 'string', 'min:4', 'max:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => 'شماره موبایل را وارد کنید.',
            'code.required' => 'کد ارسال‌شده را وارد کنید.',
            'code.min' => 'کد وارد‌شده کامل نیست.',
        ];
    }

    public function mobile(): string
    {
        return (string) Mobile::normalize($this->input('mobile'));
    }
}
