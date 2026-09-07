<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Domain\Access\Roles;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class SmsSettingsPanelTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
        $this->seedStaff();
    }

    private function manager(): User
    {
        return User::role(Roles::FACTORY_MANAGER)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'sms_enabled' => true,
            'sms_provider' => 'afe',
            'sms_sender' => '3000123',
            'sms_username' => 'panel-user',
            'sms_password' => 'panel-pass',
            'sms_api_key' => '',
            'sms_afe_domain' => '',
            'sms_custom_url' => '',
            'sms_custom_method' => 'GET',
            'sms_optout' => 'لغو ۱۱',
            'sms_manager_recipients' => '09121112233',
        ], $overrides);
    }

    #[Test]
    public function a_manager_can_save_the_panel_credentials(): void
    {
        $this->actingAs($this->manager())
            ->from(route('staff.settings.sms'))
            ->put(route('staff.settings.sms.update'), $this->payload())
            ->assertRedirect(route('staff.settings.sms'))
            ->assertSessionHas('success');

        $values = Setting::values();

        $this->assertSame('afe', $values['sms_provider']);
        $this->assertSame('panel-user', $values['sms_username']);
        $this->assertSame('panel-pass', $values['sms_password']);
        $this->assertSame('09121112233', $values['sms_manager_recipients']);
    }

    #[Test]
    public function the_panel_password_is_encrypted_at_rest(): void
    {
        $this->actingAs($this->manager())->put(route('staff.settings.sms.update'), $this->payload());

        $stored = Setting::query()->whereKey('sms_password')->value('value');

        $this->assertNotSame('panel-pass', $stored);
        $this->assertSame('panel-pass', Crypt::decryptString((string) $stored));
    }

    #[Test]
    public function secrets_never_reach_the_browser(): void
    {
        $this->actingAs($this->manager())->put(route('staff.settings.sms.update'), $this->payload());

        $this->actingAs($this->manager())
            ->get(route('staff.settings.sms'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/SmsSettings')
                ->where('settings.sms_password', '')
                ->where('settings.sms_password_is_set', true)
                ->where('settings.sms_username', 'panel-user'));
    }

    #[Test]
    public function an_empty_password_field_means_keep_the_current_one(): void
    {
        $this->actingAs($this->manager())->put(route('staff.settings.sms.update'), $this->payload());

        // ذخیره‌ی دوباره بدون وارد کردن رمز — نباید رمز را پاک کند
        $this->actingAs($this->manager())->put(
            route('staff.settings.sms.update'),
            $this->payload(['sms_password' => '', 'sms_sender' => '3000999']),
        );

        $values = Setting::values();

        $this->assertSame('panel-pass', $values['sms_password']);
        $this->assertSame('3000999', $values['sms_sender']);
    }

    #[Test]
    public function the_audit_log_records_the_change_without_the_secret(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->put(route('staff.settings.sms.update'), $this->payload());

        $log = \App\Models\AuditLog::where('action', 'UPDATE_SMS_SETTINGS')->latest('id')->firstOrFail();

        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame('***', $log->new_values['sms_password']);
        $this->assertSame('panel-user', $log->new_values['sms_username']);
    }

    #[Test]
    public function an_invalid_manager_number_is_rejected(): void
    {
        $this->actingAs($this->manager())
            ->from(route('staff.settings.sms'))
            ->put(route('staff.settings.sms.update'), $this->payload(['sms_manager_recipients' => '09121112233,12345']))
            ->assertSessionHasErrors('sms_manager_recipients');
    }

    #[Test]
    public function manager_numbers_are_normalised_before_saving(): void
    {
        $this->actingAs($this->manager())->put(
            route('staff.settings.sms.update'),
            $this->payload(['sms_manager_recipients' => '+989121112233, ۰۹۱۲۱۱۱۲۲۴۴']),
        );

        $this->assertSame('09121112233,09121112244', Setting::get('sms_manager_recipients'));
    }

    #[Test]
    public function a_custom_provider_without_a_url_is_rejected(): void
    {
        $this->actingAs($this->manager())
            ->from(route('staff.settings.sms'))
            ->put(route('staff.settings.sms.update'), $this->payload(['sms_provider' => 'custom', 'sms_custom_url' => '']))
            ->assertSessionHasErrors('sms_custom_url');
    }

    #[Test]
    public function the_test_message_is_sent_immediately_and_recorded(): void
    {
        Http::fake(['*' => Http::response('12345', 200)]);

        $this->actingAs($this->manager())->put(route('staff.settings.sms.update'), $this->payload());

        $this->actingAs($this->manager())
            ->from(route('staff.settings.sms'))
            ->post(route('staff.settings.sms.test'), ['mobile' => '09123456789'])
            ->assertSessionHas('success');

        $message = SmsMessage::where('template_key', 'test')->firstOrFail();

        $this->assertSame('SENT', $message->status);
        $this->assertSame('09123456789', $message->to);
    }

    #[Test]
    public function a_test_against_an_unconfigured_panel_says_what_is_missing(): void
    {
        Setting::putMany(['sms_provider' => 'kavenegar']);

        $this->actingAs($this->manager())
            ->from(route('staff.settings.sms'))
            ->post(route('staff.settings.sms.test'), ['mobile' => '09123456789'])
            ->assertSessionHas('error');

        $this->assertSame(0, SmsMessage::count());
    }

    #[Test]
    public function the_probe_reports_the_domain_that_worked(): void
    {
        Http::fake([
            '*afe.ir*' => Http::response('Invalid Username Or Password', 200),
            '*wide.ir*' => Http::response('778899', 200),
        ]);

        $this->actingAs($this->manager())->put(route('staff.settings.sms.update'), $this->payload());

        $response = $this->actingAs($this->manager())
            ->from(route('staff.settings.sms'))
            ->post(route('staff.settings.sms.probe'), ['mobile' => '09123456789']);

        $probe = $response->getSession()->get('probe');

        $this->assertSame('wide.ir', $probe['suggest']);
        $this->assertCount(2, $probe['results']);
    }

    #[Test]
    public function the_probe_hides_the_password_in_the_url_it_reports(): void
    {
        Http::fake(['*' => Http::response('123', 200)]);

        $this->actingAs($this->manager())->put(route('staff.settings.sms.update'), $this->payload());

        $probe = $this->actingAs($this->manager())
            ->post(route('staff.settings.sms.probe'), ['mobile' => '09123456789'])
            ->getSession()->get('probe');

        foreach ($probe['results'] as $row) {
            $this->assertStringNotContainsString('panel-pass', $row['url']);
            $this->assertStringContainsString('Password=******', $row['url']);
        }
    }

    #[Test]
    public function an_operator_cannot_see_or_change_the_sms_settings(): void
    {
        $operator = User::role(Roles::OPERATOR)->firstOrFail();

        $this->actingAs($operator)->get(route('staff.settings.sms'))->assertForbidden();
        $this->actingAs($operator)->put(route('staff.settings.sms.update'), $this->payload())->assertForbidden();
        $this->actingAs($operator)->post(route('staff.settings.sms.test'), ['mobile' => '09123456789'])->assertForbidden();
        $this->actingAs($operator)->post(route('staff.settings.sms.probe'), ['mobile' => '09123456789'])->assertForbidden();
    }

    #[Test]
    public function the_opt_out_line_is_appended_to_every_message(): void
    {
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);

        Setting::putMany(['sms_provider' => 'console', 'sms_optout' => 'لغو ۱۱']);

        app(\App\Domain\Sms\SmsService::class)->queue('09123456789', 'متن پیام');

        $this->assertStringEndsWith("متن پیام\nلغو ۱۱", SmsMessage::firstOrFail()->body);
    }
}
