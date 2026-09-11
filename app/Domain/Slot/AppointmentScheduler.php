<?php

declare(strict_types=1);

namespace App\Domain\Slot;

use App\Domain\Appointment\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Factory;
use Carbon\CarbonImmutable;

/**
 * سامانه ترتیب را اعلام می‌کند، نه راننده.
 *
 * کارخانه مطب دکتر نیست: راننده ساعت نمی‌چیند، در صف می‌ایستد. اولین نوبتِ
 * هر روز از لحظه‌ی باز شدن کارخانه (یا همین حالا، هر کدام دیرتر) شروع
 * می‌شود و هر نوبت بعدی از جایی که نوبت قبلی روی همان لاین تمام شده.
 *
 * مدت هر نوبت از نوع کامیون می‌آید — تریلی و خاور یک اندازه جا نمی‌گیرند و
 * صفی که این را نادیده بگیرد، تا ظهر از برنامه عقب است.
 *
 * لاین‌های موازی: کارخانه‌ای که سه لاین بارگیری دارد سه کامیون را هم‌زمان
 * می‌برد. هر نوبت روی لاینی می‌نشیند که زودتر از همه آزاد می‌شود.
 *
 * زمانِ اعلام‌شده بعداً جابه‌جا نمی‌شود: نوبت‌هایی که همین حالا در صف‌اند
 * سر جای خودشان می‌مانند و هرگز جلو کشیده نمی‌شوند — راننده‌ای که پیامک
 * «ساعت ۱۰:۲۰» گرفته نباید ساعت ۹ کامیونش را از دست بدهد.
 *
 * ولی بازه‌ی نوبتی که لغو شده به نوبتِ *تازه* داده می‌شود. کامیونی نیامده و
 * لاین آن ساعت خالی است؛ اگر آزاد نشود، هر لغو یک تکه از ظرفیت آن روز را
 * برای همیشه می‌سوزاند و بعد از چند لغو، اولین نوبتِ صبح ساعت‌ها دیرتر
 * اعلام می‌شود.
 */
final class AppointmentScheduler
{
    /**
     * ساعت اعلام‌شده روی مضربی از این عدد می‌نشیند.
     *
     * بدون این، نوبتی که در ساعت ۱۴:۰۵:۴۶ گرفته شود «۱۵:۰۵:۴۶» می‌شد —
     * عددی که نه روی قبض جا می‌شود و نه کسی سرِ آن حاضر می‌شود. رُند به
     * بالا و نه پایین: زمانِ زودتر از آنچه ممکن است، وعده‌ای است که همان
     * روز اول شکسته می‌شود.
     */
    private const ROUND_TO_MINUTES = 5;

    public function __construct(private readonly SlotGenerator $plans) {}

    /**
     * اولین جای خالی برای کامیونی که این‌قدر طول می‌کشد.
     *
     * از امروز تا انتهای افق نوبت‌دهی می‌گردد و اولین روزی را برمی‌گرداند که
     * این کامیون تا پیش از ساعت تعطیلی جا شود. null یعنی تا انتهای افق جایی
     * نیست.
     */
    public function nextOpening(Factory $factory, int $loadingMinutes): ?Opening
    {
        $loadingMinutes = max(1, $loadingMinutes);
        $today = CarbonImmutable::today();

        for ($offset = 0; $offset <= $factory->booking_horizon_days; $offset++) {
            $opening = $this->openingOn($factory, $today->addDays($offset), $loadingMinutes);

            if ($opening !== null) {
                return $opening;
            }
        }

        return null;
    }

