<?php

declare(strict_types=1);

namespace App\Http\Requests\Driver;

use App\Domain\Truck\PlateNumber;
use App\Rules\NationalCode;
use App\Support\Digits;
use Carbon\CarbonImmutable;
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
            'national_code' => Digits::toLatin((string) $this->input('national_code')),
            'plate_two' => Digits::toLatin((string) $this->input('plate_two')),
            'plate_three' => Digits::toLatin((string) $this->input('plate_three')),
            'plate_iran' => Digits::toLatin((string) $this->input('plate_iran')),
        ]);
    }

    /**
     * ساعتِ دقیق نوبت اینجا نیست و نمی‌تواند باشد.
     *
     * راننده حداکثر یک *کف* می‌فرستد — «فلان روز، از فلان ساعت به بعد» —
     * و زمان‌بند سمت سرور اولین جای خالی را از آنجا پیدا می‌کند. اگر ساعتِ
     * قطعی از درخواست خوانده می‌شد، هر کسی می‌توانست نوبتی روی لاینِ اشغال
     * بنشاند.
     *
     * روز هم فقط از فردا: امروز مالِ صف است.
     */
    public function rules(): array
    {
        return [
            'driver_name' => ['required', 'string', 'min:3', 'max:120'],
            // خالی یعنی «زودترین ممکن» — همان رفتار همیشگی
            'preferred_date' => ['nullable', 'date_format:Y-m-d', 'after:today'],
            'preferred_time' => ['nullable', 'required_with:preferred_date', 'date_format:H:i'],
            'national_code' => ['required', 'string', new NationalCode],
            'plate_two' => ['required', 'digits:2'],
            'plate_letter' => ['required', 'string', Rule::in(PlateNumber::LETTERS)],
            'plate_three' => ['required', 'digits:3'],
            'plate_iran' => ['required', 'digits:2'],
            'truck_type_id' => ['required', Rule::exists('truck_types', 'id')->where('is_active', true)],
            'product_id' => ['required', Rule::exists('products', 'id')->where('is_active', true)],
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

    /**
     * روز و ساعتی که راننده خواسته — یا null یعنی «زودترین ممکن».
     *
     * دو فیلد جدا در فرم‌اند چون انتخابگرشان جداست، ولی برای دامنه یک لحظه‌اند.
     */
    public function preferredStart(): ?CarbonImmutable
    {
        $date = $this->string('preferred_date')->trim()->toString();
        $time = $this->string('preferred_time')->trim()->toString();

        if ($date === '' || $time === '') {
            return null;
        }

        return CarbonImmutable::parse($date.' '.$time);
    }

    public function messages(): array
    {
        return [
            'preferred_date.after' => 'انتخاب روز از فردا به بعد ممکن است.',
            'preferred_date.date_format' => 'روز انتخاب‌شده معتبر نیست.',
            'preferred_time.required_with' => 'ساعت را هم انتخاب کنید.',
            'preferred_time.date_format' => 'ساعت انتخاب‌شده معتبر نیست.',
            'driver_name.required' => 'نام و نام خانوادگی راننده را وارد کنید.',
            'driver_name.min' => 'نام راننده خیلی کوتاه است.',
            'national_code.required' => 'کد ملی راننده را وارد کنید.',
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
