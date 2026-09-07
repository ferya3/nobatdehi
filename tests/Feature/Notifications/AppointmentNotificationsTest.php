<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Access\Roles;
use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Events\AppointmentCreated;
use App\Events\AppointmentStatusChanged;
use App\Events\AppointmentTransitioned;
use App\Events\QueueChanged;
use App\Listeners\BroadcastQueueChange;
use App\Listeners\NotifyDriverWhenCalled;
use App\Listeners\NotifyDriverWhenCancelled;
use App\Listeners\NotifyOnAppointmentCreated;
use App\Models\Appointment;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

final class AppointmentNotificationsTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private \App\Models\Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->seed(\Database\Seeders\UserSeeder::class);
        $this->seed(\Database\Seeders\SmsTemplateSeeder::class);

        // در تست، پنل واقعی صدا زده نمی‌شود
        Setting::putMany(['sms_provider' => 'console']);
    }

    private function book(string $mobile = '09123456789'): Appointment
    {
        return app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver($mobile),
            $this->makeTruck('12', 'ب', '345', '67'),
            $this->futureSlot($this->factory),
        ));
    }

    #[Test]
    public function creating_an_appointment_dispatches_the_created_event(): void
    {
        Event::fake([AppointmentCreated::class]);

        $appointment = $this->book();

        Event::assertDispatched(
            AppointmentCreated::class,
            fn (AppointmentCreated $e) => $e->appointmentId === $appointment->id,
        );
    }

    #[Test]
    public function the_driver_and_the_manager_both_get_an_sms_for_a_new_appointment(): void
    {
        Setting::putMany(['sms_manager_recipients' => '09120000099']);

        // صف در تست sync است، پس Listener واقعی خودش اجرا می‌شود
        $appointment = $this->book();

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09123456789',
            'template_key' => 'appointment.created.driver',
        ]);

        $this->assertSame(
            1,
            SmsMessage::where('template_key', 'appointment.created.driver')->count(),
            'برای هر نوبت فقط یک پیامک باید صادر شود.',
        );

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09120000099',
            'template_key' => 'appointment.created.manager',
        ]);

        // متن باید واقعاً جای‌گذاری شده باشد، نه {number} خام
        $body = SmsMessage::where('template_key', 'appointment.created.driver')->value('body');

        $this->assertStringNotContainsString('{', $body);
        $this->assertStringContainsString((string) $appointment->number, $body);
    }

    #[Test]
    public function the_sms_is_queued_rather_than_sent_inside_the_request(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        app(NotifyOnAppointmentCreated::class)->handle(new AppointmentCreated($this->book()->id));

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendSmsMessage::class);
        $this->assertSame('QUEUED', SmsMessage::firstOrFail()->status);
    }

    #[Test]
    public function calling_a_driver_sends_them_an_sms(): void
    {
        $appointment = $this->book();
        $operator = User::role(Roles::OPERATOR)->firstOrFail();
        $transition = app(TransitionAppointment::class);

        $appointment = $transition($appointment, S::Waiting, Actor::user($operator));
        $appointment = $transition($appointment, S::Called, Actor::user($operator));

        app(NotifyDriverWhenCalled::class)->handle(
            new AppointmentTransitioned($appointment->id, S::Waiting->value, S::Called->value),
        );

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09123456789',
            'template_key' => 'appointment.called',
        ]);
    }

    #[Test]
    public function no_sms_is_sent_for_a_transition_the_driver_does_not_care_about(): void
    {
        $appointment = $this->book();

        app(NotifyDriverWhenCalled::class)->handle(
            new AppointmentTransitioned($appointment->id, S::Booked->value, S::Waiting->value),
        );

        $this->assertSame(0, SmsMessage::where('template_key', 'appointment.called')->count());
    }

    #[Test]
    public function a_factory_cancellation_notifies_the_driver(): void
    {
        $appointment = $this->book();
        $manager = User::role(Roles::FACTORY_MANAGER)->firstOrFail();

        app(TransitionAppointment::class)($appointment, S::Cancelled, Actor::user($manager), 'تعطیلی اضطراری');

        app(NotifyDriverWhenCancelled::class)->handle(
            new AppointmentTransitioned($appointment->id, S::Booked->value, S::Cancelled->value),
        );

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09123456789',
            'template_key' => 'appointment.cancelled',
        ]);
    }

    #[Test]
    public function a_driver_cancelling_their_own_appointment_gets_no_pointless_sms(): void
    {
        $appointment = $this->book();
        $driver = $appointment->driver;

        app(TransitionAppointment::class)($appointment, S::Cancelled, Actor::driver($driver), 'لغو توسط راننده');

        app(NotifyDriverWhenCancelled::class)->handle(
            new AppointmentTransitioned($appointment->id, S::Booked->value, S::Cancelled->value),
        );

        $this->assertSame(0, SmsMessage::where('template_key', 'appointment.cancelled')->count());
    }

    #[Test]
    public function a_transition_broadcasts_to_the_queue_and_to_the_driver(): void
    {
        Event::fake([QueueChanged::class, AppointmentStatusChanged::class]);

        $appointment = $this->book();
        $operator = User::role(Roles::OPERATOR)->firstOrFail();

        app(TransitionAppointment::class)($appointment, S::Waiting, Actor::user($operator));

        app(BroadcastQueueChange::class)->handle(
            new AppointmentTransitioned($appointment->id, S::Booked->value, S::Waiting->value),
        );

        Event::assertDispatched(
            QueueChanged::class,
            fn (QueueChanged $e) => $e->factoryId === $this->factory->id && $e->appointmentNumber === $appointment->number,
        );

        Event::assertDispatched(
            AppointmentStatusChanged::class,
            fn (AppointmentStatusChanged $e) => $e->driverId === $appointment->driver_id
                && $e->ulid === $appointment->ulid
                && $e->status === S::Waiting->value,
        );
    }

    #[Test]
    public function broadcast_payloads_carry_no_personal_data(): void
    {
        $appointment = $this->book();

        $queue = new QueueChanged($this->factory->id, $appointment->date->toDateString(), $appointment->number, 'WAITING');
        $status = new AppointmentStatusChanged($appointment->driver_id, $appointment->ulid, 'WAITING', 'در انتظار');

        $payload = json_encode([$queue->broadcastWith(), $status->broadcastWith()], JSON_UNESCAPED_UNICODE);

        // نه شماره موبایل، نه پلاک، نه نام راننده روی کانال نمی‌رود
        $this->assertStringNotContainsString($appointment->driver->mobile, $payload);
        $this->assertStringNotContainsString($appointment->truck->plate_key, $payload);
    }

    #[Test]
    public function the_channels_are_private_and_scoped(): void
    {
        $appointment = $this->book();

        $queue = new QueueChanged($this->factory->id, $appointment->date->toDateString());
        $status = new AppointmentStatusChanged($appointment->driver_id, $appointment->ulid, 'WAITING', 'در انتظار');

        $names = collect($queue->broadcastOn())->merge($status->broadcastOn())
            ->map(fn ($channel) => (string) $channel)
            ->all();

        $this->assertContains("private-factory.{$this->factory->id}.queue", $names);
        $this->assertContains("private-driver.{$appointment->driver_id}", $names);
        $this->assertContains("private-appointment.{$appointment->ulid}", $names);
    }
}