    /**
     * اولین جای خالی در یک روز مشخص، یا null اگر آن روز جا نشود.
     *
     * $notBefore کفِ راننده است، نه کفِ کارخانه: وقتی کسی «فردا حوالی ۱۰»
     * می‌خواهد، سامانه اولین جای خالیِ ساعت ۱۰ به بعد را می‌دهد — نه ساعت
     * هفت صبح که آن روز اولین جای خالی است.
     */
    public function openingOn(
        Factory $factory,
        CarbonImmutable $date,
        int $loadingMinutes,
        ?CarbonImmutable $notBefore = null,
    ): ?Opening {
        $plan = $this->plans->planFor($factory, $date);

        if ($plan === null) {
            return null;    // تعطیل
        }

        [$opensAt, $closesAt] = $plan;

        if ($this->dayIsFull($factory, $date)) {
            return null;
        }

        // «همین حالا به‌علاوه‌ی مهلت رسیدن» روی هر روز اعمال می‌شود و نه فقط
        // امروز: مهلت یعنی راننده چقدر وقت لازم دارد تا خودش را برساند، و
        // این با عوض‌شدن تاریخ از بین نمی‌رود. برای روزهای دور معمولاً بی‌اثر
        // است، ولی مهلتِ دو روزه باید فردا را هم رد کند.
        $earliest = $this->roundUp(
            $opensAt->max(CarbonImmutable::now()->addMinutes((int) $factory->booking_lead_minutes)),
        );

        if ($notBefore !== null) {
            $earliest = $this->roundUp($earliest->max($notBefore));
        }

        // مهلت که از ساعت تعطیلیِ این روز رد شده باشد، این روز اصلاً گزینه نیست
        if ($earliest->greaterThanOrEqualTo($closesAt)) {
            return null;
        }

        [$startsAt, $line] = $this->firstFreeLine($factory, $date, $opensAt, $earliest, $loadingMinutes);

        $endsAt = $startsAt->addMinutes($loadingMinutes);

        // بارگیری باید تا ساعت تعطیلی تمام شود، نه اینکه شروع شود
        if ($endsAt->greaterThan($closesAt)) {
            return null;
        }

        return new Opening($date, $startsAt, $endsAt, $line, $loadingMinutes);
    }

    /**
     * ساعت‌های یک روز، با این خبر که این کامیون در هرکدام جا می‌شود یا نه.
     *
     * راننده‌ای که روز خاصی می‌خواهد، نباید ساعتی را تایپ کند و بعد بشنود
     * «جا نیست». پنجره‌ها از همان طولی ساخته می‌شوند که کارخانه برای
     * اسلات‌هایش تعریف کرده، و هرکدام می‌گوید اگر از اینجا شروع کنی، نوبت
     * واقعاً کِی می‌افتد.
     *
     * عددها تا لحظه‌ی ثبت تخمینی‌اند: بین دیدن و زدن دکمه ممکن است کسی
     * دیگر نوبت گرفته باشد. زمان‌بند داخل قفلِ روز دوباره حساب می‌کند.
     *
     * @return array<int, array{time: string, available: bool, starts_at: ?string, ends_at: ?string}>
     */
    public function windowsOn(Factory $factory, CarbonImmutable $date, int $loadingMinutes): array
    {
        $plan = $this->plans->planFor($factory, $date);

        if ($plan === null) {
            return [];
        }

        [$opensAt, $closesAt] = $plan;

        $step = max(15, (int) $factory->slot_minutes);
        $windows = [];

        for ($cursor = $opensAt; $cursor->lessThan($closesAt); $cursor = $cursor->addMinutes($step)) {
            $opening = $this->openingOn($factory, $date, $loadingMinutes, $cursor);

            // نوبتی که خیلی دیرتر از پنجره‌ی خواسته‌شده بیفتد، جوابِ این
            // پنجره نیست — جوابِ پنجره‌ای جلوتر است و همان‌جا نشان داده می‌شود.
            $fits = $opening !== null && $opening->startsAt->lessThan($cursor->addMinutes($step));

            $windows[] = [
                'time' => $cursor->format('H:i'),
                'available' => $fits,
                'starts_at' => $fits ? $opening->startsAt->format('H:i') : null,
                'ends_at' => $fits ? $opening->endsAt->format('H:i') : null,
            ];
        }

        return $windows;
    }

