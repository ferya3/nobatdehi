<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * سامانه اغلب روی http و با آی‌پیِ سرور باز می‌شود (بدون گواهی).
 * در آن حالت مرورگر APIهای «secure context only» را اصلاً تعریف نمی‌کند و
 * صدا زدنشان داخل setup یک کامپوننت، کل صفحه را سفید می‌کند.
 * پس استفاده‌ی مستقیم از آن‌ها در کد صفحه‌ها ممنوع است.
 */
final class SecureContextApiTest extends TestCase
{
    /** APIهایی که فقط در https/localhost وجود دارند و جایگزین امنشان */
    private const FORBIDDEN = [
        'crypto.randomUUID(' => 'به جایش uuid() از resources/js/lib/uuid.ts را import کنید.',
        'crypto.subtle' => 'در secure context نیست؛ رمزنگاری را به سمت سرور ببرید.',
        'navigator.clipboard' => 'اول با «\'clipboard\' in navigator» بررسی کنید.',
    ];

    #[Test]
    public function test_page_code_never_calls_secure_context_only_apis_directly(): void
    {
        $root = resource_path('js');
        // تنها جاهایی که اجازه دارند مستقیم سراغ این APIها بروند: خودِ
        // پوشش‌دهنده‌هایشان، که همان‌جا هم feature check و جایگزین دارند.
        $allowed = [
            resource_path('js/lib/uuid.ts'),
            resource_path('js/lib/clipboard.ts'),
        ];

        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'vue'], true)) {
                continue;
            }

            if (in_array($file->getPathname(), $allowed, true)) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN as $api => $hint) {
                if (str_contains($code, $api)) {
                    $relative = str_replace(base_path().'/', '', $file->getPathname());
                    $offenders[] = "{$relative}: «{$api}» — {$hint}";
                }
            }
        }

        $this->assertSame([], $offenders, "استفاده‌ی مستقیم از API مخصوص secure context:\n".implode("\n", $offenders));
    }
}
