<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Gate\GateEntry;
use App\Models\Appointment;
use App\Models\PlateReading;
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
            'priority' => (int) $this->priority,
            'priority_reason' => $this->priority_reason,

            // «چطور وارد شد» — فقط وقتی واقعاً واردی در کار بوده
            'gate' => $this->when($this->checked_in_at !== null, fn () => [
                'entry_method' => $this->gate_entry_method,
                'entry_method_label' => GateEntry::methodLabel($this->gate_entry_method),
                'scan_source' => $this->gate_scan_source,
                'scan_source_label' => GateEntry::scanSourceLabel($this->gate_scan_source),
                'observed_plate' => $this->gate_observed_plate,
                'plate_source' => $this->gate_plate_source,
                'plate_source_label' => GateEntry::plateSourceLabel($this->gate_plate_source),
                'override_reason' => $this->gate_override_reason,
                'reading' => $this->whenLoaded(
                    'gatePlateReading',
                    fn () => $this->gatePlateReading ? self::reading($this->gatePlateReading) : null,
                ),
            ]),

            // «وزن چه شد» — برای پنل صف، که تنها جایی است که همه‌ی کامیون‌ها
            // کنار هم دیده می‌شوند. بدون این، مغایرتِ وزن فقط روی صفحه‌ی
            // باسکول دیده می‌شد و اپراتورِ صف از آن بی‌خبر می‌ماند.
            'weighing' => $this->whenLoaded('loadingRecord', fn () => $this->loadingRecord === null ? null : [
                'net_kg' => $this->loadingRecord->net_weight_kg,
                'expected_kg' => $this->loadingRecord->expected_net_kg,
                'variance_kg' => $this->loadingRecord->variance_kg,
                'is_overload' => (bool) $this->loadingRecord->is_overload,
                'discrepancy' => $this->loadingRecord->discrepancy_kind,
                'discrepancy_label' => match ($this->loadingRecord->discrepancy_kind) {
                    'overload' => 'اضافه‌بار',
                    'variance' => 'مغایرت وزن',
                    default => null,
                },
                'alerted_at' => $this->loadingRecord->discrepancy_alerted_at?->toIso8601String(),
                'exit_permit_number' => $this->loadingRecord->exit_permit_number,
                'awaits_decision' => $this->loadingRecord->awaitsDecision(),
                'decision' => $this->loadingRecord->discrepancy_decision,
                'decision_reason' => $this->loadingRecord->discrepancy_decision_reason,
                'decided_by' => $this->loadingRecord->discrepancyDecidedBy?->name,
                'decided_at' => $this->loadingRecord->discrepancy_decided_at?->toIso8601String(),
            ]),

            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => $this->cancelled_by_type,
            'cancelled_by_label' => $this->cancelledByLabel(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * خواندنِ پلاک، همان شکلی که صفحه‌ی نگهبانی هم می‌بیند.
     *
     * آدرس عکس از مسیرِ دارای دسترسی می‌آید و نه لینک مستقیمِ دیسک — عکسِ
     * پلاک داده‌ی نظارتی است و نباید با دانستن آدرس برای همه باز باشد.
     *
     * @return array<string, mixed>
     */
    public static function reading(PlateReading $reading): array
    {
        return [
            'id' => $reading->id,
            'source' => $reading->source,
            'source_label' => $reading->sourceLabel(),
            'plate_key' => $reading->plate_key,
            'raw_plate' => $reading->raw_plate,
            'confidence' => $reading->confidence,
            'lane' => $reading->lane,
            'recognised' => ! $reading->isUnreadable(),
            'image_url' => $reading->image_path !== null
                ? route('staff.gate.reading-image', $reading)
                : null,
            'captured_at' => $reading->captured_at?->toIso8601String(),
            'clock' => $reading->captured_at?->format('H:i:s'),
        ];
    }
}
