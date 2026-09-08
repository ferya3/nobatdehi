<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Domain\Truck\PlateNumber;
use App\Events\AppointmentTransitioned;
use App\Listeners\NotifyNextDriverWhenLoadingStarts;
use App\Models\Appointment;
use App\Models\Driver;
use App\Models\DriverNotification;
use App\Models\Factory;
use App\Models\Truck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * اعلانِ داخل برنامه — کانالِ دومِ خبررسانی به راننده.
 */
final class DriverNotificationTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        // ساعت را وسط یک روز کاری نگه می‌داریم تا نوبت‌ها روی «امروز»
        // بنشینند؛ وگرنه گروهِ «نوبت‌داران امروز» بعدازظهرها خالی می‌شود.
        $this->travelTo(CarbonImmutable::parse('2026-09-07 09:00', 'Asia/Tehran'));

        $this->factory = $this->seedFactory();
        $this->seedStaff();
    }

    private function manager(): User
    {
        return User::role(Roles::FACTORY_MANAGER)->firstOrFail();
    }

    private function book(string $mobile, string $plateTwo): Appointment
    {
        $driver = Driver::create(['mobile' => $mobile, 'name' => 'راننده '.$mobile]);
        $truck = Truck::fromPlate(PlateNumber::make($plateTwo, 'ب', '345', '67'), null);

        return app(CreateAppointment::class)($this->booking($this->factory, $driver, $truck));
    }

    #[Test]
    public function a_manager_sends_one_message_and_every_driver_in_the_group_gets_a_row(): void
    {
        $this->book('09123000001', '11');
        $this->book('09123000002', '12');

        $this->actingAs($this->manager())
            ->post(route('staff.notifications.store'), [
                'audience' => 'today',
                'title' => 'تعطیلی فردا',
                'body' => 'کارخانه فردا تعطیل است.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, DriverNotification::count());
        $this->assertSame('تعطیلی فردا', DriverNotification::first()->title);
    }

    #[Test]
    public function a_driver_only_ever_sees_their_own_notifications(): void
    {
        $mine = $this->book('09123000001', '11')->driver;
        $theirs = $this->book('09123000002', '12')->driver;

        DriverNotification::create(['driver_id' => $mine->id, 'title' => 'مالِ من', 'body' => '…']);
        DriverNotification::create(['driver_id' => $theirs->id, 'title' => 'مالِ او', 'body' => '…']);

        $response = $this->actingAs($mine, 'driver')
            ->getJson(route('driver.api.notifications'))
            ->assertOk();

        $response->assertJsonCount(1, 'notifications');
        $response->assertJsonPath('notifications.0.title', 'مالِ من');
    }

    /**
     * رسید جدا از خواندن است.
     *
     * اگر خودِ درخواستِ خواندن، اعلان را «رسیده» علامت بزند، اعلانی که
     * برنامه پیش از نمایشش بسته شد برای همیشه گم می‌شود.
     */
    #[Test]
    public function reading_the_list_does_not_consume_it_but_acknowledging_does(): void
    {
        $driver = $this->book('09123000001', '11')->driver;

        $notification = DriverNotification::create([
            'driver_id' => $driver->id, 'title' => 'خبر', 'body' => '…',
        ]);

        $this->actingAs($driver, 'driver')
            ->getJson(route('driver.api.notifications'))
            ->assertJsonCount(1, 'notifications');

        // هنوز نرسیده — پس دوباره می‌آید
        $this->actingAs($driver, 'driver')
            ->getJson(route('driver.api.notifications'))
            ->assertJsonCount(1, 'notifications');

        $this->actingAs($driver, 'driver')
            ->postJson(route('driver.api.notifications.ack'), ['ids' => [$notification->id]])
            ->assertOk()
            ->assertJsonPath('delivered', 1);

        $this->actingAs($driver, 'driver')
            ->getJson(route('driver.api.notifications'))
            ->assertJsonCount(0, 'notifications');
    }

    /** راننده نمی‌تواند اعلانِ راننده‌ی دیگری را «رسیده» کند */
    #[Test]
    public function acknowledging_someone_elses_notification_does_nothing(): void
    {
        $mine = $this->book('09123000001', '11')->driver;
        $theirs = $this->book('09123000002', '12')->driver;

        $hers = DriverNotification::create(['driver_id' => $theirs->id, 'title' => 'خبر', 'body' => '…']);

        $this->actingAs($mine, 'driver')
            ->postJson(route('driver.api.notifications.ack'), ['ids' => [$hers->id]])
            ->assertJsonPath('delivered', 0);

        $this->assertNull($hers->fresh()->delivered_at);
    }

    #[Test]
    public function a_role_without_the_permission_cannot_open_or_send(): void
    {
        $gate = User::role(Roles::GATE)->firstOrFail();

        $this->actingAs($gate)->get(route('staff.notifications.index'))->assertForbidden();

        $this->actingAs($gate)
            ->post(route('staff.notifications.store'), [
                'audience' => 'all', 'title' => 'x', 'body' => 'y',
            ])
            ->assertForbidden();

        $this->assertSame(0, DriverNotification::count());
    }

    /**
     * مسیر باید نسبی باشد.
     *
     * با پذیرفتنِ آدرس کامل، هر کسی که این دسترسی را دارد می‌تواند
     * راننده‌ها را با اعلانی که رسمی به نظر می‌رسد به سایت دلخواهش بفرستد.
     */
    #[Test]
    public function an_absolute_url_is_rejected_as_a_destination(): void
    {
        $this->book('09123000001', '11');

        $this->actingAs($this->manager())
            ->post(route('staff.notifications.store'), [
                'audience' => 'today',
                'title' => 'خبر',
                'body' => '…',
                'path' => 'https://example.com/phish',
            ])
            ->assertSessionHasErrors('path');

        $this->assertSame(0, DriverNotification::count());
    }

    #[Test]
    public function sending_to_one_driver_needs_a_mobile_that_exists(): void
    {
        $this->book('09123000001', '11');

        $this->actingAs($this->manager())
            ->post(route('staff.notifications.store'), [
                'audience' => 'one',
                'mobile' => '09129999999',
                'title' => 'خبر',
                'body' => '…',
            ])
            ->assertSessionHasErrors('mobile');

        $this->actingAs($this->manager())
            ->post(route('staff.notifications.store'), [
                'audience' => 'one',
                'mobile' => '09123000001',
                'title' => 'خبر',
                'body' => '…',
            ])
            ->assertSessionHas('success');

        $this->assertSame(1, DriverNotification::count());
    }

    #[Test]
    public function the_page_shows_how_many_drivers_each_group_reaches(): void
    {
        $this->book('09123000001', '11');
        $this->book('09123000002', '12');

        $this->actingAs($this->manager())
            ->get(route('staff.notifications.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Staff/Notifications')
                ->where('reach.today', 2)
                ->where('reach.all', 2)
                ->where('reach.in_queue', 0)
                ->has('audiences', 4));
    }

    /** خبرِ «نوبت جلویی شروع شد» هم باید روی برنامه دیده شود */
    #[Test]
    public function the_next_in_line_notice_reaches_the_app_as_well_as_the_phone(): void
    {
        $this->factory->update(['loading_lines' => 1]);

        $ahead = $this->book('09123000001', '11');
        $next = $this->book('09123000002', '12');

        // مستقیم به listener، نه از راه TransitionAppointment: رفتنِ واقعی
        // روی لاین اول وزن خالی باسکول را می‌خواهد و آن، موضوعِ این تست نیست.
        app(NotifyNextDriverWhenLoadingStarts::class)->handle(
            new AppointmentTransitioned($ahead->id, S::CheckedIn->value, S::Loading->value),
        );

        $notification = DriverNotification::where('driver_id', $next->driver_id)
            ->where('kind', 'next-up')
            ->first();

        $this->assertNotNull($notification, 'راننده‌ی بعدی اعلانِ درون‌برنامه‌ای نگرفت.');
        $this->assertStringContainsString('نوبت جلوتر از شما', $notification->body);
        $this->assertSame('/queue/appointments/'.$next->ulid, $notification->path);
    }
}
