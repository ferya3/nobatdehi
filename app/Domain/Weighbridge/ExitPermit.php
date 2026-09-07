<?php

declare(strict_types=1);

namespace App\Domain\Weighbridge;

use App\Models\Appointment;
use App\Models\LoadingRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * شماره‌ی برگه‌ی خروج.
 *
 * سریالِ روزانه و پیوسته است تا نبودِ یک شماره در دفتر، خودش سؤال بسازد:
 * EX-14050617-0007. صدور داخل تراکنش و با قفلِ رکورد انجام می‌شود، وگرنه دو
 * باسکول هم‌زمان یک شماره می‌گیرند.
 */
final class ExitPermit
{
    public static function issue(Appointment $appointment, LoadingRecord $record): string
    {
        return DB::transaction(function () use ($appointment, $record) {
            $date = CarbonImmutable::today();
            $prefix = 'EX-'.$date->format('Ymd').'-';

            // قفل روی جدول برای همان روز: شماره تکراری صادر نمی‌شود
            $last = LoadingRecord::where('exit_permit_number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('exit_permit_number')
                ->value('exit_permit_number');

            $next = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

            $number = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);

            $record->forceFill([
                'exit_permit_number' => $number,
                'exit_permit_issued_at' => now(),
                'waybill_number' => $record->waybill_number ?? $appointment->number,
            ])->save();

            return $number;
        });
    }
}
