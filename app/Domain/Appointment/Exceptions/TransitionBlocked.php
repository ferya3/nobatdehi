<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Exceptions;

use RuntimeException;

/**
 * انتقال از نظر ماشین وضعیت مجاز است، ولی شرطِ فیزیکیِ آن برقرار نیست:
 * بارگیری بدون توزینِ خالی، یا خروج بدون توزینِ پر.
 *
 * جدا از InvalidStateTransition است چون پیامش به اپراتور فرق دارد؛ آنجا
 * «نمی‌شود»، اینجا «هنوز نه — اول این کار».
 */
final class TransitionBlocked extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
