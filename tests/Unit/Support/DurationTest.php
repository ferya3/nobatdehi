<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    /** @return array<string, array{0: ?int, 1: string}> */
    public static function cases(): array
    {
        return [
            'خالی' => [null, ''],
            'صفر' => [0, '۰ دقیقه'],
            'کمتر از یک ساعت' => [30, '۳۰ دقیقه'],
            'دقیقاً یک ساعت' => [60, '۱ ساعت'],
            'یک ساعت و خرده‌ای' => [80, '۱ ساعت و ۲۰ دقیقه'],
            'دو ساعت گرد' => [120, '۲ ساعت'],
            'منفی مثل صفر رفتار می‌کند' => [-5, '۰ دقیقه'],
        ];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_reads_the_way_a_driver_would_say_it(?int $minutes, string $expected): void
    {
        $this->assertSame($expected, Duration::human($minutes));
    }
}
