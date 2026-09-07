<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Domain\Sms\SmsManager;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * رفتار پنل‌ها عمداً همان payroll-saas است؛ این تست‌ها همان رفتار را قفل می‌کنند.
 */
final class SmsManagerTest extends TestCase
{
    use RefreshDatabase;

    private SmsManager $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = app(SmsManager::class);
    }

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return array_merge(Setting::DEFAULTS, ['sms_enabled' => '1'], $overrides);
    }

    /** @return array<string, mixed> */
    private function afeSettings(array $overrides = []): array
    {
        return $this->settings(array_merge([
            'sms_provider' => 'afe',
            'sms_username' => 'u',
            'sms_password' => 'p',
            'sms_sender' => '3000',
        ], $overrides));
    }

    #[Test]
    public function it_refuses_to_send_when_sms_is_switched_off(): void
    {
        Http::fake();

        [$status, $detail] = $this->sms->send(
            $this->settings(['sms_enabled' => '0']),
            '09123456789',
            'سلام',
        );

        $this->assertSame(SmsManager::STATUS_DISABLED, $status);
        $this->assertStringContainsString('غیرفعال', $detail);
        Http::assertNothingSent();
    }

    #[Test]
    public function the_afe_panel_receives_the_documented_query_string(): void
    {
        Http::fake(['*afe.ir*' => Http::response('12345', 200)]);

        [$status] = $this->sms->send($this->settings([
            'sms_provider' => 'afe',
            'sms_username' => 'user',
            'sms_password' => 'pass',
            'sms_sender' => '3000123',
        ]), '09123456789', 'متن پیام');

        $this->assertSame(SmsManager::STATUS_SENT, $status);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/Url/SendSMS.aspx')
                && $query['Username'] === 'user'
                && $query['Password'] === 'pass'
                && $query['Number'] === '3000123'
                && $query['Mobile'] === '09123456789'
                && $query['SMS'] === 'متن پیام';
        });
    }

    /** پنل افه شناسه‌ی پیام را برمی‌گرداند؛ عدد یعنی موفق */
    #[Test]
    public function an_afe_numeric_body_means_success(): void
    {
        Http::fake(['*' => Http::response('9876543', 200)]);

        [$status] = $this->sms->send($this->afeSettings(), '09123456789', 'x');

        $this->assertSame(SmsManager::STATUS_SENT, $status);
    }

    /** و متن یعنی خطا — با همان متن پنل، تا اپراتور بداند چه شده */
    #[Test]
    public function an_afe_text_body_means_failure_and_keeps_the_panel_wording(): void
    {
        Http::fake(['*' => Http::response('Invalid Username Or Password', 200)]);

        [$status, $detail] = $this->sms->send($this->afeSettings(), '09123456789', 'x');

        $this->assertSame(SmsManager::STATUS_FAILED, $status);
        $this->assertStringContainsString('Invalid Username', $detail);
    }

    #[Test]
    public function send_successfully_in_the_body_also_counts_as_sent(): void
    {
        Http::fake(['*' => Http::response('Send Successfully', 200)]);

        [$status] = $this->sms->send($this->settings([
            'sms_provider' => 'afe', 'sms_username' => 'u', 'sms_password' => 'p', 'sms_sender' => '3000',
        ]), '09123456789', 'x');

        $this->assertSame(SmsManager::STATUS_SENT, $status);
    }

    #[Test]
    public function a_custom_afe_domain_is_tried_before_the_defaults(): void
    {
        Http::fake(['*' => Http::response('555', 200)]);

        $this->sms->send($this->settings([
            'sms_provider' => 'afe',
            'sms_afe_domain' => 'panel.example.ir',
            'sms_username' => 'u', 'sms_password' => 'p', 'sms_sender' => '3000',
        ]), '09123456789', 'x');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'www.panel.example.ir/Url/SendSMS.aspx'));
    }

    #[Test]
    public function kavenegar_status_200_means_sent(): void
    {
        Http::fake(['*kavenegar*' => Http::response(['return' => ['status' => 200]], 200)]);

        [$status] = $this->sms->send(
            $this->settings(['sms_provider' => 'kavenegar', 'sms_api_key' => 'KEY']),
            '09123456789',
            'x',
        );

        $this->assertSame(SmsManager::STATUS_SENT, $status);
    }

    #[Test]
    public function a_kavenegar_error_keeps_the_panel_message(): void
    {
        Http::fake(['*kavenegar*' => Http::response(['return' => ['status' => 411, 'message' => 'گیرنده نامعتبر']], 200)]);

        [$status, $detail] = $this->sms->send(
            $this->settings(['sms_provider' => 'kavenegar', 'sms_api_key' => 'KEY']),
            '09123456789',
            'x',
        );

        $this->assertSame(SmsManager::STATUS_FAILED, $status);
        $this->assertStringContainsString('گیرنده نامعتبر', $detail);
    }

    #[Test]
    public function smsir_and_melipayamak_speak_their_own_envelopes(): void
    {
        Http::fake(['*sms.ir*' => Http::response(['status' => 1], 200)]);

        [$status] = $this->sms->send(
            $this->settings(['sms_provider' => 'smsir', 'sms_api_key' => 'KEY', 'sms_sender' => '3000']),
            '09123456789',
            'x',
        );
        $this->assertSame(SmsManager::STATUS_SENT, $status);

        Http::fake(['*payamak-panel*' => Http::response(['RetStatus' => 1], 200)]);

        [$status] = $this->sms->send(
            $this->settings(['sms_provider' => 'melipayamak', 'sms_username' => 'u', 'sms_password' => 'p']),
            '09123456789',
            'x',
        );
        $this->assertSame(SmsManager::STATUS_SENT, $status);
    }

    #[Test]
    public function a_custom_panel_url_gets_its_placeholders_filled(): void
    {
        Http::fake(['*' => Http::response('OK', 200)]);

        [$status] = $this->sms->send($this->settings([
            'sms_provider' => 'custom',
            'sms_custom_url' => 'https://panel.example.com/send?to={to}&text={text}&key={apikey}',
            'sms_api_key' => 'SECRET',
        ]), '09123456789', 'سلام');

        $this->assertSame(SmsManager::STATUS_SENT, $status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'to=09123456789')
                && str_contains($request->url(), 'key=SECRET')
                && ! str_contains($request->url(), '{');
        });
    }

    /**
     * رگرسیون: گزینه‌ی query در Guzzle جایگزین کوئری خودِ URL می‌شود، پس
     * فرستادن آرایه‌ی خالی، پارامترهای پنل سفارشی را کامل پاک می‌کرد.
     */
    #[Test]
    public function a_custom_url_keeps_every_parameter_it_was_given(): void
    {
        Http::fake(['*' => Http::response('OK', 200)]);

        $this->sms->send($this->settings([
            'sms_provider' => 'custom',
            'sms_custom_url' => 'https://panel.example.com/send?to={to}&text={text}&from={from}&key={apikey}',
            'sms_api_key' => 'SECRET',
            'sms_sender' => '3000',
        ]), '09123456789', 'سلام');

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query === [
                'to' => '09123456789',
                'text' => 'سلام',
                'from' => '3000',
                'key' => 'SECRET',
            ];
        });
    }

    /**
     * رگرسیون: post() از bodyFormat پیش‌فرض (json) استفاده می‌کند، پس بدنه‌ی
     * رشته‌ای را JSON-encode می‌کرد و پنل «"to=..."» با گیومه می‌گرفت.
     */
    #[Test]
    public function a_custom_post_panel_gets_a_clean_form_body(): void
    {
        Http::fake(['*' => Http::response('OK', 200)]);

        $this->sms->send($this->settings([
            'sms_provider' => 'custom',
            'sms_custom_method' => 'POST',
            'sms_custom_url' => 'https://panel.example.com/send?to={to}&text={text}',
        ]), '09123456789', 'سلام');

        Http::assertSent(function ($request) {
            $body = $request->body();

            $this->assertStringNotContainsString('"', $body);
            parse_str($body, $fields);

            return $fields === ['to' => '09123456789', 'text' => 'سلام']
                && $request->header('Content-Type') === ['application/x-www-form-urlencoded'];
        });
    }

    #[Test]
    public function it_refuses_to_call_an_internal_address(): void
    {
        // محافظ SSRF: پنل «سفارشی» نباید بتواند به شبکه‌ی داخلی وصل شود
        [$status, $detail] = $this->sms->send($this->settings([
            'sms_provider' => 'custom',
            'sms_custom_url' => 'http://127.0.0.1:6379/send?to={to}',
        ]), '09123456789', 'x');

        $this->assertSame(SmsManager::STATUS_FAILED, $status);
        $this->assertStringContainsString('داخلی', $detail);
    }

    #[Test]
    public function a_missing_credential_is_reported_as_configuration_not_as_a_send_failure(): void
    {
        $this->assertNotNull($this->sms->configurationError($this->settings(['sms_provider' => 'afe'])));
        $this->assertNotNull($this->sms->configurationError($this->settings(['sms_provider' => 'kavenegar'])));
        $this->assertNull($this->sms->configurationError($this->settings(['sms_provider' => 'console'])));

        $this->assertNull($this->sms->configurationError($this->settings([
            'sms_provider' => 'afe', 'sms_username' => 'u', 'sms_password' => 'p', 'sms_sender' => '3000',
        ])));
    }

    #[Test]
    public function the_console_provider_writes_to_a_log_instead_of_the_network(): void
    {
        Http::fake();

        [$status] = $this->sms->send($this->settings(['sms_provider' => 'console']), '09123456789', 'متن آزمایشی');

        $this->assertSame(SmsManager::STATUS_SENT, $status);
        Http::assertNothingSent();
        $this->assertStringContainsString('متن آزمایشی', file_get_contents(storage_path('logs/sms-test.log')));
    }
}
