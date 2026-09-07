<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Data\NewAppointment;
use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Domain\Queue\QueueService;
use App\Domain\Sms\SmsService;
use App\Events\QueueChanged;
use App\Listeners\NotifyOnAppointmentCreated;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\Product;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * راننده نوبت می‌گیرد و پنل اپراتور باید همان لحظه خبردار شود.
 *
 * قبلاً این خبر از دل listener پیامک رد می‌شد که ShouldQueue است؛ یعنی
 * خوابیدنِ Horizon پنل را بی‌صدا کور می‌کرد. تست‌های زیر همان جدایی را
 * قفل می‌کنند.
 */
final class QueueLivenessTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($this->factory);
    }

    private function book(string $mobile = '09123456789', string $two = '12'): Appointment
    {
        return app(CreateAppointment::class)(new NewAppointment(
            factory: $this->factory,
            driver: $this->makeDriver($mobile),
            truck: $this->makeTruck($two, 'ب', '345', '11'),
            product: Product::where('factory_id', $this->factory->id)->firstOrFail(),
            ip: '127.0.0.1',
        ));
    }

    #[Test]
    public function test_a_new_booking_reaches_the_panel_even_with_no_queue_worker(): void
    {
        Event::fake([QueueChanged::class]);

        // هیچ job ای اجرا نمی‌شود — دقیقاً حالتی که Horizon خوابیده
        Queue::fake();

        $appointment = $this->book();

        Event::assertDispatched(
            QueueChanged::class,
            fn (QueueChanged $e) => $e->factoryId === $this->factory->id
                && $e->appointmentNumber === $appointment->number
                && $e->date === $appointment->date->toDateString(),
        );
    }

    #[Test]
    public function test_a_status_change_reaches_the_panel_with_no_queue_worker(): void
    {
        $appointment = $this->book();

        Event::fake([QueueChanged::class]);
        Queue::fake();

        app(TransitionAppointment::class)($appointment, AppointmentStatus::Waiting, Actor::system());

        Event::assertDispatched(QueueChanged::class);
    }

    #[Test]
    public function test_the_queue_event_is_not_itself_a_queued_job(): void
    {
        /*
         * ShouldBroadcast (بدون Now) خودِ انتشار را هم به صف می‌سپارد، و
         * آن‌وقت جدا کردن listener هیچ فایده‌ای ندارد.
         */
        $this->assertInstanceOf(ShouldBroadcastNow::class, new QueueChanged(1, '2026-09-07'));
    }

    #[Test]
    public function test_the_sms_listener_stays_queued_and_no_longer_owns_the_panel_update(): void
    {
        // پیامک می‌تواند صبر کند و باید در صف بماند
        $this->assertInstanceOf(ShouldQueue::class, new NotifyOnAppointmentCreated(
            app(SmsService::class),
            app(QueueService::class),
        ));

        $source = file_get_contents(app_path('Listeners/NotifyOnAppointmentCreated.php'));

        $this->assertStringNotContainsString(
            'QueueChanged',
            (string) $source,
            'اطلاع‌رسانی به پنل نباید دوباره به listener پیامک برگردد.',
        );
    }

    #[Test]
    public function test_a_broadcast_failure_never_breaks_the_booking(): void
    {
        // Reverb در دسترس نیست
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.options.host' => '127.0.0.1', 'broadcasting.connections.reverb.options.port' => 1]);

        $appointment = $this->book();

        // نوبت ثبت شده — همان چیزی که برای راننده مهم است
        $this->assertDatabaseHas('appointments', ['id' => $appointment->id]);
    }
}
