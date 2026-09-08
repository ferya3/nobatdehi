<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * پیوندِ دامنه به برنامه‌ی اندروید.
 *
 * اگر این فایل درست نباشد، لینکِ پیامک در مرورگر باز می‌شود و راننده باید
 * دوباره وارد شود — بی‌آنکه خطایی جایی دیده شود.
 */
final class AssetLinksTest extends TestCase
{
    #[Test]
    public function it_publishes_the_fingerprint_when_one_is_configured(): void
    {
        config()->set('android.fingerprints', ['AA:BB:CC']);
        config()->set('android.package', 'ir.nobatdehi.driver');

        $this->get('/.well-known/assetlinks.json')
            ->assertOk()
            ->assertJsonPath('0.target.package_name', 'ir.nobatdehi.driver')
            ->assertJsonPath('0.target.sha256_cert_fingerprints.0', 'AA:BB:CC')
            ->assertJsonPath('0.relation.0', 'delegate_permission/common.handle_all_urls');
    }

    /** بدون اثر انگشت، آرایه‌ی خالی — نه خطا، نه پیوندِ نادرست */
    #[Test]
    public function it_stays_empty_until_a_fingerprint_is_set(): void
    {
        config()->set('android.fingerprints', []);

        $this->get('/.well-known/assetlinks.json')
            ->assertOk()
            ->assertExactJson([]);
    }
}
