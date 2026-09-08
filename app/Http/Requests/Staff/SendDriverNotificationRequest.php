<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Notification\Audience;
use App\Support\Mobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendDriverNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::NOTIFICATIONS_SEND);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->mobile)) {
            $this->merge(['mobile' => Mobile::normalize($this->mobile) ?? $this->mobile]);
        }
    }

    public function rules(): array
    {
        return [
            'audience' => ['required', Rule::enum(Audience::class)],

            // فقط وقتی مخاطب «یک راننده» است شماره لازم — و آن‌وقت واجب — است
            'mobile' => [
                Rule::requiredIf(fn () => $this->input('audience') === Audience::One->value),
                'nullable', 'string', 'regex:/^09\d{9}$/',
                Rule::exists('drivers', 'mobile'),
            ],

            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:1000'],

            /*
             * مسیر داخل همان دامنه و نه یک URL کامل.
             *
             * اگر آدرس کامل بپذیریم، هر کسی که این دسترسی را دارد می‌تواند
             * راننده‌ها را با یک اعلانِ رسمی‌به‌نظر به سایت دلخواهش بفرستد.
             */
            'path' => ['nullable', 'string', 'max:200', 'regex:#^/[A-Za-z0-9/_\-?=&.]*$#'],
        ];
    }

    public function attributes(): array
    {
        return [
            'audience' => 'گیرندگان',
            'mobile' => 'شماره موبایل',
            'title' => 'عنوان',
            'body' => 'متن',
            'path' => 'مسیر',
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود.',
            'mobile.exists' => 'راننده‌ای با این شماره در سامانه نیست.',
            'path.regex' => 'مسیر باید با / شروع شود؛ آدرس کامل پذیرفته نمی‌شود.',
        ];
    }
}
