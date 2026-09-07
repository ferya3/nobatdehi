<?php

declare(strict_types=1);

namespace App\Domain\Appointment;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Models\Appointment;

/**
 * شرط‌های فیزیکیِ هر مرحله — همان ترتیبی که کامیون در محوطه طی می‌کند.
 *
 * ماشین وضعیت می‌گوید کدام انتقال «شکل درستی» دارد؛ اینجا می‌گوید آیا در
 * دنیای واقعی هم اتفاق افتاده. بدون این، اپراتور می‌توانست بارگیری را بدون
 * توزینِ خالی شروع کند و آن‌وقت وزن خالص هیچ‌وقت قابل اثبات نبود.
 */
final class TransitionPreconditions
{
    public function assert(Appointment $appointment, AppointmentStatus $to): void
    {
        match ($to) {
            AppointmentStatus::Loading => $this->assertTareWeighed($appointment),
            AppointmentStatus::Completed => $this->assertExitCleared($appointment),
            default => null,
        };
    }

    /** باسکول اول قبل از بارگیری */
    private function assertTareWeighed(Appointment $appointment): void
    {
        $record = $appointment->loadingRecord;

        if ($record?->empty_weight_kg === null) {
            throw TransitionBlocked::because(
                'اول باید وزن خالی روی باسکول ثبت شود؛ بدون آن وزن خالص قابل اثبات نیست.',
            );
        }
    }

    /** باسکول دوم و برگه‌ی خروج قبل از تکمیل */
    private function assertExitCleared(Appointment $appointment): void
    {
        $record = $appointment->loadingRecord;

        if ($record?->loaded_weight_kg === null) {
            throw TransitionBlocked::because('اول باید وزن پر روی باسکول دوم ثبت شود.');
        }

        if ($record->exit_permit_number === null) {
            throw TransitionBlocked::because(
                'برگه‌ی خروج صادر نشده است. تا رفع مغایرت وزن، خروج ثبت نمی‌شود.',
            );
        }
    }
}
