<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DriverLoginFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);
        config()->set('otp.expose_in_response', true);
    }

    #[Test]
    public function a_driver_can_log_in_end_to_end(): void
    {
        $this->get(route('driver.login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Driver/Auth/Login'));

        $response = $this->post(route('driver.otp.request'), ['mobile' => '۰۹۱۲۳۴۵۶۷۸۹'])
            ->assertRedirect(route('driver.otp.verify.show'))
            ->assertSessionHas('otp');

        $code = $response->getSession()->get('otp')['dev_code'];

        $this->post(route('driver.otp.verify'), ['mobile' => '09123456789', 'code' => $code])
            ->assertRedirect(route('driver.home'));

        $this->assertAuthenticatedAs(Driver::firstOrFail(), 'driver');
    }

    #[Test]
    public function a_returning_driver_can_log_in_again(): void
    {
        // راننده‌ای که از قبل در دیتابیس هست — حالت رایج، نه استثنا.
        // این مسیر با مسیر «راننده‌ی تازه» فرق دارد: مدل از دیتابیس خوانده
        // می‌شود و wasRecentlyCreated نیست، پس دسترسی به صفت‌های نبوده
        // (مثل password که راننده اصلاً ندارد) خطا می‌دهد.
        $driver = Driver::create([
            'mobile' => '09123456789',
            'name' => 'راننده قدیمی',
            'mobile_verified_at' => now(),
        ]);

        $response = $this->post(route('driver.otp.request'), ['mobile' => '09123456789'])
            ->assertRedirect(route('driver.otp.verify.show'));

        $code = $response->getSession()->get('otp')['dev_code'];

        $this->post(route('driver.otp.verify'), ['mobile' => '09123456789', 'code' => $code])
            ->assertRedirect(route('driver.home'));

        $this->assertAuthenticatedAs($driver->fresh(), 'driver');
        $this->assertSame(1, Driver::count(), 'راننده‌ی موجود نباید دوباره ساخته شود.');

        // صفحه‌ی بعدی هم باید با همین نشست باز شود
        $this->get(route('driver.home'))->assertOk();
    }

    #[Test]
    public function a_wrong_code_returns_a_field_error_and_no_session(): void
    {
        $this->post(route('driver.otp.request'), ['mobile' => '09123456789']);

        $this->from(route('driver.otp.verify.show'))
            ->post(route('driver.otp.verify'), ['mobile' => '09123456789', 'code' => '00000'])
            ->assertRedirect(route('driver.otp.verify.show'))
            ->assertSessionHasErrors('code');

        $this->assertGuest('driver');
    }

    #[Test]
    public function the_verify_page_redirects_home_without_a_pending_request(): void
    {
        $this->get(route('driver.otp.verify.show'))->assertRedirect(route('driver.login'));
    }

    #[Test]
    public function guests_are_sent_to_the_driver_login_page(): void
    {
        $this->get(route('driver.home'))->assertRedirect(route('driver.login'));
    }

    #[Test]
    public function an_invalid_mobile_is_rejected_with_a_persian_message(): void
    {
        $this->from(route('driver.login'))
            ->post(route('driver.otp.request'), ['mobile' => '12345'])
            ->assertSessionHasErrors('mobile');
    }

    #[Test]
    public function repeated_requests_from_one_ip_are_throttled(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('driver.otp.request'), ['mobile' => '0912345678'.$i]);
        }

        $this->post(route('driver.otp.request'), ['mobile' => '09121111111'])
            ->assertStatus(429);
    }

    #[Test]
    public function a_logged_in_driver_can_log_out(): void
    {
        $driver = Driver::create(['mobile' => '09123456789'])->refresh();

        $this->actingAs($driver, 'driver')
            ->post(route('driver.logout'))
            ->assertRedirect(route('driver.login'));

        $this->assertGuest('driver');
    }
}
