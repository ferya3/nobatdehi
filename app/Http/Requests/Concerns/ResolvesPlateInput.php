<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Domain\Truck\PlateNumber;
use App\Support\Digits;
use Illuminate\Validation\Rule;

/**
 * چهار تکه‌ی پلاک، همان‌طور که اپراتور روی تبلت واردشان می‌کند.
 *
 * بیش از یک ایستگاه پلاک می‌گیرد و هر کدام دسترسیِ خودش را دارد — نگهبانی
 * با queue.checkin، باسکول با weighing.record. چیزی که میانشان مشترک است
 * فقط شکلِ ورودی است، پس همان یکی اینجا می‌ماند و authorize() دستِ خودِ
 * Request می‌ماند.
 *
 * دو نسخه‌ی جدا از این قواعد یعنی روزی یکی حرف «معلولین» را می‌پذیرد و
 * دیگری نه، و هیچ‌کس تا آن روز نمی‌فهمد.
 */
trait ResolvesPlateInput
{
    /**
     * اپراتور با کیبورد فارسی تایپ می‌کند؛ «۱۲» باید همان «12» باشد.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'plate_two' => Digits::toLatin((string) $this->input('plate_two')),
            'plate_three' => Digits::toLatin((string) $this->input('plate_three')),
            'plate_iran' => Digits::toLatin((string) $this->input('plate_iran')),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'plate_two' => ['required', 'digits:2'],
            'plate_letter' => ['required', 'string', Rule::in(PlateNumber::LETTERS)],
            'plate_three' => ['required', 'digits:3'],
            'plate_iran' => ['required', 'digits:2'],
        ];
    }

    /** @return array<string, string> */
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
