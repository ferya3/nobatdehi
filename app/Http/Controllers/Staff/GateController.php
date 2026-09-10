<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\InvalidStateTransition;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Domain\Appointment\Support\QrToken;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Gate\GateDevices;
use App\Domain\Gate\GateEntry;
use App\Domain\Gate\PlateCapture;
use App\Domain\Gate\PlateVerdict;
use App\Domain\Gate\PlateVerifier;
use App\Domain\Gate\ScanTicket;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Staff\Concerns\WorksOnOneWaybill;
use App\Http\Requests\Staff\CapturePlateRequest;
use App\Http\Requests\Staff\GateCheckInRequest;
use App\Http\Requests\Staff\PlateLookupRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\PlateReading;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * صفحه‌ی نگهبانی: اعتبارسنجی حواله و ثبت ورود.
 *
 * دو قانون که کل این کلاس دور آن‌ها ساخته شده:
 *
 *   ۱. بدون اسکن QR ورود ممنوع است. جستجوی پلاک فقط «نمایش وضعیت» است و
 *      دکمه‌ی ورودش خاموش می‌ماند، مگر کسی دسترسی gate.manual-override
 *      داشته باشد و دلیل بنویسد — که آن هم در لاگ امنیتی ثبت می‌شود.
 *   ۲. پلاکِ دیده‌شده باید با پلاکِ حواله یکی باشد. مغایرت یعنی راهبند بسته
 *      می‌ماند؛ نه هشدار، نه «ادامه بده».
 */
class GateController extends Controller
{
    use WorksOnOneWaybill;

    /** با چه چیزی QR خوانده شد */
    private const SCAN_SOURCES = ['camera', 'barcode'];

    /** چند خواندنِ اخیرِ دوربین به صفحه داده می‌شود */
    private const RECENT_READINGS = 5;

    public function __construct(
        private readonly SecurityLogger $security,
        private readonly PlateVerifier $plates,
        private readonly PlateCapture $capture,
        private readonly GateDevices $devices,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeGate($request);

        return $this->result($request, $this->currentWaybill($request), $this->stationNotice($request));
    }

    /** اعتبارسنجی توکن QR */
    public function scan(Request $request): RedirectResponse
    {
        $this->authorizeGate($request);

        $token = $request->string('token')->toString();
        $parsed = QrToken::parse($token);

        if ($parsed === null) {
            $this->security->log(SecurityLogger::QR_INVALID, null, context: ['reason' => 'bad_token'], request: $request);

            return $this->toStation()->with('station_notice', 'کد QR معتبر نیست یا منقضی شده است.');
        }

        $appointment = Appointment::where('ulid', $parsed['ulid'])->first();

        if ($appointment === null || $appointment->factory_id !== $this->factory($request)->id) {
            $this->security->log(SecurityLogger::QR_INVALID, $parsed['ulid'], context: ['reason' => 'not_found'], request: $request);

            return $this->toStation()->with('station_notice', 'نوبتی با این کد پیدا نشد.');
        }

        // توکن باید همانی باشد که آخرین بار برای این نوبت صادر شده
        if (! QrToken::matches($appointment, $token)) {
            $this->security->log(SecurityLogger::QR_REPLAY, $appointment->ulid, $appointment, request: $request);

            return $this->toStation($appointment)
                ->with('station_notice', 'این کد دیگر معتبر نیست. راننده باید کد را از برنامه دوباره باز کند.');
        }

        ScanTicket::issue($request, $appointment, $this->scanSource($request));

        return $this->toStation($appointment);
    }

    /**
     * جستجوی پلاک — فقط برای دیدنِ وضعیت.
     *
     * نتیجه‌ی این مسیر بلیط اسکن صادر نمی‌کند، پس دکمه‌ی ورود روی آن خاموش
     * است. این عمدی است: راهی که «وقتی QR خوانده نمی‌شود» باز گذاشته شود،
     * همان راهی است که کامیونِ بی‌حواله از آن وارد می‌شود.
     */
    public function lookup(PlateLookupRequest $request): RedirectResponse
    {
        $plate = $request->plate();

        if ($plate === null) {
            return $this->toStation()->with('station_notice', 'شماره پلاک معتبر نیست.');
        }

        $appointment = Appointment::whereHas('truck', fn ($q) => $q->where('plate_key', $plate->key()))
            ->where('factory_id', $this->factory($request)->id)
            ->whereDate('date', CarbonImmutable::today()->toDateString())
            ->active()
            ->queueOrder()
            ->first();

        if ($appointment === null) {
            return $this->toStation()->with('station_notice', 'برای این پلاک نوبت فعالی در امروز ثبت نشده است.');
        }

        return $this->toStation($appointment);
    }

