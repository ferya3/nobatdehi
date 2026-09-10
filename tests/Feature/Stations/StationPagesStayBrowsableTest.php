<?php

declare(strict_types=1);

namespace Tests\Feature\Stations;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Truck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * هیچ ایستگاهی نباید مرورگر را روی نشانیِ یک POST رها کند.
 *
 * باگی که این آزمون نگه می‌دارد واقعی بود و روی سرور دیده شد: اسکن و
 * جستجوی پلاک خودشان صفحه را رندر می‌کردند، پس نشانیِ مرورگر روی
 * `/panel/weighbridge/lookup` می‌نشست. بعدش ثبت وزن `back()` می‌زد،
 * مرورگر همان نشانی را این بار با GET باز می‌کرد، و چون آن مسیر فقط POST
 * را می‌پذیرفت کاربر یک صفحه‌ی خالیِ «۴۰۵ Method Not Allowed» می‌دید —
 * بدون هیچ سرنخی که چه شد.
 *
 * قاعده: هر پاسخِ ایستگاه که ریدایرکت است، باید به نشانی‌ای برود که
 * مرورگر بتواند با GET بازش کند.
 */
final class StationPagesStayBrowsableTest extends TestCase
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
        $this->truck = $this->makeTruck('12', 'ب', '345', '11');
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

    /** @return array<string, string> */
    private function plate(): array
    {
        return ['plate_two' => '12', 'plate_letter' => 'ب', 'plate_three' => '345', 'plate_iran' => '11'];
    }

    private function book(): Appointment
    {
        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->truck->refresh(),
            $this->product,
        ));
    }

    /** نشانی‌ای که مرورگر همین حالا رویش ایستاده */
    private string $at = '';

    private function visit(User $user, string $url): TestResponse
    {
        $this->at = $url;

        return $this->actingAs($user)->get($url);
    }

    /**
     * همان کاری که مرورگر می‌کند، با همان ترتیب.
     *
     * سه چیز اینجا مهم است و هیچ‌کدام در یک post() ساده دیده نمی‌شود:
     * Referer همان صفحه‌ای است که کاربر رویش ایستاده (چیزی که back() از آن
     * می‌خواند)، ریدایرکت با GET دنبال می‌شود (جایی که ۴۰۵ می‌افتاد)، و
     * نشانیِ جدید برای قدم بعدی نگه داشته می‌شود.
     */
    private function submit(User $user, string $url, array $data = []): TestResponse
    {
        $response = $this->actingAs($user)->from($this->at)->post($url, $data);

        $response->assertRedirect();

        return $this->visit($user, (string) $response->headers->get('Location'));
    }

    /** کاربری که پای ایستگاه نشسته و صفحه‌اش را باز کرده */
    private function standingAt(string $role, string $email, string $station): User
    {
        $user = $this->staff($role, $email);

        $this->visit($user, route($station))->assertOk();

        return $user;
    }

    #[Test]
    public function recording_a_weight_after_a_plate_lookup_lands_on_a_page_the_browser_can_open(): void
    {
        $appointment = app(TransitionAppointment::class)(
            $this->book(), AppointmentStatus::CheckedIn, Actor::system(),
        );

        $scaleman = $this->standingAt(Roles::WEIGHBRIDGE, 'scale@test.local', 'staff.weighbridge.index');

        // ۱) اپراتور با پلاک پیدایش می‌کند
        $this->submit($scaleman, route('staff.weighbridge.lookup'), $this->plate())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.appointment.number', $appointment->number));

        // ۲) وزن خالی را تایپ می‌کند — همان جایی که ۴۰۵ می‌داد
        $recorded = $this->submit($scaleman, route('staff.weighbridge.record', $appointment), [
            'stage' => 'tare',
            'weight_kg' => 14000,
        ]);

        // پیغام باید روی همان صفحه دیده شود، نه در session‌ای که کسی نمی‌بیند
        $recorded->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('flash.success', 'وزن خالی ثبت شد. کامیون می‌تواند به لاین بارگیری برود.')
            // حواله روی صفحه می‌ماند تا اپراتور نتیجه را کنارش ببیند
            ->where('result.appointment.number', $appointment->number));
    }

    #[Test]
    public function a_refused_weighing_also_lands_on_a_page_the_browser_can_open(): void
    {
        // هنوز وارد محوطه نشده: توزین رد می‌شود و پیغام خطا می‌دهد
        $appointment = $this->book();
        $scaleman = $this->standingAt(Roles::WEIGHBRIDGE, 'scale@test.local', 'staff.weighbridge.index');

        $this->submit($scaleman, route('staff.weighbridge.lookup'), $this->plate())->assertOk();

        $refused = $this->submit($scaleman, route('staff.weighbridge.record', $appointment), [
            'stage' => 'tare',
            'weight_kg' => 14000,
        ]);

        // خطا باید کنارِ همان حواله دیده شود، نه روی یک صفحه‌ی خالی
        $refused->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->whereNot('flash.error', null)
            ->where('result.appointment.number', $appointment->number));
    }

    #[Test]
    public function a_refused_check_in_after_a_plate_lookup_lands_on_a_page_the_browser_can_open(): void
    {
        $appointment = $this->book();
        $guard = $this->standingAt(Roles::GATE, 'gate@test.local', 'staff.gate.index');

        $this->submit($guard, route('staff.gate.lookup'), $this->plate())->assertOk();

        // نگهبان استثنای دستی ندارد، پس ورود رد می‌شود
        $refused = $this->submit($guard, route('staff.gate.check-in', $appointment), ['plate_match' => true]);

        $refused->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->whereNot('flash.error', null)
            ->where('result.appointment.number', $appointment->number));
    }

    #[Test]
    public function a_refused_loading_start_after_a_plate_lookup_lands_on_a_page_the_browser_can_open(): void
    {
        // بدون توزین خالی، شروع بارگیری رد می‌شود
        $appointment = app(TransitionAppointment::class)(
            $this->book(), AppointmentStatus::CheckedIn, Actor::system(),
        );

        $loader = $this->standingAt(Roles::WAREHOUSE, 'loader@test.local', 'staff.loading.index');

        $this->submit($loader, route('staff.loading.lookup'), $this->plate())->assertOk();

        $refused = $this->submit($loader, route('staff.loading.transition', $appointment), ['to' => 'LOADING']);

        $refused->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->whereNot('flash.error', null)
            ->where('result.appointment.number', $appointment->number));
    }

    #[Test]
    public function a_finished_loading_after_a_plate_lookup_lands_on_a_page_the_browser_can_open(): void
    {
        $appointment = app(TransitionAppointment::class)(
            $this->book(), AppointmentStatus::CheckedIn, Actor::system(),
        );

        LoadingRecord::create([
            'appointment_id' => $appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $loader = $this->standingAt(Roles::WAREHOUSE, 'loader@test.local', 'staff.loading.index');

        $this->submit($loader, route('staff.loading.lookup'), $this->plate())->assertOk();

        $started = $this->submit($loader, route('staff.loading.transition', $appointment), ['to' => 'LOADING']);

        $started->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->whereNot('flash.success', null));
        $this->assertSame(AppointmentStatus::Loading, $appointment->refresh()->status);
    }

    #[Test]
    public function the_waybill_survives_a_page_refresh(): void
    {
        // F5 روی صفحه‌ی ایستگاه نباید حواله را گم کند و نباید فرم را
        // دوباره بفرستد — به همین دلیل حواله در نشانی می‌نشیند
        $appointment = app(TransitionAppointment::class)(
            $this->book(), AppointmentStatus::CheckedIn, Actor::system(),
        );

        $scaleman = $this->staff(Roles::WEIGHBRIDGE, 'scale@test.local');

        $target = (string) $this->actingAs($scaleman)
            ->post(route('staff.weighbridge.lookup'), $this->plate())
            ->headers->get('Location');

        // دو بار پشت سر هم، مثل دو بار زدنِ F5
        foreach ([1, 2] as $ignored) {
            $this->actingAs($scaleman)->get($target)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('result.appointment.number', $appointment->number));
        }
    }

    #[Test]
    public function a_waybill_from_another_factory_is_not_shown_by_editing_the_address(): void
    {
        $appointment = $this->book();

        $other = Factory::create(array_merge(
            $this->factory->replicate()->getAttributes(),
            ['name' => 'کارخانه دوم', 'slug' => 'second'],
        ));

        $appointment->forceFill(['factory_id' => $other->id])->save();

        $scaleman = $this->staff(Roles::WEIGHBRIDGE, 'scale@test.local');

        $this->actingAs($scaleman)
            ->get(route('staff.weighbridge.index', ['waybill' => $appointment->ulid]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('result.appointment', null));
    }
}
