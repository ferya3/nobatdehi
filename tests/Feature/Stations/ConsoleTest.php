<?php

declare(strict_types=1);

namespace Tests\Feature\Stations;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\TruckType;
use App\Models\User;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * یک صفحه به‌جای پنج تا.
 *
 * قرار نیست کاربر اول ایستگاه را انتخاب کند و بعد کامیون را. کامیون انتخاب
 * می‌شود و کنسول خودش می‌گوید کارِ بعدی‌اش چیست — همان نردبانی که در محوطه
 * بالا می‌رود.
 *
 * چیزی که این آزمون بیش از همه نگه می‌دارد این است: کنسول میان‌بر نیست.
 * هر کار همان مسیرِ همیشگی را صدا می‌زند و همان دسترسی را می‌خواهد.
 */
final class ConsoleTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->seed(SmsTemplateSeeder::class);
        $this->freezeOnWorkingMorning($this->factory);

        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();

        Setting::putMany(['sms_provider' => 'console']);
    }

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

    private function book(string $typeCode = 'tak'): Appointment
    {
        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->forceFill(['truck_type_id' => TruckType::where('code', $typeCode)->value('id')])->save();

        return app(CreateAppointment::class)($this->booking(
            $this->factory, $this->makeDriver('09123456789'), $truck->refresh(), $this->product,
        ));
    }

    private function console(User $user, ?Appointment $appointment = null): TestResponse
    {
        return $this->actingAs($user, 'web')->get(route(
            'staff.console',
            $appointment === null ? [] : ['waybill' => $appointment->ulid],
        ));
    }

    #[Test]
    public function the_console_says_what_the_truck_needs_next_at_every_step(): void
    {
        // مدیر کارخانه هر پنج کار را می‌تواند، پس نردبان کامل را می‌بیند
        $manager = $this->person(Roles::FACTORY_MANAGER);
        $appointment = $this->book();

        $expect = function (string $step) use ($manager, $appointment) {
            $this->console($manager, $appointment)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('selected.next', $step)
                    ->where('selected.mine', true));
        };

        $expect('check-in');

        $this->actingAs($manager, 'web')->post(route('staff.gate.check-in', $appointment), [
            'plate_match' => true,
            'override_reason' => 'ورود آزمایشی از کنسول ثبت شد',
            'console' => 1,
        ])->assertRedirect(route('staff.console', ['waybill' => $appointment->ulid]));

        $expect('tare');

        $this->actingAs($manager, 'web')->post(route('staff.weighbridge.record', $appointment), [
            'stage' => 'tare', 'weight_kg' => 3500, 'console' => 1,
        ]);

        $expect('start-loading');

        $this->actingAs($manager, 'web')->post(route('staff.loading.transition', $appointment), [
            'to' => 'LOADING', 'console' => 1,
        ]);

        $expect('finish-loading');

        $this->actingAs($manager, 'web')->post(route('staff.loading.transition', $appointment), [
            'to' => 'LOADED', 'console' => 1,
        ]);

        $expect('gross');

        $this->actingAs($manager, 'web')->post(route('staff.weighbridge.record', $appointment), [
            'stage' => 'gross', 'weight_kg' => 9500, 'console' => 1,
        ]);

        $expect('exit');

        $this->actingAs($manager, 'web')->post(route('staff.queue.transition', $appointment), [
            'to' => 'COMPLETED', 'console' => 1,
        ]);

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);

        // ۶ تن خالص روی کامیون ده‌تنی — بارِ نصفه، بدون هیچ گیری
        $this->assertSame('6000.00', LoadingRecord::firstOrFail()->net_weight_kg);
        $this->assertNotNull(LoadingRecord::firstOrFail()->exit_permit_number);
    }

    #[Test]
    public function an_action_taken_from_the_console_comes_back_to_the_console(): void
    {
        // وگرنه اپراتور ناگهان روی صفحه‌ی نگهبانی می‌افتد و جای خودش را
        // در صف گم می‌کند
        $appointment = $this->book();
        $manager = $this->person(Roles::FACTORY_MANAGER);

        $this->actingAs($manager, 'web')
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_match' => true,
                'override_reason' => 'ورود آزمایشی از کنسول ثبت شد',
                'console' => 1,
            ])
            ->assertRedirect(route('staff.console', ['waybill' => $appointment->ulid]));
    }

    #[Test]
    public function the_same_action_from_its_own_station_still_lands_on_that_station(): void
    {
        // کنسول رفتارِ صفحه‌های موجود را عوض نمی‌کند؛ فقط یک مقصدِ دیگر دارد
        $appointment = $this->book();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->post(route('staff.gate.check-in', $appointment), [
                'plate_match' => true,
                'override_reason' => 'ورود آزمایشی از صفحه‌ی نگهبانی ثبت شد',
            ])
            ->assertRedirect(route('staff.gate.index'));
    }

    #[Test]
    public function the_queue_marks_the_jobs_this_user_can_actually_do(): void
    {
        $appointment = $this->book();

        // نگهبان: ورود مالِ اوست
        $this->console($this->person(Roles::GATE))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.next', 'check-in')
                ->where('rows.0.mine', true)
                ->where('can.weigh', false));

        // باسکول‌بان همان کامیون را می‌بیند ولی دکمه‌ای جلویش باز نمی‌شود
        $this->console($this->person(Roles::WEIGHBRIDGE), $appointment)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.next', 'check-in')
                ->where('rows.0.mine', false)
                ->where('selected.mine', false)
                ->where('can.checkin', false));
    }

    #[Test]
    public function the_console_is_no_shortcut_around_a_permission(): void
    {
        $appointment = $this->book();

        // باسکول‌بان از کنسول هم نمی‌تواند ورود ثبت کند
        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true, 'console' => 1])
            ->assertForbidden();

        $this->assertSame(AppointmentStatus::Waiting, $appointment->refresh()->status);
    }

    #[Test]
    public function a_guard_still_cannot_walk_in_a_truck_without_a_reason(): void
    {
        // قاعده‌ی گیت سرِ جایش است: بدون اسکن، ورود استثنا می‌خواهد و
        // نگهبان آن استثنا را ندارد
        $appointment = $this->book();

        $this->actingAs($this->person(Roles::GATE), 'web')
            ->from(route('staff.console'))
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true, 'console' => 1])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Waiting, $appointment->refresh()->status);
    }

    #[Test]
    public function an_overloaded_truck_is_flagged_in_the_queue_and_waits_for_the_manager(): void
    {
        $appointment = $this->book();
        $manager = $this->person(Roles::FACTORY_MANAGER);

        $this->actingAs($manager, 'web')->post(route('staff.gate.check-in', $appointment), [
            'plate_match' => true, 'override_reason' => 'ورود آزمایشی از کنسول ثبت شد', 'console' => 1,
        ]);
        $this->actingAs($manager, 'web')->post(route('staff.weighbridge.record', $appointment), [
            'stage' => 'tare', 'weight_kg' => 3500, 'console' => 1,
        ]);
        $this->actingAs($manager, 'web')->post(route('staff.loading.transition', $appointment), ['to' => 'LOADING', 'console' => 1]);
        $this->actingAs($manager, 'web')->post(route('staff.loading.transition', $appointment), ['to' => 'LOADED', 'console' => 1]);

        // ظرفیت تک ۱۰ تن؛ خالص ۱۲ تن
        $this->actingAs($manager, 'web')->post(route('staff.weighbridge.record', $appointment), [
            'stage' => 'gross', 'weight_kg' => 15500, 'console' => 1,
        ]);

        $this->console($manager, $appointment)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rows.0.alert', true)
                ->where('selected.next', 'resolve')
                ->where('selected.mine', true));

        // اپراتوری که اجازه‌ی تصمیم ندارد، دلیلِ ایستادن کامیون را می‌بیند
        $this->console($this->person(Roles::OPERATOR), $appointment)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.mine', false)
                ->where('selected.blocked', 'منتظر تصمیم مدیر کارخانه'));
    }

    #[Test]
    public function someone_with_no_yard_job_has_no_console(): void
    {
        // مدیرعامل گزارش می‌خواند، کامیون راه نمی‌اندازد
        $this->console($this->person(Roles::CEO))->assertForbidden();
    }

    #[Test]
    public function the_console_shows_todays_numbers_without_a_second_page(): void
    {
        $this->book();

        $this->console($this->person(Roles::FACTORY_MANAGER))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Console')
                ->where('counters.waiting', 1)
                ->where('counters.on_site', 0)
                ->has('rows', 1));
    }

    #[Test]
    public function a_waybill_from_another_factory_does_not_open(): void
    {
        $appointment = $this->book();

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $appointment->forceFill(['factory_id' => $other->id])->save();

        $this->console($this->person(Roles::FACTORY_MANAGER), $appointment)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected', null));
    }
}
