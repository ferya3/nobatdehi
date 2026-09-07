<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePriorityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::QUEUE_MANAGE);
    }

    public function rules(): array
    {
        return [
            // ۰ عادی، ۱۰۰ بالاترین. بازه‌ی باز عمدی است تا کارخانه سطح‌های
            // خودش را تعریف کند، ولی دلیل هر بار اجباری است.
            'priority' => ['required', 'integer', 'min:0', 'max:100'],
            'priority_reason' => ['required', 'string', 'min:5', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'priority_reason.required' => 'دلیل تغییر اولویت را بنویسید.',
            'priority_reason.min' => 'دلیل تغییر اولویت را کامل بنویسید.',
        ];
    }
}
