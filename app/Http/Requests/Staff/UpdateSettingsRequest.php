<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'daily_capacity' => ['required', 'integer', 'min:1', 'max:2000'],
            'loading_lines' => ['required', 'integer', 'min:1', 'max:50'],
            'avg_loading_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'booking_horizon_days' => ['required', 'integer', 'min:0', 'max:60'],
            'booking_lead_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_active_per_mobile' => ['required', 'integer', 'min:1', 'max:20'],
            'max_active_per_plate' => ['required', 'integer', 'min:1', 'max:20'],

            'working_hours' => ['required', 'array', 'size:7'],
            'working_hours.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'working_hours.*.is_open' => ['required', 'boolean'],
            'working_hours.*.opens_at' => ['required', 'date_format:H:i'],
            'working_hours.*.closes_at' => ['required', 'date_format:H:i'],
            'working_hours.*.capacity_per_slot' => ['required', 'integer', 'min:0', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->input('working_hours', []) as $index => $day) {
                    if (! ($day['is_open'] ?? false)) {
                        continue;
                    }

                    if (($day['closes_at'] ?? '') <= ($day['opens_at'] ?? '')) {
                        $validator->errors()->add(
                            "working_hours.{$index}.closes_at",
                            'ساعت پایان باید بعد از ساعت شروع باشد.',
                        );
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'slot_minutes.required' => 'طول هر نوبت را وارد کنید.',
            'slot_minutes.min' => 'طول هر نوبت نمی‌تواند کمتر از ۵ دقیقه باشد.',
            'daily_capacity.required' => 'حداکثر نوبت روزانه را وارد کنید.',
            'loading_lines.required' => 'تعداد لاین بارگیری را وارد کنید.',
            'avg_loading_minutes.required' => 'زمان متوسط بارگیری را وارد کنید.',
            'booking_horizon_days.required' => 'افق نوبت‌دهی را وارد کنید.',
            'booking_lead_minutes.required' => 'حداقل فاصله تا نوبت را وارد کنید.',
            'working_hours.*.opens_at.date_format' => 'ساعت شروع باید به قالب ۰۷:۰۰ باشد.',
            'working_hours.*.closes_at.date_format' => 'ساعت پایان باید به قالب ۱۸:۰۰ باشد.',
        ];
    }
}
