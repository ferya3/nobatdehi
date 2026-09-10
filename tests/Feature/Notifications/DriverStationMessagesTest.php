<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Actions\TransitionAppointment;
use App\Domain\Appointment\Data\Actor;
use App\Domain\Appointment\Enums\AppointmentStatus as S;
use App\Models\Appointment;
use App\Models\Factory;
use App\Models\LoadingRecord;
use App\Models\Product;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * راننده باید بداند ایستگاه بعدی‌اش کجاست.
 *
 * دو لحظه‌ای که راننده در کامیون نشسته و منتظر است کسی چیزی بگوید: وقتی
 * نگهبان پلاکش را تأیید کرد، و وقتی بارگیری‌اش تمام شد. هر دو بار، تا پیش
 * از این همان تماس تلفنی‌ای لازم بود که این سامانه قرار است حذفش کند.
 */
final class DriverStationMessagesTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private Factory $factory;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = $this->seedFactory();
        $this->seed(SmsTemplateSeeder::class);
        $this->freezeOnWorkingMorning($this->factory);

        Setting::putMany(['sms_provider' => 'console']);

        $this->appointment = app(CreateAppointment::class)($this->booking(
            $this->factory,
            $this->makeDriver('09123456789'),
            $this->makeTruck('12', 'ب', '345', '11'),
            Product::where('factory_id', $this->factory->id)->orderBy('id')->firstOrFail(),
        ));
    }

    private function move(S $status): void
    {
        $this->appointment = app(TransitionAppointment::class)(
            $this->appointment->refresh(), $status, Actor::system(),
        );
    }

    private function bodyOf(string $key): ?string
    {
        return SmsMessage::where('template_key', $key)->value('body');
    }

    #[Test]
    public function the_driver_is_sent_to_the_first_scale_once_the_plate_is_confirmed(): void
    {
        $this->move(S::CheckedIn);

        $body = $this->bodyOf('appointment.checked-in');

        $this->assertNotNull($body, 'بعد از تأیید پلاک، راننده باید پیامک بگیرد.');
        $this->assertStringContainsString('باسکول', $body);
        $this->assertStringNotContainsString('{', $body);

        $this->assertDatabaseHas('sms_messages', [
            'to' => '09123456789',
            'template_key' => 'appointment.checked-in',
        ]);
    }

    #[Test]
    public function the_driver_is_sent_to_the_second_scale_when_loading_finishes(): void
    {
        $this->move(S::CheckedIn);

        LoadingRecord::create([
            'appointment_id' => $this->appointment->id,
            'empty_weight_kg' => 14000,
            'tare_weighed_at' => now(),
            'tare_source' => 'device',
        ]);

        $this->move(S::Loading);
        $this->move(S::Loaded);

        $body = $this->bodyOf('appointment.loaded');

        $this->assertNotNull($body, 'بعد از پایان بارگیری، راننده باید پیامک بگیرد.');
        $this->assertStringContainsString('باسکول', $body);
        $this->assertStringNotContainsString('{', $body);
    }

    #[Test]
    public function the_text_the_factory_wrote_is_the_text_the_driver_gets(): void
    {
        SmsTemplate::where('key', 'appointment.checked-in')->update([
            'body' => 'پلاک {plate} تأیید شد. به باسکول شماره یک بروید.',
        ]);

        $this->move(S::CheckedIn);

        $this->assertSame(
            'پلاک ۱۲ ب ۳۴۵ ایران ۱۱ تأیید شد. به باسکول شماره یک بروید.',
            $this->bodyOf('appointment.checked-in'),
        );
    }

    #[Test]
    public function switching_the_message_off_stops_it(): void
    {
        SmsTemplate::where('key', 'appointment.checked-in')->update(['is_active' => false]);

        $this->move(S::CheckedIn);

        $this->assertNull($this->bodyOf('appointment.checked-in'));
    }

    #[Test]
    public function the_call_message_no_longer_hardcodes_the_loading_yard(): void
    {
        // متنِ پیش‌فرض باید راننده را به نگهبانی بفرستد، نه مستقیم سرِ لاین:
        // کامیونی که از گیت رد نشده، اصلاً حق ورود به محوطه را ندارد
        $this->move(S::Called);

        $this->assertStringContainsString('نگهبانی', (string) $this->bodyOf('appointment.called'));
    }
}
