<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Domain\Slot\SlotGenerator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\UpdateSettingsRequest;
use App\Models\Factory;
use App\Models\WorkingHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SlotGenerator $slots,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): Response
    {
        abort_unless($request->user()?->can(Permissions::SETTINGS_MANAGE), 403);

        $factory = $this->factory($request);

        return Inertia::render('Staff/Settings', [
            'factory' => $factory->only([
                'name', 'slot_minutes', 'daily_capacity', 'loading_lines', 'avg_loading_minutes', 'no_show_grace_minutes',
                'booking_horizon_days', 'booking_lead_minutes', 'max_active_per_mobile', 'max_active_per_plate',
            ]),
            'workingHours' => $this->workingHours($factory),
            'weekdays' => WorkingHour::WEEKDAYS,
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $factory = $this->factory($request);

        $before = array_merge(
            $factory->only([
                'slot_minutes', 'daily_capacity', 'loading_lines', 'avg_loading_minutes', 'no_show_grace_minutes',
                'booking_horizon_days', 'booking_lead_minutes', 'max_active_per_mobile', 'max_active_per_plate',
            ]),
            ['working_hours' => $this->workingHours($factory)],
        );

        $data = $request->validated();

        DB::transaction(function () use ($factory, $data) {
            $factory->update(collect($data)->except('working_hours')->all());

            foreach ($data['working_hours'] as $day) {
                WorkingHour::updateOrCreate(
                    ['factory_id' => $factory->id, 'weekday' => $day['weekday']],
                    [
                        'is_open' => $day['is_open'],
                        'opens_at' => $day['opens_at'],
                        'closes_at' => $day['closes_at'],
                        'capacity_per_slot' => $day['capacity_per_slot'],
                    ],
                );
            }
        });

        // اسلات‌های آینده باید با تنظیمات جدید هماهنگ شوند، ولی ظرفیت هیچ اسلاتی
        // زیر تعداد رزروشده‌اش نمی‌آید — SlotGenerator خودش این را تضمین می‌کند.
        $this->slots->generateHorizon($factory->fresh());

        $this->audit->log(
            action: 'UPDATE_BOOKING_SETTINGS',
            entity: $factory->fresh(),
            oldValues: $before,
            newValues: array_merge(
                collect($data)->except('working_hours')->all(),
                ['working_hours' => $this->workingHours($factory->fresh())],
            ),
            request: $request,
        );

        return back()->with('success', 'تنظیمات نوبت‌دهی ذخیره شد و اسلات‌های آینده به‌روزرسانی شدند.');
    }

    /** @return array<int, array<string, mixed>> */
    private function workingHours(Factory $factory): array
    {
        $existing = WorkingHour::where('factory_id', $factory->id)->get()->keyBy('weekday');

        return collect(range(0, 6))->map(function (int $weekday) use ($existing) {
            $row = $existing->get($weekday);

            return [
                'weekday' => $weekday,
                'name' => WorkingHour::WEEKDAYS[$weekday],
                'is_open' => (bool) ($row?->is_open ?? true),
                'opens_at' => substr((string) ($row?->opens_at ?? '07:00'), 0, 5),
                'closes_at' => substr((string) ($row?->closes_at ?? '18:00'), 0, 5),
                'capacity_per_slot' => (int) ($row?->capacity_per_slot ?? 5),
            ];
        })->all();
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
