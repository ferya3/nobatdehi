<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\TrustedProxies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TrustedProxiesTest extends TestCase
{
    #[Test]
    public function test_an_empty_setting_trusts_only_this_machine(): void
    {
        $this->assertSame(TrustedProxies::LOCAL, TrustedProxies::from(null));
        $this->assertSame(TrustedProxies::LOCAL, TrustedProxies::from(''));
        $this->assertSame(TrustedProxies::LOCAL, TrustedProxies::from('   '));
    }

    #[Test]
    public function test_a_comma_list_becomes_a_proxy_list(): void
    {
        $this->assertSame(
            ['10.0.0.1', '10.0.0.2/24'],
            TrustedProxies::from(' 10.0.0.1 , 10.0.0.2/24 '),
        );
    }

    #[Test]
    public function test_trusting_everyone_stays_possible_but_only_on_purpose(): void
    {
        // '*' انتخابی آگاهانه است، نه چیزی که از یک مقدار خالی دربیاید
        $this->assertSame('*', TrustedProxies::from('*'));
        $this->assertNotSame('*', TrustedProxies::from(null));
    }

    #[Test]
    public function test_a_list_of_only_separators_falls_back_to_local(): void
    {
        $this->assertSame(TrustedProxies::LOCAL, TrustedProxies::from(',,, ,'));
    }
}
