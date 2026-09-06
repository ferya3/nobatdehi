<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Domain\Truck\PlateNumber;
use App\Support\Digits;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('driver') !== null;
    }

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
        $factoryId = $this->route('factory')?->id ?? $this->input('factory_id');

        return [
            'driver_name' => ['required', 'string', 'min:3', 'max:120'],
            'plate_two' => ['required', 'digits:2'],
            'plate_letter' => ['required', 'string', Rule::in(PlateNumber::LETTERS)],
            'plate_three' => ['required', 'digits:3'],
            'plate_iran' => ['required', 'digits:2'],
            'truck_type_id' => ['required', Rule::exists('truck_types', 'id')->where('is_active', true)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
            'slot_id' => ['required', Rule::exists('appointment_slots', 'id')],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->plate() === null) {
                    $validator->errors()->add('plate_two', 'شماره پلاک معتبر نیست.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'driver_name.required' => 'نام و نام خانوادگی راننده را وارد کنید.',
            'driver_name.min' => 'نام راننده خیلی کوتاه است.',
            'plate_two.required' => 'دو رقم اول پلاک را وارد کنید.',
            'plate_two.digits' => 'دو رقم اول پلاک باید دو رقم باشد.',
            'plate_letter.required' => 'حرف پلاک را انتخاب کنید.',
            'plate_letter.in' => 'حرف پلاک معتبر نیست.',
            'plate_three.required' => 'سه رقم میانی پلاک را وارد کنید.',
            'plate_three.digits' => 'بخش میانی پلاک باید سه رقم باشد.',
            'plate_iran.required' => 'کد ایران پلاک را وارد کنید.',
            'plate_iran.digits' => 'کد ایران باید دو رقم باشد.',
            'truck_type_id.required' => 'نوع خودرو را انتخاب کنید.',
            'product_id.required' => 'نوع بار را انتخاب کنید.',
            'slot_id.required' => 'ساعت مراجعه را انتخاب کنید.',
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
