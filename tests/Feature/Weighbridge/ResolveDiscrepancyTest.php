<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
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
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * قفلِ برگه‌ی خروج باید کلیدی داشته باشد.
 *
 * سامانه می‌گفت «تا تعیین تکلیف برگه صادر نمی‌شود» و هیچ جایی برای تعیین
 * تکلیف نداشت. نتیجه‌اش کامیونی بود که در محوطه می‌ماند و تنها راهِ بازش
 * کردن، دست بردن در دیتابیس بود.
 */
final class ResolveDiscrepancyTest extends TestCase
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

    /** کامیونی که وزن پرش با حواله نخوانده */
    private function withDiscrepancy(): Appointment
    {
        $truck = $this->makeTruck('12', 'ب', '345', '11');
        $truck->forceFill(['truck_type_id' => TruckType::where('code', 'teriler')->value('id')])->save();

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory, $this->makeDriver('09123456789'), $truck->refresh(), $this->product,
        ));

        $move = app(TransitionAppointment::class);
        $appointment = $move($appointment, AppointmentStatus::CheckedIn, Actor::system());

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $appointment = $move($appointment, AppointmentStatus::Loading, Actor::system());
        $appointment = $move($appointment, AppointmentStatus::Loaded, Actor::system());

        // خالص ۲۵ تن در برابر حواله‌ی ۳۰ تنی — بیرون از رواداری ۳٪
        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'gross', 'weight_kg' => 39000])
            ->assertSessionHas('error');

        return $appointment->refresh();
    }

    #[Test]
    public function a_manager_can_accept_the_difference_and_the_permit_is_issued(): void
    {
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->from(route('staff.queue.show', $appointment))
            ->post(route('staff.queue.weight-discrepancy', $appointment), [
                'decision' => 'approved',
                'reason' => 'کسری بار با هماهنگی واحد فروش پذیرفته شد',
            ])
            ->assertSessionHas('success');

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('approved', $record->discrepancy_decision);
        $this->assertNotNull($record->exit_permit_number);
        $this->assertNotNull($record->discrepancy_decided_at);

        // وزن دست نمی‌خورد: چیزی که عوض شده اجازه‌ی خروج است، نه عدد
        $this->assertSame('25000.00', $record->net_weight_kg);
        $this->assertSame('39000.00', $record->loaded_weight_kg);

        // و حالا کامیون می‌تواند برود
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->post(route('staff.queue.transition', $appointment), ['to' => 'COMPLETED'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
    }

    #[Test]
    public function rejecting_it_leaves_the_truck_locked_and_says_so(): void
    {
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->post(route('staff.queue.weight-discrepancy', $appointment), [
                'decision' => 'rejected',
                'reason' => 'کسری بار پذیرفته نیست؛ بار تکمیل شود',
            ])
            ->assertSessionHas('success');

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('rejected', $record->discrepancy_decision);
        $this->assertNull($record->exit_permit_number);

        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->from(route('staff.queue.index'))
            ->post(route('staff.queue.transition', $appointment), ['to' => 'COMPLETED'])
            ->assertSessionHas('error');
    }

    #[Test]
    public function a_rejected_truck_can_be_loaded_properly_and_weighed_again(): void
    {
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->post(route('staff.queue.weight-discrepancy', $appointment), [
                'decision' => 'rejected',
                'reason' => 'کسری بار پذیرفته نیست؛ بار تکمیل شود',
            ]);

        // بار تکمیل می‌شود و کامیون دوباره وزن می‌شود — این بار درست
        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'gross', 'weight_kg' => 44000])
            ->assertSessionHas('success');

        $this->assertNotNull(LoadingRecord::firstOrFail()->exit_permit_number);
    }

    #[Test]
    public function the_decision_needs_a_written_reason(): void
    {
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->from(route('staff.queue.show', $appointment))
            ->post(route('staff.queue.weight-discrepancy', $appointment), ['decision' => 'approved', 'reason' => 'ok'])
            ->assertSessionHasErrors('reason');

        $this->assertNull(LoadingRecord::firstOrFail()->exit_permit_number);
    }

    #[Test]
    public function the_scaleman_cannot_sign_off_on_his_own_weighing(): void
    {
        // اگر خواندنِ وزن و پذیرفتنِ اختلاف یک نفر باشد، قفلِ مغایرت
        // هیچ معنایی ندارد
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::WEIGHBRIDGE), 'web')
            ->post(route('staff.queue.weight-discrepancy', $appointment), [
                'decision' => 'approved',
                'reason' => 'خودم دیدم مشکلی ندارد',
            ])
            ->assertForbidden();

        $this->assertNull(LoadingRecord::firstOrFail()->exit_permit_number);
    }

    #[Test]
    public function a_decision_cannot_be_taken_twice(): void
    {
        $appointment = $this->withDiscrepancy();
        $manager = $this->person(Roles::FACTORY_MANAGER);

        $this->actingAs($manager, 'web')->post(route('staff.queue.weight-discrepancy', $appointment), [
            'decision' => 'approved', 'reason' => 'کسری بار پذیرفته شد',
        ]);

        $permit = LoadingRecord::firstOrFail()->exit_permit_number;

        $this->actingAs($manager, 'web')
            ->from(route('staff.queue.show', $appointment))
            ->post(route('staff.queue.weight-discrepancy', $appointment), [
                'decision' => 'rejected', 'reason' => 'نظرم عوض شد و می‌خواهم برگردانم',
            ])
            ->assertSessionHas('error');

        $this->assertSame($permit, LoadingRecord::firstOrFail()->exit_permit_number);
    }

    #[Test]
    public function the_appointment_page_offers_the_decision_to_the_manager_only(): void
    {
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->get(route('staff.queue.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('mayResolveWeight', true)
                ->where('appointment.weighing.awaits_decision', true));

        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->get(route('staff.queue.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mayResolveWeight', false));
    }

    #[Test]
    public function approving_leaves_a_line_in_the_security_log(): void
    {
        // «چرا این کامیون با پنج تن کسری بیرون رفت؟» باید جواب داشته باشد
        $appointment = $this->withDiscrepancy();

        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->post(route('staff.queue.weight-discrepancy', $appointment), [
                'decision' => 'approved',
                'reason' => 'کسری بار با هماهنگی واحد فروش پذیرفته شد',
            ]);

        $this->assertDatabaseHas('security_logs', [
            'event' => 'admin_action',
            'identifier' => $appointment->ulid,
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => 'APPROVE_WEIGHT_DISCREPANCY']);
    }
}
