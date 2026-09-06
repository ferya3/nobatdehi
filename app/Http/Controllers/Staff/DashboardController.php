<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Queue\QueueService;
use App\Http\Controllers\Controller;
use App\Models\Factory;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly QueueService $queue) {}

    public function __invoke(Request $request): Response
    {
        abort_unless($request->user()?->can(Permissions::DASHBOARD_VIEW), 403);

        $factory = $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();

        $today = CarbonImmutable::today();

        return Inertia::render('Staff/Dashboard', [
            'jalaliDate' => Jalali::long($today),
            'counters' => $this->queue->todayCounters($factory, $today),
            'avgLoadingMinutes' => $this->queue->averageLoadingMinutes($factory, $today)
                ?? $factory->avg_loading_minutes,
        ]);
    }
}