    /**
     * ثبت ورود کامیون به محوطه.
     *
     * سه دروازه پشت سر هم؛ رد شدن از هرکدام یعنی راهبند بسته می‌ماند:
     * دسترسی، اسکن QR (یا استثنای ثبت‌شده)، و تطبیق پلاک.
     */
    public function checkIn(
        GateCheckInRequest $request,
        Appointment $appointment,
        TransitionAppointment $transition,
    ): RedirectResponse {
        if ($request->user()->cannot('transition', [$appointment, AppointmentStatus::CheckedIn])) {
            return $this->toStation($appointment)->with('error', 'برای ثبت ورود دسترسی ندارید.');
        }

        $scanned = ScanTicket::isValid($request, $appointment);
        $reason = $request->string('override_reason')->trim()->toString();

        if (! $scanned) {
            $mayOverride = $request->user()->can(Permissions::GATE_MANUAL_OVERRIDE);

            $this->security->log(
                SecurityLogger::GATE_NO_QR,
                $appointment->ulid,
                $appointment,
                ['allowed' => $mayOverride && $reason !== '', 'reason' => $reason ?: null],
                $request,
            );

            if (! $mayOverride) {
                return $this->toStation($appointment)->with(
                    'error',
                    'ورود فقط با اسکن QR ثبت می‌شود. اگر کد راننده خوانده نمی‌شود، با مسئول شیفت تماس بگیرید.',
                );
            }

            if ($reason === '') {
                return $this->toStation($appointment)->with('error', 'برای ثبت ورود بدون اسکن، نوشتن دلیل الزامی است.');
            }
        }

        $verdict = $this->plates->verify(
            $appointment,
            $this->readingFor($request, $appointment),
            $request->string('observed_plate')->trim()->toString() ?: null,
            $request->boolean('plate_match'),
        );

        if (! $verdict->passed) {
            $this->security->log(
                SecurityLogger::GATE_PLATE_MISMATCH,
                $appointment->ulid,
                $appointment,
                $verdict->context(),
                $request,
            );

            return $this->toStation($appointment)->with('error', $verdict->message);
        }

        $scanSource = ScanTicket::source($request, $appointment);

        try {
            $transition(
                $appointment,
                AppointmentStatus::CheckedIn,
                Actor::user($request->user(), $request->ip()),
            );
        } catch (InvalidStateTransition|TransitionBlocked $e) {
            return $this->toStation($appointment)->with('error', $e->getMessage());
        }

        $appointment->forceFill([
            'qr_used_at' => now(),
            'gate_entry_method' => $scanned ? GateEntry::QR : GateEntry::MANUAL,
            'gate_scan_source' => $scanned ? $scanSource : null,
            // پلاکی که واقعاً دیده شد، نه پلاکی که در حواله نوشته بود. وقتی
            // دستگاه خوانده، این دو یکی‌اند؛ ارزشش وقتی معلوم می‌شود که
            // نباشند — و آن‌وقت اصلاً به اینجا نمی‌رسیم.
            'gate_observed_plate' => $verdict->observed ?? $appointment->truck?->plate_key,
            'gate_plate_source' => $verdict->source,
            'gate_plate_reading_id' => $verdict->reading?->id,
            'gate_override_reason' => $scanned ? null : $reason,
            'gate_override_by_user_id' => $scanned ? null : $request->user()->id,
        ])->save();

        // خواندنِ دوربین به همین نوبت گره می‌خورد تا عکس در سابقه پیدا شود
        $verdict->reading?->forceFill(['appointment_id' => $appointment->id])->save();

        ScanTicket::consume($request, $appointment);

        return $this->stationDone($appointment)
            ->with('success', 'ورود نوبت '.$appointment->number.' ثبت شد.');
    }

    /**
     * ثبت عکس پلاک از ایستگاه نگهبانی.
     *
     * جدا از ثبت ورود است و عمداً: عکس باید بماند حتی وقتی راهبند باز
     * نمی‌شود. کامیونی که برگردانده شده، دقیقاً همانی است که بعداً کسی
     * می‌پرسد «مگر نیامده بود؟».
     */
    public function capture(CapturePlateRequest $request): JsonResponse
    {
        $factory = $this->factory($request);

        $reading = $this->capture->record(
            factory: $factory,
            source: PlateReading::SOURCE_STATION,
            rawPlate: $request->string('plate')->trim()->toString() ?: null,
            image: $request->file('image'),
            capturedBy: $request->user(),
        );

        // عکسی که هنگام بررسی یک نوبت گرفته شده، همان‌جا گره می‌خورد — حتی
        // اگر راهبند باز نشود. کامیونی که برگردانده شده دقیقاً همانی است که
        // بعداً کسی می‌پرسد «مگر نیامده بود؟»، و پلاکِ مغایر همان چیزی است
        // که حراست می‌خواهد ببیند.
        $ulid = $request->string('appointment')->toString();

        if ($ulid !== '') {
            $appointment = Appointment::where('ulid', $ulid)
                ->where('factory_id', $factory->id)
                ->first();

            $reading->forceFill(['appointment_id' => $appointment?->id])->save();
        }

        return response()->json(AppointmentResource::reading($reading));
    }

