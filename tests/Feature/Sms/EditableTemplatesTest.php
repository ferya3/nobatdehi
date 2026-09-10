<?php

declare(strict_types=1);

namespace Tests\Feature\Sms;

use App\Domain\Access\Roles;
use App\Domain\Sms\SmsService;
use App\Domain\Sms\SmsTemplates;
use App\Models\Factory;
use App\Models\SmsTemplate;
use App\Models\User;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * متن پیامک‌ها، دستِ کارخانه.
 *
 * «به بارگیری مراجعه کنید» در کارخانه‌ای درست است که راننده مستقیم سرِ لاین
 * می‌رود و در کارخانه‌ای که اول باید از نگهبانی رد شود غلط. تا وقتی این
 * متن‌ها در کد بودند، هر تفاوتِ کوچکِ رویه یک تیکت می‌شد.
 */
final class EditableTemplatesTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->seed(SmsTemplateSeeder::class);
    }

    /** @var array<string, User> */
    private array $people = [];

    private function person(string $role): User
    {
        if (isset($this->people[$role])) {
            return $this->people[$role];
        }

        $user = User::create([
            'name' => 'کاربر',
            'email' => str_replace('-', '', $role).'@t.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role, 'web'));

        return $this->people[$role] = $user->fresh();
    }

    private function called(): SmsTemplate
    {
        return SmsTemplate::where('key', 'appointment.called')->firstOrFail();
    }

    #[Test]
    public function the_page_lists_each_template_with_the_variables_it_understands(): void
    {
        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->get(route('staff.settings.sms-templates'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/SmsTemplates')
                ->has('templates', count(SmsTemplates::keys()))
                // بدون فهرست متغیرها، اپراتور {plate} را جایی می‌نویسد که
                // پلاکی ندارد و همان {plate} خام برای راننده می‌رود
                ->has('templates.0.variables'));
    }

    #[Test]
    public function the_factory_can_rewrite_where_the_driver_is_sent(): void
    {
        $template = $this->called();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->put(route('staff.settings.sms-templates.update', $template), [
                'body' => "نوبت شما فرا رسید.\nشماره نوبت: {number}\nلطفاً به نگهبانی مراجعه کنید.",
                'is_active' => true,
            ])
            ->assertSessionHas('success');

        $this->assertStringContainsString('نگهبانی', $template->refresh()->body);
    }

    #[Test]
    public function a_variable_the_message_does_not_have_is_refused(): void
    {
        // وگرنه راننده پیامکی می‌گیرد که در آن نوشته «{driver} عزیز»
        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->from(route('staff.settings.sms-templates'))
            ->put(route('staff.settings.sms-templates.update', $this->called()), [
                'body' => 'نوبت {number} برای {national_code} آماده است.',
                'is_active' => true,
            ])
            ->assertSessionHasErrors('body');
    }

    #[Test]
    public function an_edited_text_survives_the_next_update(): void
    {
        $template = $this->called();
        $template->update(['body' => 'متنِ خودمان با {number}']);

        // همان seeder ای که update.sh در هر به‌روزرسانی اجرا می‌کند
        $this->seed(SmsTemplateSeeder::class);

        $this->assertSame('متنِ خودمان با {number}', $template->refresh()->body);
    }

    #[Test]
    public function a_template_can_be_put_back_to_the_default(): void
    {
        $template = $this->called();
        $template->update(['body' => 'یک چیز اشتباه']);

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->post(route('staff.settings.sms-templates.reset', $template))
            ->assertSessionHas('success');

        $this->assertSame(SmsTemplates::all()['appointment.called']['body'], $template->refresh()->body);
    }

    #[Test]
    public function switching_a_template_off_stops_that_message(): void
    {
        $this->called()->update(['is_active' => false]);

        app(SmsService::class)->queueTemplate('appointment.called', '09123456789', ['number' => 1]);

        $this->assertDatabaseMissing('sms_messages', ['template_key' => 'appointment.called']);
    }

    #[Test]
    public function an_operator_cannot_rewrite_the_texts(): void
    {
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->get(route('staff.settings.sms-templates'))
            ->assertForbidden();

        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->put(route('staff.settings.sms-templates.update', $this->called()), [
                'body' => 'هر چیزی',
                'is_active' => true,
            ])
            ->assertForbidden();
    }

    #[Test]
    public function every_template_the_code_sends_actually_exists(): void
    {
        // قالبی که در کد صدا زده شود ولی در فهرست نباشد، بی‌سروصدا هیچ
        // پیامکی نمی‌فرستد — queueTemplate در آن حالت null برمی‌گرداند
        $used = [];

        foreach (['app/Listeners', 'app/Domain', 'app/Http', 'app/Console'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                preg_match_all(
                    "/queueTemplate\(\s*'([a-z0-9.\-]+)'/i",
                    (string) file_get_contents($file->getPathname()),
                    $found,
                );

                $used = array_merge($used, $found[1]);
            }
        }

        $missing = array_values(array_diff(array_unique($used), SmsTemplates::keys()));

        $this->assertSame([], $missing, 'قالبی که کد صدا می‌زند تعریف نشده: '.implode('، ', $missing));
    }
}
