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
 * زمانِ اعلام‌شده بعداً جابه‌جا نمی‌شود. اگر نوبتی لغو شود، جای خالی‌اش
 * همان‌جا می‌ماند و نوبت‌های بعدی جلو نمی‌افتند — راننده‌ای که پیامک «ساعت
 * ۱۰:۲۰» گرفته نباید ساعت ۹ کامیونش را از دست بدهد.
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

    /** اولین جای خالی در یک روز مشخص، یا null اگر آن روز جا نشود */
    public function openingOn(Factory $factory, CarbonImmutable $date, int $loadingMinutes): ?Opening
    {
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

        // مهلت که از ساعت تعطیلیِ این روز رد شده باشد، این روز اصلاً گزینه نیست
        if ($earliest->greaterThanOrEqualTo($closesAt)) {
            return null;
        }

        [$startsAt, $line] = $this->firstFreeLine($factory, $date, $opensAt, $earliest);

        $endsAt = $startsAt->addMinutes($loadingMinutes);

        // بارگیری باید تا ساعت تعطیلی تمام شود، نه اینکه شروع شود
        if ($endsAt->greaterThan($closesAt)) {
            return null;
        }

        return new Opening($date, $startsAt, $endsAt, $line, $loadingMinutes);
    }

    /**
     * زودترین لاینِ آزاد و لحظه‌ای که آزاد می‌شود.
     *
     * لاین‌ها از روی نوبت‌های همان روز بازسازی می‌شوند و نه از یک شمارنده:
     * شمارنده بعد از اولین لغو یا ویرایش، با واقعیت فاصله می‌گیرد و کسی
     * متوجه نمی‌شود تا روزی که دو کامیون هم‌زمان روی یک لاین بیفتند.
     *
     * نوبت‌های لغوشده هم حساب می‌شوند: جای خالی‌شان پر نمی‌شود.
     *
     * @return array{0: CarbonImmutable, 1: int}
     */
    private function firstFreeLine(
        Factory $factory,
        CarbonImmutable $date,
        CarbonImmutable $opensAt,
        CarbonImmutable $earliest,
    ): array {
        $lines = max(1, (int) $factory->loading_lines);

        $busyUntil = array_fill(1, $lines, $opensAt);

        $rows = Appointment::where('factory_id', $factory->id)
            ->whereDate('date', $date->toDateString())
            ->orderBy('start_time')
            ->get(['line_no', 'end_time']);

        foreach ($rows as $row) {
            $line = (int) $row->line_no;

            // نوبت‌های قدیمیِ پیش از لاین‌بندی، یا لاینی که دیگر وجود ندارد
            if ($line < 1 || $line > $lines) {
                $line = 1;
            }

            $endsAt = $this->at($date, (string) $row->end_time);

            if ($endsAt->greaterThan($busyUntil[$line])) {
                $busyUntil[$line] = $endsAt;
            }
        }

        $bestLine = 1;
        $bestStart = null;

        foreach ($busyUntil as $line => $freeAt) {
            $start = $freeAt->max($earliest);

            // مساوی که باشند، لاین کوچک‌تر برنده است تا ترتیب قابل پیش‌بینی بماند
            if ($bestStart === null || $start->lessThan($bestStart)) {
                $bestStart = $start;
                $bestLine = $line;
            }
        }

        return [$bestStart, $bestLine];
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