    /**
     * تازه‌ترین خواندن‌های دوربین.
     *
     * WebSocket راه اصلی است، ولی ایستگاه نگهبانی جایی است که وای‌فای
     * ضعیف است و صفحه ساعت‌ها باز می‌ماند. این مسیر همان پشتیبانِ polling
     * است که وقتی اتصال زنده قطع شود، دوربین را بی‌مصرف نمی‌گذارد.
     */
    public function readings(Request $request): JsonResponse
    {
        $this->authorizeGate($request);

        return response()->json([
            'readings' => $this->recentReadings($request),
        ]);
    }

    /**
     * عکس پلاک.
     *
     * روی دیسک خصوصی است و از این مسیر سرو می‌شود، نه با لینک مستقیم:
     * عکسِ هر خودرویی که از جلوی دوربین رد شده، داده‌ی نظارتی است و نباید
     * با دانستنِ آدرس، برای همه قابل دیدن باشد.
     */
    public function readingImage(Request $request, PlateReading $reading): StreamedResponse
    {
        abort_unless(
            $request->user()?->can(Permissions::QUEUE_CHECKIN)
                || $request->user()?->can(Permissions::APPOINTMENTS_VIEW),
            403,
        );

        abort_unless($reading->factory_id === $this->factory($request)->id, 404);
        abort_if($reading->image_path === null, 404);

        $disk = Storage::disk($reading->image_disk ?? PlateCapture::DISK);

        abort_unless($disk->exists($reading->image_path), 404);

        return $disk->response($reading->image_path);
    }

    /**
     * خواندنی که مرورگر به آن اشاره کرده.
     *
     * مرورگر فقط شناسه می‌فرستد و هرگز «مطابق است». تطبیق را سرور از روی
     * همان ردیف انجام می‌دهد، وگرنه کافی بود کسی در درخواست بنویسد پلاک
     * تأیید شده و کل زنجیره‌ی دوربین تبدیل شود به یک فیلد قابل تایپ.
     */
    private function readingFor(GateCheckInRequest $request, Appointment $appointment): ?PlateReading
    {
        $id = $request->integer('plate_reading_id');

        if ($id <= 0) {
            return null;
        }

        return PlateReading::find($id);
    }

    private function scanSource(Request $request): string
    {
        $source = $request->string('source')->toString();

        return in_array($source, self::SCAN_SOURCES, true) ? $source : ScanTicket::SOURCE_CAMERA;
    }

    /** @return array<int, array<string, mixed>> */
    private function recentReadings(Request $request): array
    {
        if (! $this->devices->anprEnabled()) {
            return [];
        }

        return PlateReading::where('factory_id', $this->factory($request)->id)
            ->where('source', PlateReading::SOURCE_ANPR)
            ->fresh()
            ->latest('captured_at')
            ->limit(self::RECENT_READINGS)
            ->get()
            ->map(fn (PlateReading $reading) => AppointmentResource::reading($reading))
            ->all();
    }

    private function result(
        Request $request,
        ?Appointment $appointment,
        ?string $error = null,
    ): Response {
        // «اسکن شده» را از همان بلیطی می‌خوانیم که خودِ check-in هم می‌خواند.
        // پیش از این یک پارامتر جدا بود و می‌توانست با واقعیتِ سرور اختلاف
        // پیدا کند — یعنی دکمه‌ای که خاموش است ولی سرور قبولش می‌کند.
        $scanned = $appointment !== null && ScanTicket::isValid($request, $appointment);

        $appointment?->load(['driver', 'truck.truckType', 'product']);

        $factory = $this->factory($request);

        return Inertia::render('Staff/Gate/Index', [
            'factoryId' => $factory->id,
            'onSiteCount' => Appointment::where('factory_id', $factory->id)
                ->whereDate('date', CarbonImmutable::today()->toDateString())
                ->onSite()
                ->count(),
            'devices' => [
                'barcode' => $this->devices->barcodeEnabled(),
                'station_camera' => $this->devices->stationCameraEnabled(),
                'anpr' => $this->devices->anprEnabled(),
            ],
            'readings' => $this->recentReadings($request),
            'result' => $appointment === null && $error === null ? null : [
                'error' => $error,
                'appointment' => $appointment
                    ? array_merge((new AppointmentResource($appointment))->resolve($request), [
                        'can_check_in' => $error === null
                            && $request->user()->can('transition', [$appointment, AppointmentStatus::CheckedIn]),
                        // بدون اسکن، دکمه‌ی ورود فقط برای دارندگان استثنا باز است
                        'scanned' => $scanned,
                        'needs_override' => ! $scanned,
                        'may_override' => $request->user()->can(Permissions::GATE_MANUAL_OVERRIDE),
                        'expected_plate' => $appointment->truck?->plate(),
                        'is_today' => $appointment->date->isToday(),
                    ])
                    : null,
            ],
        ]);
    }

    private function stationRoute(): string
    {
        return 'staff.gate.index';
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function authorizeGate(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::QUEUE_CHECKIN), 403, 'برای این بخش دسترسی ندارید.');
    }
}
