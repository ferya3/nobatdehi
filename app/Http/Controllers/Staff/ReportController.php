<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Reporting\ReportService;
use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** بازه‌های آماده */
    private const RANGES = [
        'today' => 'امروز',
        'week' => '۷ روز اخیر',
        'month' => '۳۰ روز اخیر',
        'quarter' => '۹۰ روز اخیر',
    ];

    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can(Permissions::REPORTS_VIEW), 403);

        $factory = $this->factory($request);
        [$from, $to, $range] = $this->range($request);

        return Inertia::render('Staff/Reports', [
            'range' => $range,
            'ranges' => collect(self::RANGES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'from' => Jalali::date($from),
            'to' => Jalali::date($to),
            'summary' => $this->reports->summary($factory, $from, $to),
            'daily' => $this->reports->daily($factory, $from, $to),
            'byHour' => $this->reports->byHour($factory, $from, $to),
            'byProduct' => $this->reports->byProduct($factory, $from, $to),
            'byOperator' => $this->reports->byOperator($factory, $from, $to),
        ]);
    }

    /** خروجی CSV با BOM تا اکسل فارسی را درست باز کند */
    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->can(Permissions::REPORTS_VIEW), 403);

        $factory = $this->factory($request);
        [$from, $to] = $this->range($request);

        $rows = $this->reports->daily($factory, $from, $to);
        $filename = 'report-'.$from->toDateString().'-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');

            // BOM: بدون این، اکسل فارسی را جویده نشان می‌دهد
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['تاریخ', 'تاریخ میلادی', 'کل نوبت', 'تکمیل‌شده', 'عدم حضور']);

            foreach ($rows as $row) {
                fputcsv($handle, [$row['jalali'], $row['date'], $row['total'], $row['completed'], $row['no_show']]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    private function range(Request $request): array
    {
        $range = $request->string('range')->toString();
        $range = array_key_exists($range, self::RANGES) ? $range : 'week';

        $to = CarbonImmutable::today();

        $from = match ($range) {
            'today' => $to,
            'month' => $to->subDays(29),
            'quarter' => $to->subDays(89),
            default => $to->subDays(6),
        };

        return [$from, $to, $range];
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
