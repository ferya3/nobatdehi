<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Truck\PlateNumber;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlateLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::QUEUE_CHECKIN);
    }

    /**
     * نگهبان روی تبلت با کیبورد فارسی تایپ می‌کند؛ «۱۲» باید همان «12» باشد.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'plate_two' => Digits::toLatin((string) $this->input('plate_two')),
            'plate_three' => Digits::toLatin((string) $this->input('plate_three')),
            'plate_iran' => Digits::toLatin((string) $this->input('plate_iran')),
        ]);
    }

    public function rules(): array
    {
        return [
            'plate_two' => ['required', 'digits:2'],
            'plate_letter' => ['required', 'string', Rule::in(PlateNumber::LETTERS)],
            'plate_three' => ['required', 'digits:3'],
            'plate_iran' => ['required', 'digits:2'],
        ];
    }

    public function messages(): array
    {
        return [
            'plate_two.required' => 'دو رقم اول پلاک را وارد کنید.',
            'plate_two.digits' => 'دو رقم اول پلاک باید دو رقم باشد.',
            'plate_letter.required' => 'حرف پلاک را انتخاب کنید.',
            'plate_letter.in' => 'حرف پلاک معتبر نیست.',
            'plate_three.required' => 'بخش میانی پلاک را وارد کنید.',
            'plate_three.digits' => 'بخش میانی پلاک باید سه رقم باشد.',
            'plate_iran.required' => 'کد ایران را وارد کنید.',
            'plate_iran.digits' => 'کد ایران باید دو رقم باشد.',
        ];
    }

    public function plate(): ?PlateNumber
    {
        return PlateNumber::tryMake(
            $this->string('plate_two')->toString(),
            $this->string('plate_letter')->toString(),
            $this->string('plate_three')->toString(),
            $this->string('plate_iran')->toString(),
        );
    }
}
