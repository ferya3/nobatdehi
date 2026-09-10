<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Domain\Access\Roles;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * دو چیزی که یک بار بی‌صدا از دست رفتند.
 *
 * منوی پنل در Vue از روی دسترسی‌های کاربر ساخته می‌شود، پس تست HTTP آن را
 * نمی‌بیند. قبلاً لینکِ «مدیریت صف» فقط وقتی به منو اضافه می‌شد که کاربر
 * داشبورد *نداشته* باشد — و اپراتور هر دو را دارد. نتیجه این بود که
 * اپراتور هیچ راهی به صفحه‌ی مدیریت صف نمی‌دید، در حالی که صفحه وجود داشت
 * و دسترسی‌اش را هم داشت.
 *
 * فاصله‌ی تازه‌سازی هم همین‌طور: عددی که در یک refactor به‌راحتی بزرگ
 * می‌شود و کسی تا وقتی اپراتور نگوید «نوبت‌ها دیر می‌آیند» متوجه نمی‌شود.
 */
final class QueuePageIsReachableTest extends TestCase
{
    #[Test]
    public function the_main_menu_always_offers_the_queue_page(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/StaffLayout.vue'));

        $primary = $this->between($layout, 'const PRIMARY = [', '];');

        $this->assertStringContainsString(
            "'staff.queue.index'",
            $primary,
            'لینک مدیریت صف باید در منوی اصلی باشد، نه مشروط به نداشتن داشبورد.',
        );

        $this->assertStringContainsString(
            "'queue.view'",
            $primary,
            'لینک صف باید با دسترسی queue.view کنترل شود.',
        );
    }

    #[Test]
    public function the_queue_page_refreshes_every_five_seconds(): void
    {
        $page = file_get_contents(resource_path('js/pages/Staff/Queue/Index.vue'));

        $this->assertMatchesRegularExpression(
            '/const REFRESH_MS = 5_?000;/',
            $page,
            'صف باید هر ۵ ثانیه تازه شود تا نوبت تازه سریع دیده شود.',
        );

        // WebSocket نباید دوباره فاصله را طولانی کند: وقتی Reverb بالا نباشد
        // یا رویدادی گم شود، همان polling تنها چیزی است که باقی می‌ماند.
        $this->assertStringNotContainsString(
            'isLive ? 120_000',
            $page,
            'اتصال زنده نباید فاصله‌ی تازه‌سازی را طولانی کند.',
        );
    }

    #[Test]
    public function the_operator_holds_both_dashboard_and_queue_permissions(): void
    {
        // همان ترکیبی که باعث شد لینک صف پنهان بماند
        $operator = Roles::matrix()[Roles::OPERATOR];

        $this->assertContains('dashboard.view', $operator);
        $this->assertContains('queue.view', $operator);
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);

        $this->assertNotFalse($from, "بلوک «{$start}» پیدا نشد.");

        $to = strpos($haystack, $end, $from);

        $this->assertNotFalse($to, "پایان بلوک «{$start}» پیدا نشد.");

        return substr($haystack, $from, $to - $from);
    }
}
