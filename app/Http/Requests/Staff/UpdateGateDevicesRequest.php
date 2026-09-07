<?php

declare(strict_types=1);

namespace App\Http\Requests\Staff;

use App\Domain\Access\Permissions;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGateDevicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permissions::SETTINGS_MANAGE);
    }

    public function rules(): array
    {
        return [
            'gate_barcode_enabled' => ['required', 'boolean'],
            'gate_station_camera_enabled' => ['required', 'boolean'],
            'gate_anpr_enabled' => ['required', 'boolean'],
            'gate_anpr_min_confidence' => ['required', 'integer', 'min:0', 'max:100'],
            'gate_reading_retention_days' => ['required', 'integer', 'min:1', 'max:365'],

            'scale_device_enabled' => ['required', 'boolean'],
            'scale_require_stable' => ['required', 'boolean'],
            'scale_reading_retention_days' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'gate_anpr_min_confidence.min' => 'حداقل اطمینان بین ۰ تا ۱۰۰ است.',
            'gate_anpr_min_confidence.max' => 'حداقل اطمینان بین ۰ تا ۱۰۰ است.',
            'gate_reading_retention_days.min' => 'مدت نگهداری حداقل یک روز است.',
            'gate_reading_retention_days.max' => 'مدت نگهداری حداکثر یک سال است.',
            'scale_reading_retention_days.min' => 'مدت نگهداری حداقل یک روز است.',
            'scale_reading_retention_days.max' => 'مدت نگهداری حداکثر یک سال است.',
        ];
    }

    /** @return array<string, string> مقادیر به شکلی که Setting ذخیره می‌کند */
    public function settings(): array
    {
        return [
            'gate_barcode_enabled' => $this->boolean('gate_barcode_enabled') ? '1' : '0',
            'gate_station_camera_enabled' => $this->boolean('gate_station_camera_enabled') ? '1' : '0',
            'gate_anpr_enabled' => $this->boolean('gate_anpr_enabled') ? '1' : '0',
            'gate_anpr_min_confidence' => (string) $this->integer('gate_anpr_min_confidence'),
            'gate_reading_retention_days' => (string) $this->integer('gate_reading_retention_days'),

            'scale_device_enabled' => $this->boolean('scale_device_enabled') ? '1' : '0',
            'scale_require_stable' => $this->boolean('scale_require_stable') ? '1' : '0',
            'scale_reading_retention_days' => (string) $this->integer('scale_reading_retention_days'),
        ];
    }
}
