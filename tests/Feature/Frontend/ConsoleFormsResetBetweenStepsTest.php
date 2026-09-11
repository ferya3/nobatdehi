<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * عددِ یک مرحله نباید در مرحله‌ی بعد زنده بماند.
 *
 * کنسول همان صفحه می‌ماند و کامپوننتش دوباره ساخته نمی‌شود، پس هر useForm
 * بین قدم‌ها زنده است. یک بار همین اتفاق افتاد: اپراتور وزن خالی را می‌زد،
 * بارگیری تمام می‌شد، و در «توزین پر» همان وزن خالی از قبل در جعبه نشسته
 * بود — اگر ثبتش می‌کرد سرور ردش می‌کرد و اگر رویش تایپ می‌کرد، عددی
 * می‌ساخت که هیچ باسکولی نگفته بود.
 *
 * پاک‌کردنِ فرم‌ها با عوض شدنِ کامیون کافی نیست: کامیون همان است و قدمش
 * عوض شده.
 */
final class ConsoleFormsResetBetweenStepsTest extends TestCase
{
    #[Test]
    public function the_console_clears_its_forms_when_the_step_changes_not_only_the_truck(): void
    {
        $code = (string) file_get_contents(resource_path('js/pages/Staff/Console.vue'));

        // بدنه‌ی watch ای که فرم‌ها را پاک می‌کند
        $found = preg_match('/watch\(\s*\(\)\s*=>\s*(.+?),\s*\(\)\s*=>\s*\{(.*?)\},\s*\);/s', $code, $m);

        $this->assertSame(1, $found, 'در کنسول watch ای برای پاک‌کردن فرم‌ها پیدا نشد.');

        [$source, $body] = [$m[1], $m[2]];

        $this->assertStringContainsString('.reset()', $body, 'این watch فرمی را پاک نمی‌کند.');

        $this->assertStringContainsString(
            'next',
            $source,
            'فرم‌ها فقط با عوض شدن کامیون پاک می‌شوند. قدمِ بعدیِ همان کامیون هم '
            .'باید جعبه‌ها را خالی کند — وگرنه وزن خالی تا توزین پر زنده می‌ماند.',
        );

        // و هر فرمی که روی صفحه هست باید در همان watch پاک شود
        preg_match_all('/const (\w+) = useForm\(/', $code, $forms);

        foreach ($forms[1] as $form) {
            $this->assertStringContainsString(
                "{$form}.reset()",
                $body,
                "فرم «{$form}» بین قدم‌ها پاک نمی‌شود.",
            );
        }
    }
}
