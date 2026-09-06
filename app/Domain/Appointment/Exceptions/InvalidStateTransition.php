<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Exceptions;

use App\Domain\Appointment\Enums\AppointmentStatus;
use RuntimeException;

final class InvalidStateTransition extends RuntimeException
{
    public static function between(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self(sprintf(
            'انتقال از وضعیت «%s» به «%s» مجاز نیست.',
            $from->label(),
            $to->label(),
        ));
    }
}