    /**
     * زودترین جایی که این کامیون روی یکی از لاین‌ها جا می‌شود.
     *
     * لاین‌ها از روی نوبت‌های همان روز بازسازی می‌شوند و نه از یک شمارنده:
     * شمارنده بعد از اولین لغو یا ویرایش، با واقعیت فاصله می‌گیرد و کسی
     * متوجه نمی‌شود تا روزی که دو کامیون هم‌زمان روی یک لاین بیفتند.
     *
     * فاصله‌های خالیِ وسط روز هم گزینه‌اند، نه فقط انتهای صف. نوبتی که لغو
     * می‌شود یک حفره‌ی واقعی در برنامه باقی می‌گذارد؛ اگر فقط «آخرین لحظه‌ی
     * آزاد بودنِ لاین» را نگاه کنیم، آن حفره تا آخر روز خالی می‌ماند و
     * کامیون‌ها بی‌دلیل عقب می‌افتند.
     *
     * لغوشده و عدم‌حضور اصلاً جایی اشغال نمی‌کنند: آن کامیون نیامده. ولی
     * تکمیل‌شده اشغال می‌کند، چون واقعاً لاین را گرفته بود.
     *
     * @return array{0: CarbonImmutable, 1: int}
     */
    private function firstFreeLine(
        Factory $factory,
        CarbonImmutable $date,
        CarbonImmutable $opensAt,
        CarbonImmutable $earliest,
        int $loadingMinutes,
    ): array {
        $lines = max(1, (int) $factory->loading_lines);
        $floor = $opensAt->max($earliest);

        $bestLine = 1;
        $bestStart = null;

        foreach ($this->busyByLine($factory, $date, $lines) as $line => $intervals) {
            $start = $this->firstGap($intervals, $floor, $loadingMinutes);

            // مساوی که باشند، لاین کوچک‌تر برنده است تا ترتیب قابل پیش‌بینی بماند
            if ($bestStart === null || $start->lessThan($bestStart)) {
                $bestStart = $start;
                $bestLine = $line;
            }
        }

        return [$bestStart, $bestLine];
    }

    /**
     * بازه‌های اشغالِ هر لاین، مرتب‌شده بر اساس شروع.
     *
     * @return array<int, array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>>
     */
    private function busyByLine(Factory $factory, CarbonImmutable $date, int $lines): array
    {
        $busy = array_fill(1, $lines, []);

        $rows = Appointment::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->whereNotIn('status', AppointmentStatus::releasedValues())
            ->orderBy('start_time')
            ->get(['line_no', 'start_time', 'end_time']);

        foreach ($rows as $row) {
            $line = (int) $row->line_no;

            // نوبت‌های قدیمیِ پیش از لاین‌بندی، یا لاینی که دیگر وجود ندارد
            if ($line < 1 || $line > $lines) {
                $line = 1;
            }

            $busy[$line][] = [
                $this->at($date, (string) $row->start_time),
                $this->at($date, (string) $row->end_time),
            ];
        }

        return $busy;
    }

    /**
     * اولین لحظه‌ای که از $floor به بعد، $minutes دقیقه پشت سر هم آزاد است.
     *
     * @param  array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $intervals
     */
    private function firstGap(array $intervals, CarbonImmutable $floor, int $minutes): CarbonImmutable
    {
        $cursor = $floor;

        foreach ($intervals as [$start, $end]) {
            if ($end->lessThanOrEqualTo($cursor)) {
                continue;   // کاملاً پشت سر ماند
            }

            // تا شروعِ این نوبت، به اندازه‌ی کافی جا هست؟
            if ($start->greaterThanOrEqualTo($cursor->addMinutes($minutes))) {
                return $cursor;
            }

            $cursor = $end;
        }

        return $cursor;
    }

    /** سقف روزانه‌ی کارخانه — جدا از ساعات کاری */
    private function dayIsFull(Factory $factory, CarbonImmutable $date): bool
    {
        $booked = Appointment::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->whereIn('status', AppointmentStatus::activeValues())
            ->count();

        return $booked >= (int) $factory->daily_capacity;
    }

    /** به بالا، روی نزدیک‌ترین مضرب دقیقه‌ی رُند */
    private function roundUp(CarbonImmutable $moment): CarbonImmutable
    {
        $step = self::ROUND_TO_MINUTES;
        $clean = $moment->startOfMinute();

        $remainder = $clean->minute % $step;

        // ثانیه که داشته باشد، همان دقیقه هم کامل نشده است
        if ($remainder === 0 && $moment->second === 0) {
            return $clean;
        }

        return $clean->addMinutes($step - $remainder);
    }

    private function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.substr($time, 0, 8));
    }
}
