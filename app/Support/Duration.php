<?php

declare(strict_types=1);

namespace App\Support;

/**
 * مدت زمان، آن‌طور که در پیامک خوانده می‌شود.
 *
 * «۸۰ دقیقه» درست است ولی راننده باید در ذهنش تقسیم کند. «۱ ساعت و ۲۰
 * دقیقه» همان عدد است و کسی رویش مکث نمی‌کند. همتای PHPیِ duration() در
 * Frontend است تا پیامک و صفحه یک چیز بگویند.
 */
final class Duration
{
    public static function human(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        $total = max(0, $minutes);

        if ($total < 60) {
            return Digits::toPersian((string) $total).' دقیقه';
        }

        $hours = intdiv($total, 60);
        $rest = $total % 60;

        $label = Digits::toPersian((string) $hours).' ساعت';

        return $rest === 0
            ? $label
            : $label.' و '.Digits::toPersian((string) $rest).' دقیقه';
    }
}
