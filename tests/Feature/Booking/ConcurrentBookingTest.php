<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * تست همزمانی واقعی: چند پروسه‌ی جدا هم‌زمان یک اسلات را رزرو می‌کنند.
 *
 * از RefreshDatabase استفاده نمی‌کنیم چون آن، تست را داخل یک تراکنش باز نگه
 * می‌دارد و پروسه‌های فرزند داده‌ی commit‌نشده را نمی‌بینند.
 */
final class ConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations, SeedsFactory;

    private const WORKERS = 12;

    private const CAPACITY = 5;

    #[Test]
    public function parallel_requests_never_exceed_slot_capacity(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('برای این تست به افزونه‌ی pcntl نیاز است.');
        }

        $factory = $this->seedFactory();
        $factory->update([
            'max_active_per_mobile' => 5,
            'max_active_per_plate' => 5,
        ]);

        $slot = $this->futureSlot($factory);
        $slot->update(['capacity' => self::CAPACITY]);

        // راننده و کامیون‌ها را قبل از fork می‌سازیم تا فرزندها فقط رزرو کنند
        $pairs = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pairs[] = [
                $this->makeDriver('0912'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT))->id,
                $this->makeTruck(
                    str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'ب',
                    str_pad((string) (100 + $i), 3, '0', STR_PAD_LEFT), '67'
                )->id,
            ];
        }

        $children = [];

        foreach ($pairs as [$driverId, $truckId]) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('ساخت پروسه‌ی فرزند شکست خورد.');
            }

            if ($pid === 0) {
                exit($this->bookInChild($factory->id, $slot->id, $driverId, $truckId));
            }

            $children[] = $pid;
        }

        $succeeded = 0;
        $rejected = 0;

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $code = pcntl_wexitstatus($status);

            match ($code) {
                0 => $succeeded++,
                1 => $rejected++,
                default => $this->fail("پروسه‌ی فرزند با کد غیرمنتظره‌ی {$code} خارج شد."),
            };
        }

        DB::purge();

        $this->assertSame(self::CAPACITY, $succeeded, 'دقیقاً به اندازه‌ی ظرفیت باید نوبت صادر شود.');
        $this->assertSame(self::WORKERS - self::CAPACITY, $rejected);
        $this->assertSame(self::CAPACITY, Appointment::count());
        $this->assertSame(self::CAPACITY, AppointmentSlot::find($slot->id)->reserved_count);

        // شماره‌ی نوبت‌ها باید یکتا و پشت سر هم باشند، نه تکراری
        $numbers = Appointment::orderBy('number')->pluck('number')->all();
        $this->assertSame(range(1, self::CAPACITY), $numbers);
    }

    /** داخل پروسه‌ی فرزند: اتصال تازه، یک تلاش رزرو، خروج با کد نتیجه */
    private function bookInChild(int $factoryId, int $slotId, int $driverId, int $truckId): int
    {
        DB::purge(); // اتصال والد را به ارث نبریم؛ سوکت مشترک PDO داده را خراب می‌کند

        try {
            $factory = \App\Models\Factory::findOrFail($factoryId);
            $slot = AppointmentSlot::findOrFail($slotId);

            app(CreateAppointment::class)($this->booking(
                $factory,
                \App\Models\Driver::findOrFail($driverId),
                \App\Models\Truck::findOrFail($truckId),
                $slot,
            ));

            return 0;
        } catch (BookingException) {
            return 1;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage()."\n");

            return 2;
        }
    }
}
