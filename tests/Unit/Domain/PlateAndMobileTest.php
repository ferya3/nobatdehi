<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Truck\PlateNumber;
use App\Support\Mobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlateAndMobileTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function mobiles(): array
    {
        return [
            'استاندارد' => ['09123456789', '09123456789'],
            'بدون صفر' => ['9123456789', '09123456789'],
            'با کد کشور' => ['+989123456789', '09123456789'],
            'با صفر دوتایی' => ['00989123456789', '09123456789'],
            'ارقام فارسی' => ['۰۹۱۲۳۴۵۶۷۸۹', '09123456789'],
            'با خط تیره' => ['0912-345-6789', '09123456789'],
            'کوتاه' => ['0912345', null],
            'غیر موبایل' => ['02112345678', null],
            'خالی' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('mobiles')]
    public function it_normalises_iranian_mobile_numbers(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Mobile::normalize($input));
    }

    #[Test]
    public function it_builds_a_stable_plate_key(): void
    {
        $plate = PlateNumber::make('12', 'ب', '345', '67');

        $this->assertSame('12-ب-345-67', $plate->key());
        $this->assertStringContainsString('ایران', $plate->full());
    }

    #[Test]
    public function it_accepts_persian_digits_and_pads_short_parts(): void
    {
        $plate = PlateNumber::make('۱۲', 'ب', '۴۵', '۷');

        $this->assertSame('12-ب-045-07', $plate->key());
    }

    #[Test]
    public function it_rejects_an_invalid_plate_letter(): void
    {
        $this->assertNull(PlateNumber::tryMake('12', 'X', '345', '67'));
    }
}
