<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ثبت ورود از گیت.
 *
 * دو چیز اجباری است و هیچ‌کدام «پیش‌فرض درست» ندارند: نگهبان باید صریحاً
 * بگوید پلاک را دیده و مطابق است، و اگر QR اسکن نشده، مجوز استثنا و دلیل
 * لازم است. حالت پیش‌فرضِ سامانه «ورود ممنوع» است، نه «ورود مجاز».
 */
class GateCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::QUEUE_CHECKIN);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'observed_plate' => Digits::toLatin((string) $this->input('observed_plate')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            // پلاک‌خوان می‌تواند این را پر کند؛ آن‌وقت سرور خودش مقایسه می‌کند
            // و نظر نگهبان دیگر تعیین‌کننده نیست.
            'observed_plate' => ['nullable', 'string', 'max:32'],

            // وقتی پلاک‌خوان نیست، تأیید چشمیِ نگهبان جای آن را می‌گیرد
            'plate_match' => ['required_without:observed_plate', 'boolean'],

            'override_reason' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'plate_match.required_without' => 'تطبیق پلاک را تأیید یا رد کنید.',
            'override_reason.min' => 'دلیل ثبت دستی را کامل بنویسید.',
        ];
    }
}
