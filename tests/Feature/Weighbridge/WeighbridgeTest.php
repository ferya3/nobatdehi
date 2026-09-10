<?php

declare(strict_types=1);

namespace Tests\Feature\Weighbridge;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Appointment\Exceptions\TransitionBlocked;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Truck;
use App\Models\TruckType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * «وزن خالص = پر − خالی» فقط وقتی ضدسرقت است که هیچ‌کس نتواند یکی از سه عدد
 * را جدا از بقیه بنویسد، و هیچ کامیونی بدون این حساب از در بیرون نرود.
 */
final class WeighbridgeTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    private Truck $truck;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);

        $this->product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();

        // کامیون ۳۰ تنی: سقفِ اضافه‌بار همین است
        $type = TruckType::where('code', 'teriler')->firstOrFail();
        $type->update(['capacity_tons' => 30]);

        $this->truck = $this->makeTruck('12', 'ب', '345', '11');
        $this->truck->update(['truck_type_id' => $type->id]);
    }

    private function staff(string $role, string $email): User
    {
        $user = User::create([
            'name' => 'کاربر',
            'email' => $email,
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role));

        return $user->fresh();
    }

    private ?User $scaleman = null;

    private function scaleman(): User
    {
        return $this->scaleman ??= $this->staff(Roles::WEIGHBRIDGE, 'scale@test.local');
    }

    /** نوبتی که وارد محوطه شده و آماده‌ی باسکول اول است */
    private function checkedIn(): Appointment
    {
        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->truck->refresh(),
            $this->product,
        ));

        return app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::CheckedIn,
            Actor::system(),
        );
    }

    private function weigh(Appointment $appointment, string $stage, float $kg): TestResponse
    {
        return $this->actingAs($this->scaleman())->post(
            route('staff.weighbridge.record', $appointment),
            ['stage' => $stage, 'weight_kg' => $kg, 'source' => 'device'],
        );
    }

    #[Test]
    public function test_loading_cannot_start_before_the_empty_weight_is_recorded(): void
    {
        $appointment = $this->checkedIn();

        $this->expectException(TransitionBlocked::class);

        app(TransitionAppointment::class)(
            $appointment,
            AppointmentStatus::Loading,
            Actor::system(),
        );
    }

    #[Test]
    public function test_the_net_weight_is_computed_by_the_server_not_submitted(): void
    {
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000)->assertSessionHas('success');

        $appointment = $this->loadAndFinish($appointment);

        $this->weigh($appointment, 'gross', 34000)->assertSessionHas('success');

        $record = LoadingRecord::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertSame('20000.00', $record->net_weight_kg);

        // ظرفیت کامیون، نه تناژِ الزامی — و چون اضافه‌باری نیست، اختلافی هم نیست
        $this->assertSame('30000.00', $record->expected_net_kg);
        $this->assertNull($record->variance_kg);
        $this->assertNotNull($record->exit_permit_number);
    }

    #[Test]
    public function test_an_overloaded_truck_gets_no_exit_permit(): void
    {
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000)->assertSessionHas('success');
        $appointment = $this->loadAndFinish($appointment);

        // خالص ۳۱ تن روی کامیون ۳۰ تنی
        $this->weigh($appointment, 'gross', 45000)->assertSessionHas('error');

        $record = LoadingRecord::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertTrue($record->is_overload);
        $this->assertNull($record->exit_permit_number);
        $this->assertSame('31000.00', $record->net_weight_kg);
    }

    #[Test]
    public function test_a_truck_that_took_less_than_it_could_still_gets_a_permit(): void
    {
        // باسکول شناور است: تریلیِ سی‌تنی که پنج تن برده، هیچ اشکالی ندارد.
        // بارِ نصفه یک انتخاب است، نه یک خطا — و پیش از این همین حالت قفل
        // می‌شد چون تناژِ محصول را الزام می‌گرفتیم.
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000)->assertSessionHas('success');
        $appointment = $this->loadAndFinish($appointment);

        $this->weigh($appointment, 'gross', 19000)->assertSessionHas('success');

        $record = LoadingRecord::where('appointment_id', $appointment->id)->firstOrFail();

        $this->assertSame('5000.00', $record->net_weight_kg);
        $this->assertNotNull($record->exit_permit_number);
        $this->assertNull($record->discrepancy_kind);
    }

    #[Test]
    public function test_the_empty_weight_cannot_be_rewritten_after_the_fact(): void
    {
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000)->assertSessionHas('success');

        // «اصلاح» وزن خالی، همان حفره‌ای است که وزن خالص را دلخواه می‌کند
        $this->weigh($appointment, 'tare', 11000)->assertSessionHas('error');

        $this->assertSame(
            '14000.00',
            LoadingRecord::where('appointment_id', $appointment->id)->value('empty_weight_kg'),
        );
    }

    #[Test]
    public function test_a_gross_weight_below_the_tare_is_refused(): void
    {
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000)->assertSessionHas('success');
        $appointment = $this->loadAndFinish($appointment);

        $this->weigh($appointment, 'gross', 13000)->assertSessionHas('error');

        $this->assertNull(
            LoadingRecord::where('appointment_id', $appointment->id)->value('exit_permit_number'),
        );
    }

    #[Test]
    public function test_a_truck_cannot_leave_without_an_exit_permit(): void
    {
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000);
        $appointment = $this->loadAndFinish($appointment);
        $this->weigh($appointment, 'gross', 45000); // اضافه‌بار ⇒ بدون برگه

        $this->expectException(TransitionBlocked::class);

        app(TransitionAppointment::class)(
            $appointment->refresh(),
            AppointmentStatus::Completed,
            Actor::system(),
        );
    }

    #[Test]
    public function test_a_cleared_truck_may_leave(): void
    {
        $appointment = $this->checkedIn();

        $this->weigh($appointment, 'tare', 14000);
        $appointment = $this->loadAndFinish($appointment);
        $this->weigh($appointment, 'gross', 34000);

        $done = app(TransitionAppointment::class)(
            $appointment->refresh(),
            AppointmentStatus::Completed,
            Actor::system(),
        );

        $this->assertSame(AppointmentStatus::Completed, $done->status);
    }

    #[Test]
    public function test_exit_permit_numbers_are_a_gapless_daily_serial(): void
    {
        $numbers = [];

        foreach ([['09120000001', '21', 'ب', '111', '11'], ['09120000002', '22', 'ب', '222', '22']] as $i => $row) {
            [$mobile, $two, $letter, $three, $iran] = $row;

            $truck = $this->makeTruck($two, $letter, $three, $iran);
            $truck->update(['truck_type_id' => $this->truck->truck_type_id]);

            $appointment = app(CreateAppointment::class)($this->booking(
                $this->factory,
                $this->makeDriver($mobile),
                $truck->refresh(),
                $this->product,
            ));

            $appointment = app(TransitionAppointment::class)($appointment, AppointmentStatus::CheckedIn, Actor::system());

            $this->weigh($appointment, 'tare', 14000);
            $appointment = $this->loadAndFinish($appointment);
            $this->weigh($appointment, 'gross', 34000);

            $numbers[] = LoadingRecord::where('appointment_id', $appointment->id)->value('exit_permit_number');
        }

        $this->assertSame(1, (int) substr((string) $numbers[0], -4));
        $this->assertSame(2, (int) substr((string) $numbers[1], -4));
    }

    #[Test]
    public function test_a_gate_guard_cannot_record_weights(): void
    {
        $appointment = $this->checkedIn();

        $this->actingAs($this->staff(Roles::GATE, 'gate@test.local'))
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare', 'weight_kg' => 14000, 'source' => 'device',
            ])
            ->assertForbidden();
    }

    /** بارگیری را تا «بارگیری‌شده» جلو می‌برد تا نوبت باسکول دوم برسد */
    private function loadAndFinish(Appointment $appointment): Appointment
    {
        $appointment = app(TransitionAppointment::class)($appointment->refresh(), AppointmentStatus::Loading, Actor::system());

        return app(TransitionAppointment::class)($appointment, AppointmentStatus::Loaded, Actor::system());
    }
}
