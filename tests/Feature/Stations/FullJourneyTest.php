<?php

declare(strict_types=1);

namespace Tests\Feature\Stations;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SmsMessage;
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
 * یک کامیون، از ثبت‌نام تا خروج — همان‌طور که آدم‌های واقعی طی‌اش می‌کنند.
 *
 * تست‌های دیگر هرکدام یک ایستگاه را جدا می‌سنجند و همه سبزند؛ ولی چیزی که
 * در عمل می‌شکند، جای اتصالِ ایستگاه‌هاست: عددی که یک صفحه می‌نویسد و صفحه‌ی
 * بعد نمی‌خواند، وضعیتی که در یک منو هست و در منوی بعدی نیست.
 *
 * هر قدم اینجا از راه HTTP و با نقشِ همان آدم انجام می‌شود، نه با صدا زدنِ
 * مستقیمِ Actionها.
 */
final class FullJourneyTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Product $product;

    /** @var array<string, User> */
    private array $staff = [];

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
        if (isset($this->staff[$role])) {
            return $this->staff[$role];
        }

        $user = User::create([
            'name' => $role,
            'email' => str_replace('-', '', $role).'@test.local',
            'password' => 'secret-password',
            'factory_id' => $this->factory->id,
            'is_active' => true,
        ]);

        $user->assignRole(Role::findByName($role, 'web'));

        return $this->staff[$role] = $user->fresh();
    }

    /** پلاکِ کامیونِ این داستان */
    private function plate(): array
    {
        return [
            'plate_two' => '55',
            'plate_letter' => 'ب',
            'plate_three' => '777',
            'plate_iran' => '11',
        ];
    }

    #[Test]
    public function one_truck_goes_all_the_way_from_booking_to_the_exit_permit(): void
    {
        $driver = $this->makeDriver('09121110000')->refresh();

        // ---------------------------------------------- ۱) راننده نوبت می‌گیرد
        $this->actingAs($driver, 'driver')
            ->post(route('driver.booking.store'), array_merge($this->plate(), [
                'driver_name' => 'علی رضایی',
                'national_code' => '0499370899',
                'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
                'product_id' => $this->product->id,
                'idempotency_key' => 'journey-'.uniqid(),
            ]))
            ->assertRedirect();

        $appointment = Appointment::firstOrFail();

        $this->assertSame(AppointmentStatus::Waiting, $appointment->status);
        $this->assertNotNull($appointment->start_time, 'سامانه باید خودش ساعت اعلام کند.');

        // راننده نوبتش را می‌بیند و QR می‌گیرد
        $this->actingAs($driver, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('qr.token'));

        $token = (string) $this->actingAs($driver, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->viewData('page')['props']['qr']['token'];

        // ---------------------------------------------- ۲) اپراتور در صف می‌بیندش
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointments.0.number', $appointment->number)
                ->where('appointments.0.status', AppointmentStatus::Waiting->value));

        // ---------------------------------------------- ۳) نگهبانی: اسکن و ورود
        $guard = $this->person(Roles::GATE);

        $this->actingAs($guard, 'web')
            ->post(route('staff.gate.scan'), ['token' => $token, 'source' => 'barcode'])
            ->assertRedirect();

        $this->actingAs($guard, 'web')
            ->post(route('staff.gate.check-in', $appointment), ['plate_match' => true])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);

        // ---------------------------------------------- ۴) باسکول اول: وزن خالی
        $scaleman = $this->person(Roles::WEIGHBRIDGE);

        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'tare',
                'weight_kg' => 14000,
            ])
            ->assertSessionHas('success');

        $this->assertSame('14000.00', LoadingRecord::firstOrFail()->empty_weight_kg);

        // ---------------------------------------------- ۵) لاین: شروع و پایان
        $loader = $this->person(Roles::WAREHOUSE);

        $this->actingAs($loader, 'web')
            ->post(route('staff.loading.transition', $appointment), ['to' => 'LOADING'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Loading, $appointment->refresh()->status);

        $this->actingAs($loader, 'web')
            ->post(route('staff.loading.transition', $appointment), ['to' => 'LOADED'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Loaded, $appointment->refresh()->status);

        // ---------------------------------------------- ۶) باسکول دوم: وزن پر
        // محصول A تناژ ۳۰ تن دارد؛ خالص ۳۰ تن یعنی بی‌مغایرت
        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), [
                'stage' => 'gross',
                'weight_kg' => 44000,
            ])
            ->assertSessionHas('success');

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('30000.00', $record->net_weight_kg);
        $this->assertNotNull($record->exit_permit_number, 'وزن پاک باید برگه خروج بگیرد.');
        $this->assertNull($record->discrepancy_kind);

        // ---------------------------------------------- ۷) خروج
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->post(route('staff.queue.transition', $appointment), ['to' => 'COMPLETED'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
        $this->assertNotNull($appointment->completed_at);

        // ---------------------------------------------- ۸) رد پا باید بماند
        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->get(route('staff.queue.show', $appointment))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointment.weighing.exit_permit_number', $record->exit_permit_number)
                ->where('appointment.gate.scan_source', 'barcode')
                // ثبت نوبت، ورود، شروع بارگیری، پایان بارگیری، خروج
                ->has('timeline', 5));

        // ---------------------------------------------- ۹) در گزارش‌ها
        $this->actingAs($this->person(Roles::FACTORY_MANAGER), 'web')
            ->get(route('staff.reports'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.completed', 1)
                // تناژ از باسکول خوانده می‌شود، نه از حواله
                ->where('byProduct.0.tons', 30));
    }

    /**
     * خاوری که نصفِ ظرفیتش بار زده — تا انتها، بدون هیچ گیری.
     *
     * این همان حالتی است که در عمل قفل می‌شد: تناژِ محصول ۳۰ تن بود و خاور
     * هرگز به آن نمی‌رسید، پس هر خاوری «مغایرت» می‌گرفت و برگه‌ی خروجش صادر
     * نمی‌شد. حالا باسکول شناور است و تناژ با خودِ کامیون می‌آید.
     */
    #[Test]
    public function a_small_truck_taking_half_a_load_goes_straight_through(): void
    {
        Setting::putMany(['sms_manager_recipients' => '09120000099']);

        $driver = $this->makeDriver('09121110000')->refresh();

        $this->actingAs($driver, 'driver')
            ->post(route('driver.booking.store'), array_merge($this->plate(), [
                'driver_name' => 'علی رضایی',
                'national_code' => '0499370899',
                // خاور: ظرفیت شش تن
                'truck_type_id' => TruckType::where('code', 'khavar')->value('id'),
                'product_id' => $this->product->id,
                'idempotency_key' => 'khavar-'.uniqid(),
            ]))
            ->assertRedirect();

        $appointment = Appointment::firstOrFail();

        $guard = $this->person(Roles::GATE);
        $token = (string) $this->actingAs($driver, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->viewData('page')['props']['qr']['token'];

        $this->actingAs($guard, 'web')->post(route('staff.gate.scan'), ['token' => $token, 'source' => 'barcode']);
        $this->actingAs($guard, 'web')->post(route('staff.gate.check-in', $appointment), ['plate_match' => true]);

        $scaleman = $this->person(Roles::WEIGHBRIDGE);

        // خاورِ خالی حدود ۳٫۵ تن است
        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'tare', 'weight_kg' => 3500])
            ->assertSessionHas('success');

        $loader = $this->person(Roles::WAREHOUSE);
        $this->actingAs($loader, 'web')->post(route('staff.loading.transition', $appointment), ['to' => 'LOADING']);
        $this->actingAs($loader, 'web')->post(route('staff.loading.transition', $appointment), ['to' => 'LOADED']);

        // سه تن بار زده — نصفِ ظرفیتش
        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'gross', 'weight_kg' => 6500])
            ->assertSessionHas('success');

        $record = LoadingRecord::firstOrFail();

        $this->assertSame('3000.00', $record->net_weight_kg);
        $this->assertNotNull($record->exit_permit_number, 'بارِ نصفه باید برگه خروج بگیرد.');
        $this->assertNull($record->discrepancy_kind);

        // هیچ اخطاری هم نباید رفته باشد
        $this->assertSame(
            0,
            SmsMessage::where('template_key', 'weight.discrepancy.manager')->count(),
        );

        // و کامیون بدون دخالت کسی بیرون می‌رود
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->post(route('staff.queue.transition', $appointment), ['to' => 'COMPLETED'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
    }

    /**
     * همان کامیون، این بار با اضافه‌بار — تا انتها.
     *
     * تک‌تک قطعه‌ها آزمون خودشان را دارند؛ چیزی که فقط اینجا دیده می‌شود این
     * است که آیا کامیون بعد از یک توزینِ ردشده واقعاً می‌تواند از سامانه
     * بیرون برود یا برای همیشه در محوطه می‌ماند.
     */
    #[Test]
    public function an_overloaded_truck_can_still_be_put_right_and_sent_out(): void
    {
        Setting::putMany(['sms_manager_recipients' => '09120000099']);

        $driver = $this->makeDriver('09121110000')->refresh();

        $this->actingAs($driver, 'driver')
            ->post(route('driver.booking.store'), array_merge($this->plate(), [
                'driver_name' => 'علی رضایی',
                'national_code' => '0499370899',
                // تریلیِ ۳۰ تنی با حواله‌ی ۳۰ تنی
                'truck_type_id' => TruckType::where('code', 'teriler')->value('id'),
                'product_id' => $this->product->id,
                'idempotency_key' => 'overload-'.uniqid(),
            ]))
            ->assertRedirect();

        $appointment = Appointment::firstOrFail();

        $guard = $this->person(Roles::GATE);
        $token = (string) $this->actingAs($driver, 'driver')
            ->get(route('driver.appointments.show', $appointment))
            ->viewData('page')['props']['qr']['token'];

        $this->actingAs($guard, 'web')->post(route('staff.gate.scan'), ['token' => $token, 'source' => 'camera']);
        $this->actingAs($guard, 'web')->post(route('staff.gate.check-in', $appointment), ['plate_match' => true]);

        $scaleman = $this->person(Roles::WEIGHBRIDGE);
        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'tare', 'weight_kg' => 14000]);

        $loader = $this->person(Roles::WAREHOUSE);
        $this->actingAs($loader, 'web')->post(route('staff.loading.transition', $appointment), ['to' => 'LOADING']);
        $this->actingAs($loader, 'web')->post(route('staff.loading.transition', $appointment), ['to' => 'LOADED']);

        // ---- اضافه‌بار: یک تن بیشتر از ظرفیت
        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'gross', 'weight_kg' => 45000])
            ->assertSessionHas('error');

        $this->assertNull(LoadingRecord::firstOrFail()->exit_permit_number);

        // مدیر باید خبردار شده باشد
        $this->assertDatabaseHas('sms_messages', [
            'to' => '09120000099',
            'template_key' => 'weight.discrepancy.manager',
        ]);

        // و اپراتورِ صف باید کنار همان کامیون اخطار ببیند
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->get(route('staff.queue.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('appointments.0.weighing.discrepancy_label', 'اضافه‌بار'));

        // ---- خروج تا پیش از حل شدن مسدود است
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->post(route('staff.queue.transition', $appointment), ['to' => 'COMPLETED'])
            ->assertSessionHas('error');

        $this->assertSame(AppointmentStatus::Loaded, $appointment->refresh()->status);

        // ---- راننده یک تن کم می‌کند و دوباره وزن می‌شود
        $this->actingAs($scaleman, 'web')
            ->post(route('staff.weighbridge.record', $appointment), ['stage' => 'gross', 'weight_kg' => 44000])
            ->assertSessionHas('success');

        $record = LoadingRecord::firstOrFail();

        $this->assertNotNull($record->exit_permit_number);
        $this->assertFalse((bool) $record->is_overload);

        // ---- و حالا می‌تواند برود
        $this->actingAs($this->person(Roles::OPERATOR), 'web')
            ->post(route('staff.queue.transition', $appointment), ['to' => 'COMPLETED'])
            ->assertSessionHas('success');

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);

        // یک اخطار برای یک کامیون، هرچند بار که وزن شود
        $this->assertSame(
            1,
            SmsMessage::where('template_key', 'weight.discrepancy.manager')->count(),
        );
    }
}
