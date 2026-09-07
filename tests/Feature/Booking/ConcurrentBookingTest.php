<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Domain\Appointment\Exceptions\BookingException;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * تست همزمانی واقعی: چند پروسه‌ی جدا هم‌زمان نوبت می‌گیرند.
 *
 * از RefreshDatabase استفاده نمی‌کنیم چون آن، تست را داخل یک تراکنش باز نگه
 * می‌دارد و پروسه‌های فرزند داده‌ی commit‌نشده را نمی‌بینند.
 *
 * و چون فرزندها واقعاً commit می‌کنند، این کلاس باید خودش تمیزکاری کند —
 * وگرنه ردیف‌هایش به تست‌های بعدی نشت می‌کند و آن‌ها به دلایلی شکست
 * می‌خورند که هیچ ربطی به خودشان ندارد.
 */
final class ConcurrentBookingTest extends TestCase
{
    use DatabaseMigrations, SeedsFactory;

    private const WORKERS = 12;

    #[Test]
    public function parallel_requests_never_land_two_trucks_on_one_line(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('برای این تست به افزونه‌ی pcntl نیاز است.');
        }

        $factory = $this->seedFactory();
        $factory->update([
            'max_active_per_mobile' => 5,
            'max_active_per_plate' => 5,
        ]);

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
                exit($this->bookInChild($factory->id, $driverId, $truckId));
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

        $this->assertSame(0, $rejected, 'در روز کاریِ خالی، همه باید نوبت بگیرند.');
        $this->assertSame(self::WORKERS, $succeeded);
        $this->assertSame(self::WORKERS, Appointment::count());

        // قانونی که جای «ظرفیت اسلات» را گرفت: دو کامیون نمی‌توانند هم‌زمان
        // روی یک لاین باشند. اگر قفل روز دور زده شود، اینجا تکرار پیدا می‌شود.
        $places = Appointment::get(['date', 'line_no', 'start_time'])
            ->map(fn ($a) => $a->date->toDateString().'|'.$a->line_no.'|'.$a->start_time)
            ->all();

        $this->assertSame(
            count($places),
            count(array_unique($places)),
            'دو نوبت روی یک لاین در یک لحظه نشسته‌اند.',
        );

        // شماره‌ی نوبت در هر روز باید یکتا و پشت سر هم باشد، نه تکراری
        foreach (Appointment::get(['date', 'number'])->groupBy(fn ($a) => $a->date->toDateString()) as $day) {
            $numbers = $day->pluck('number')->sort()->values()->all();
            $this->assertSame(range(1, count($numbers)), $numbers);
        }
    }

    /**
     * پاک‌کردن آنچه فرزندها commit کرده‌اند.
     *
     * DatabaseMigrations تراکنشی ندارد که برگردد، پس هر ردیفی که اینجا
     * ساخته شود تا انتهای اجرا می‌ماند و تست‌های بعدی را خراب می‌کند.
     */
    protected function tearDown(): void
    {
        DB::purge();
        Appointment::query()->delete();

        parent::tearDown();
    }

    /** داخل پروسه‌ی فرزند: اتصال تازه، یک تلاش رزرو، خروج با کد نتیجه */
    private function bookInChild(int $factoryId, int $driverId, int $truckId): int
    {
        DB::purge(); // اتصال والد را به ارث نبریم؛ سوکت مشترک PDO داده را خراب می‌کند

        try {
            $factory = \App\Models\Factory::findOrFail($factoryId);

            app(CreateAppointment::class)($this->booking(
                $factory,
                \App\Models\Driver::findOrFail($driverId),
                \App\Models\Truck::findOrFail($truckId),
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
