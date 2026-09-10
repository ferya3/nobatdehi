<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\SecurityLogger;
use App\Domain\Weighbridge\ExitPermit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\ResolveWeightDiscrepancyRequest;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;

/**
 * تعیین تکلیفِ توزینی که با حواله نخوانده.
 *
 * قفلِ برگه‌ی خروج کارِ درستی می‌کند، ولی تا وقتی راهی برای باز کردنش نبود،
 * نتیجه‌اش کامیونی بود که در محوطه می‌ماند و کسی جز دست بردن در دیتابیس
 * کاری از دستش برنمی‌آمد.
 *
 * دو تصمیم، هر دو با نامِ تصمیم‌گیرنده و دلیلِ نوشته‌شده:
 *
 *   تأیید  — اختلاف پذیرفته می‌شود و برگه‌ی خروج صادر می‌شود. وزنِ ثبت‌شده
 *            همان می‌ماند؛ چیزی بازنویسی نمی‌شود.
 *   رد     — اختلاف پذیرفته نمی‌شود. برگه صادر نمی‌شود و کامیون باید بار را
 *            درست کند و دوباره وزن شود.
 */
class WeightDiscrepancyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SecurityLogger $security,
    ) {}

    public function store(ResolveWeightDiscrepancyRequest $request, Appointment $appointment): RedirectResponse
    {
        $record = $appointment->loadingRecord;

        if ($record === null || ! $record->awaitsDecision()) {
            return back()->with('error', 'این حواله مغایرتِ بازی ندارد.');
        }

        $approved = $request->string('decision')->toString() === 'approved';
        $reason = $request->string('reason')->trim()->toString();

        $record->forceFill([
            'discrepancy_decision' => $approved ? 'approved' : 'rejected',
            'discrepancy_decision_reason' => $reason,
            'discrepancy_decided_at' => now(),
            'discrepancy_decided_by_user_id' => $request->user()->id,
        ])->save();

        $number = null;

        if ($approved) {
            // وزن دست‌نخورده می‌ماند؛ چیزی که عوض می‌شود فقط اجازه‌ی خروج است
            $number = ExitPermit::issue($appointment, $record);
        }

        $this->audit->log(
            action: $approved ? 'APPROVE_WEIGHT_DISCREPANCY' : 'REJECT_WEIGHT_DISCREPANCY',
            entity: $record,
            newValues: [
                'kind' => $record->discrepancy_kind,
                'net_weight_kg' => $record->net_weight_kg,
                'expected_net_kg' => $record->expected_net_kg,
                'variance_kg' => $record->variance_kg,
                'reason' => $reason,
                'exit_permit_number' => $number,
            ],
            request: $request,
        );

        // پذیرفتنِ یک اختلاف یعنی تناژی که فروخته شده با حواله نمی‌خواند.
        // هر بارش باید بعداً قابل شمردن باشد، نه فقط قابل حدس زدن.
        if ($approved) {
            $this->security->log(
                SecurityLogger::ADMIN_ACTION,
                $appointment->ulid,
                $appointment,
                [
                    'at' => 'weighbridge',
                    'action' => 'approve_discrepancy',
                    'kind' => $record->discrepancy_kind,
                    'variance_kg' => $record->variance_kg,
                    'reason' => $reason,
                ],
                $request,
            );
        }

        return back()->with(
            'success',
            $approved
                ? "مغایرت تأیید شد و برگه خروج {$number} صادر شد."
                : 'مغایرت رد شد. کامیون باید بار را اصلاح کند و دوباره وزن شود.',
        );
    }
}
