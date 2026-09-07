<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\Domain\Appointment\Actions\CreateAppointment;
use App\Models\Appointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsFactory;
use Tests\TestCase;

/**
 * مهاجرتِ لاین روی دیتابیسی که از قبل نوبت دارد.
 *
 * روی نصب واقعی شکست خورد: نوبت‌های قدیمی همه در یک اسلات نیم‌ساعته
 * می‌نشستند و با پیش‌فرضِ line_no = 1، همه یک کلید یکسان می‌ساختند.
 *
 * این تست همان وضعیت را بازمی‌سازد — چند نوبت با ساعت یکسان و لاین ۱ —
 * و ثابت می‌کند مهاجرت این بار از پسش برمی‌آید.
 */
final class LineBackfillMigrationTest extends TestCase
{
    use RefreshDatabase, SeedsFactory;

    private const MIGRATION = 'database/migrations/2026_09_07_060000_schedule_appointments_in_sequence.php';

    #[Test]
    public function the_migration_survives_appointments_that_share_one_slot(): void
    {
        $factory = $this->seedFactory();
        $this->freezeOnWorkingMorning($factory);

        $create = app(CreateAppointment::class);

        foreach (range(1, 5) as $i) {
            $create($this->booking(
                $factory,
                $this->makeDriver('0912000010'.$i),
                $this->makeTruck(str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'ب', '345', '11'),
            ));
        }

        $this->rewindToBeforeTheMigration();

        // همان چیزی که روی سرور بود: پنج نوبت، یک ساعت، همه روی لاین ۱
        $this->assertSame(1, Appointment::distinct()->count('start_time'));
        $this->assertSame([1], Appointment::distinct()->pluck('line_no')->all());

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--force' => true])
            ->assertSuccessful();

        // هر نوبت لاین خودش را گرفته و ساعت هیچ‌کس عوض نشده
        $rows = Appointment::orderBy('number')->get(['number', 'line_no', 'start_time']);

        $this->assertSame([1, 2, 3, 4, 5], $rows->pluck('line_no')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(1, $rows->pluck('start_time')->unique()->count(), 'ساعت کسی نباید جابه‌جا شود');

        // و ایندکس یکتا واقعاً ساخته شده است
        $this->assertTrue($this->uniqueIndexExists());
    }

    #[Test]
    public function the_migration_runs_on_an_empty_table_too(): void
    {
        $this->seedFactory();
        $this->rewindToBeforeTheMigration();

        $this->assertSame(0, Appointment::count());

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--force' => true])
            ->assertSuccessful();

        $this->assertTrue($this->uniqueIndexExists());
    }

    /**
     * دیتابیس را به حالتِ پیش از این مهاجرت برمی‌گرداند.
     *
     * ساعت‌ها هم به یک مقدار برگردانده می‌شوند: مدل قدیمی همه را در یک
     * اسلات نیم‌ساعته می‌نشاند و همان است که ایندکس یکتا را می‌شکست.
     */
    private function rewindToBeforeTheMigration(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_line_slot_unique');
        DB::statement('DROP INDEX IF EXISTS appointments_factory_id_date_line_no_index');
        DB::table('appointments')->update(['line_no' => 1, 'start_time' => '11:30:00', 'end_time' => '12:00:00']);

        DB::table('migrations')
            ->where('migration', '2026_09_07_060000_schedule_appointments_in_sequence')
            ->delete();
    }

    private function uniqueIndexExists(): bool
    {
        return DB::selectOne(
            "SELECT 1 AS ok FROM pg_indexes WHERE tablename = 'appointments' AND indexname = ?",
            ['appointments_line_slot_unique'],
        ) !== null;
    }
}
