<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Domain\Gate\GateDevices;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Middleware\VerifyGateDeviceToken;
use App\Http\Requests\Staff\UpdateGateDevicesRequest;
use App\Models\PlateReading;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تنظیمات دستگاه‌های گیت: بارکدخوان، دوربین ایستگاه، دوربین پلاک‌خوان.
 *
 * صفحه عمداً فهرست خواندن‌های اخیر را هم نشان می‌دهد. «دوربین وصل است؟»
 * سؤالی است که با تیکِ سبزِ تنظیمات جواب داده نمی‌شود — با دیدنِ اینکه در
 * ده دقیقه‌ی گذشته چیزی فرستاده یا نه جواب داده می‌شود.
 */
class GateDeviceController extends Controller
{
    public function __construct(
        private readonly GateDevices $devices,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): Response
    {
        $this->authorizeSettings($request);

        $values = Setting::values();

        return Inertia::render('Staff/GateDevices', [
            'settings' => [
                'gate_barcode_enabled' => $values['gate_barcode_enabled'] === '1',
                'gate_station_camera_enabled' => $values['gate_station_camera_enabled'] === '1',
                'gate_anpr_enabled' => $values['gate_anpr_enabled'] === '1',
                'gate_anpr_min_confidence' => (int) $values['gate_anpr_min_confidence'],
                'gate_reading_retention_days' => (int) $values['gate_reading_retention_days'],
            ],
            // خودِ توکن هرگز به Frontend نمی‌رود؛ فقط اینکه ساخته شده یا نه
            'tokenIsSet' => $this->devices->hasToken(),
            'endpoint' => url('/api/gate/anpr'),
            'headerName' => VerifyGateDeviceToken::HEADER,
            'recent' => $this->recent(),
        ]);
    }

    public function update(UpdateGateDevicesRequest $request): RedirectResponse
    {
        $before = collect(Setting::values())
            ->only(array_keys($request->settings()))
            ->all();

        Setting::putMany($request->settings());

        $this->audit->log(
            action: 'UPDATE_GATE_DEVICE_SETTINGS',
            entity: $request->user(),
            oldValues: $before,
            newValues: $request->settings(),
            request: $request,
        );

        return back()->with('success', 'تنظیمات دستگاه‌های گیت ذخیره شد.');
    }

    /**
     * ساخت توکن تازه برای دوربین.
     *
     * مقدار خام فقط همین یک بار برمی‌گردد و در flash می‌نشیند — بعد از آن
     * حتی همین صفحه هم نمی‌تواند نشانش دهد. چرخاندنِ توکن یعنی دوربینِ
     * قبلی از همان لحظه رد می‌شود، پس باید در دستگاه هم عوض شود.
     */
    public function rotateToken(Request $request): RedirectResponse
    {
        $this->authorizeSettings($request);

        $token = $this->devices->rotateToken();

        $this->audit->log(
            action: 'ROTATE_GATE_DEVICE_TOKEN',
            entity: $request->user(),
            oldValues: [],
            newValues: ['rotated_at' => now()->toIso8601String()],
            request: $request,
        );

        return back()->with('token', $token)->with(
            'success',
            'توکن تازه ساخته شد. آن را در تنظیمات دوربین بگذارید — دیگر نمایش داده نمی‌شود.',
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function recent(): array
    {
        return PlateReading::with('appointment:id,number')
            ->latest('captured_at')
            ->limit(20)
            ->get()
            ->map(fn (PlateReading $reading) => array_merge(
                AppointmentResource::reading($reading),
                [
                    'device' => $reading->device_name,
                    'appointment' => $reading->appointment?->number,
                    'at' => $reading->captured_at?->format('Y-m-d H:i:s'),
                ],
            ))
            ->all();
    }

    private function authorizeSettings(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::SETTINGS_MANAGE), 403);
    }
}
