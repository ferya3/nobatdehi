<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! Hash::check($this->string('current_password')->toString(), $this->user()->password)) {
                    $validator->errors()->add('current_password', 'رمز فعلی درست نیست.');

                    return;
                }

                // رمز «جدیدی» که همان قبلی است، تغییر رمز نیست
                if (Hash::check($this->string('password')->toString(), $this->user()->password)) {
                    $validator->errors()->add('password', 'رمز جدید باید با رمز فعلی فرق داشته باشد.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'رمز فعلی را وارد کنید.',
            'password.required' => 'رمز جدید را وارد کنید.',
            'password.confirmed' => 'تکرار رمز جدید یکی نیست.',
            'password.min' => 'رمز جدید باید حداقل ۱۰ کاراکتر باشد.',
        ];
    }
}
