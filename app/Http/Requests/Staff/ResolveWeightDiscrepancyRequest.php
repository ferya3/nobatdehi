<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveWeightDiscrepancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::WEIGHING_RESOLVE);
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['approved', 'rejected'])],

            // دلیل اجباری است چون این تصمیم بعداً پرسیده می‌شود: «چرا این
            // کامیون با دو تن اختلاف بیرون رفت؟» جوابش باید همان‌جا باشد.
            'reason' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'decision.required' => 'تأیید یا رد را انتخاب کنید.',
            'reason.required' => 'برای این تصمیم نوشتن دلیل الزامی است.',
            'reason.min' => 'دلیل را کامل بنویسید.',
        ];
    }
}
