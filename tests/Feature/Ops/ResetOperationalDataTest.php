<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * صفر کردنِ کارِ انجام‌شده، بدون دست زدن به پیکربندی.
 *
 * چیزی که این آزمون نگه می‌دارد یک مرز است: بعد از اجرا، کارخانه باید
 * بتواند بدون هیچ تنظیم دوباره‌ای کار را از سر بگیرد. پاک شدنِ ناخواسته‌ی
 * توکن باسکول یا رمز کاربران، همان لحظه دیده نمی‌شود — فردا صبح دیده می‌شود.
 */
final class ResetOperationalDataTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->seedStaff();
        $this->seed(SmsTemplateSeeder::class);
        $this->freezeOnWorkingMorning($this->factory);
    }

    /** یک روزِ کاریِ کامل: نوبت، ورود، توزین، بارگیری */
    protected function aDaysWork(): Appointment
    {
        $product = Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail();

        $appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '11'),
            $product,
        ));

        $appointment = app(TransitionAppointment::class)(
            $appointment, AppointmentStatus::CheckedIn, Actor::system(),
        );

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        return $appointment;
    }

    #[Test]
    public function it_clears_the_days_work(): void
    {
        $this->aDaysWork();

        $this->artisan('data:reset --force')->assertSuccessful();

        foreach (['appointments', 'appointment_transitions', 'loading_records', 'drivers', 'trucks'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "جدول {$table} خالی نشد.");
        }
    }

    #[Test]
    public function it_leaves_everything_the_factory_configured_alone(): void
    {
        Setting::putMany([
            'sms_username' => 'factory-user',
            'sms_manager_recipients' => '09120000099',
            'scale_device_token' => 'a-token-nobody-wants-to-retype',
        ]);

        $this->aDaysWork();

        $staffBefore = User::count();
        $passwordBefore = User::orderBy('id')->value('password');

        $this->artisan('data:reset --force')->assertSuccessful();

        $this->assertSame('factory-user', Setting::get('sms_username'));
        $this->assertSame('09120000099', Setting::get('sms_manager_recipients'));
        $this->assertSame('a-token-nobody-wants-to-retype', Setting::get('scale_device_token'));

        // رمزها نباید عوض شوند: کسی که این فرمان را می‌زند انتظار ندارد
        // فردا صبح هیچ‌کس نتواند وارد پنل شود
        $this->assertSame($staffBefore, User::count());
        $this->assertSame($passwordBefore, User::orderBy('id')->value('password'));

        $this->assertGreaterThan(0, DB::table('working_hours')->count());
        $this->assertGreaterThan(0, DB::table('products')->count());
        $this->assertGreaterThan(0, DB::table('truck_types')->count());
        $this->assertGreaterThan(0, DB::table('sms_templates')->count());
        $this->assertSame(1, Factory::count());
    }

    #[Test]
    public function the_factory_can_take_a_booking_again_right_afterwards(): void
    {
        $this->aDaysWork();

        $this->artisan('data:reset --force')->assertSuccessful();

        // بدون اسلات، اولین راننده‌ای که نوبت می‌خواهد جواب نمی‌گیرد
        $fresh = $this->aDaysWork();

        $this->assertSame(1, $fresh->number, 'شماره‌ی نوبت باید از یک شروع شود.');
        $this->assertSame(AppointmentStatus::CheckedIn, $fresh->refresh()->status);
    }

    #[Test]
    public function the_catalog_only_goes_when_it_is_asked_for(): void
    {
        $this->artisan('data:reset --force')->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('products')->count());

        $this->artisan('data:reset --force --catalog')->assertSuccessful();
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('truck_types')->count());
        $this->assertSame(0, DB::table('loading_points')->count());

        // حتی آن‌وقت هم تنظیمات و کاربران می‌مانند
        $this->assertGreaterThan(0, User::count());
        $this->assertSame(1, Factory::count());
    }

    #[Test]
    public function a_dry_run_touches_nothing(): void
    {
        $this->aDaysWork();

        $this->artisan('data:reset --dry-run')->assertSuccessful();

        $this->assertSame(1, DB::table('appointments')->count());
    }

    #[Test]
    public function saying_no_touches_nothing(): void
    {
        // پیش‌فرضِ پرسش «نه» است، پس چسباندنِ این فرمان به یک اسکریپت هم
        // بدون --force چیزی پاک نمی‌کند
        $this->aDaysWork();

        $this->artisan('data:reset')
            ->expectsConfirmation('پاک شود؟ این کار برگشت‌پذیر نیست.', 'no')
            ->assertSuccessful();

        $this->assertSame(1, DB::table('appointments')->count());
    }

    #[Test]
    public function saying_yes_clears_it(): void
    {
        $this->aDaysWork();

        $this->artisan('data:reset')
            ->expectsConfirmation('پاک شود؟ این کار برگشت‌پذیر نیست.', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('appointments')->count());
    }
}
