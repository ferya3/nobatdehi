<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'is_active' => $this->status->isActive(),

            'date' => $this->date->toDateString(),
            'jalali_date' => Jalali::date($this->date),
            'jalali_long' => Jalali::long($this->date),
            'time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),

            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'load_tons' => $this->product->load_tons,
            ]),

            'truck' => $this->whenLoaded('truck', fn () => [
                'id' => $this->truck->id,
                'plate' => $this->truck->plate(),
                'type' => $this->truck->truckType?->name,
            ]),

            'driver' => $this->whenLoaded('driver', fn () => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'mobile' => $this->driver->mobile,
            ]),

            'loading_point' => $this->whenLoaded('loadingPoint', fn () => $this->loadingPoint?->name),

            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'called_at' => $this->called_at?->toIso8601String(),
            'loading_started_at' => $this->loading_started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
