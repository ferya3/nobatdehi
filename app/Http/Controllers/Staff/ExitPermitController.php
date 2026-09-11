<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Factory;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * برگه‌ی خروج — همان کاغذی که راننده دمِ راهبند نشان می‌دهد.
 *
 * تا اینجا شماره‌ی برگه فقط یک رشته در دیتابیس بود. کارخانه‌ای که راننده‌اش
 * باید چیزی در دست داشته باشد، مجبور بود آن را دستی روی دفتر بنویسد —
 * یعنی همان جایی که عددها عوض می‌شوند.
 *
 * برگه چیزی از خودش نمی‌سازد: هر عددش از رکورد توزین خوانده می‌شود.
 */
class ExitPermitController extends Controller
{
    public function show(Request $request, Appointment $appointment): Response
    {
        abort_unless(
            $request->user()?->can(Permissions::WEIGHING_RECORD)
                || $request->user()?->can(Permissions::QUEUE_VIEW),
            403,
            'برای این بخش دسترسی ندارید.',
        );

        abort_unless($appointment->factory_id === $this->factory($request)->id, 404);

        $appointment->load(['driver', 'truck.truckType', 'product', 'loadingPoint', 'factory', 'loadingRecord']);

        $record = $appointment->loadingRecord;

        // برگه‌ای که هنوز صادر نشده، برگه نیست. چاپ کردنش یعنی کاغذی که
        // شبیه مجوز است و مجوز نیست.
        abort_if($record?->exit_permit_number === null, 404, 'برای این حواله برگه خروج صادر نشده است.');

        $factory = $appointment->factory;

        return Inertia::render('Staff/ExitPermit', [
            'permit' => [
                'number' => $record->exit_permit_number,
                'issued_at' => $this->moment($record->exit_permit_issued_at),
                'waybill_number' => $record->waybill_number ?? $appointment->number,

                'factory' => [
                    'name' => $factory?->name,
                    'phone' => $factory?->phone,
                    'address' => $factory?->address,
                ],

                'appointment' => [
                    'number' => $appointment->number,
                    'date' => Jalali::long($appointment->date),
                    'product' => $appointment->product?->name,
                    'loading_point' => $appointment->loadingPoint?->name,
                ],

                'truck' => [
                    'plate' => $appointment->truck?->plate(),
                    'type' => $appointment->truck?->truckType?->name,
                    'capacity_kg' => $appointment->truck?->truckType?->capacity_tons !== null
                        ? (float) $appointment->truck->truckType->capacity_tons * 1000
                        : null,
                ],

                'driver' => [
                    'name' => $appointment->driver?->displayName(),
                    'mobile' => $appointment->driver?->mobile,
                    'national_code' => $appointment->driver?->national_code,
                ],

                'weights' => [
                    'tare_kg' => $record->empty_weight_kg,
                    'gross_kg' => $record->loaded_weight_kg,
                    'net_kg' => $record->net_weight_kg,
                    'tare_at' => $this->moment($record->tare_weighed_at),
                    'gross_at' => $this->moment($record->gross_weighed_at),
                ],

                // اضافه‌باری که مدیر پذیرفته، روی خودِ برگه می‌ماند. پنهان
                // کردنش یعنی کاغذی که با دفترِ سامانه نمی‌خواند.
                'overload' => $record->discrepancy_kind === null ? null : [
                    'kg' => $record->variance_kg,
                    'decision' => $record->discrepancy_decision,
                    'reason' => $record->discrepancy_decision_reason,
                ],
            ],
        ]);
    }

    /** تاریخ و ساعت شمسی، یا هیچ — ردیف‌های قدیمی همه‌ی مُهرها را ندارند */
    private function moment(?\DateTimeInterface $at): ?string
    {
        return $at === null ? null : Jalali::dateTime($at);
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
