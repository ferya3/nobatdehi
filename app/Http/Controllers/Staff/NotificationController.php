<?php

declare(strict_types=1);

namespace App\Http\Controllers\Staff;

use App\Domain\Access\Permissions;
use App\Domain\Audit\AuditLogger;
use App\Domain\Notification\Audience;
use App\Domain\Notification\DriverNotifier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\SendDriverNotificationRequest;
use App\Models\DriverNotification;
use App\Models\Factory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function __construct(
        private readonly DriverNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeSend($request);

        $factory = $this->factory($request);

        return Inertia::render('Staff/Notifications', [
            'audiences' => Audience::options(),
            'reach' => $this->reach($factory->id),
            'sent' => $this->history(),
        ]);
    }

    public function store(SendDriverNotificationRequest $request): RedirectResponse
    {
        $factory = $this->factory($request);
        $data = $request->validated();
        $audience = Audience::from($data['audience']);

        $count = $this->notifier->broadcast(
            audience: $audience,
            factoryId: $factory->id,
            title: $data['title'],
            body: $data['body'],
            path: $data['path'] ?? null,
            mobile: $data['mobile'] ?? null,
            createdBy: $request->user()->id,
        );

        // موجودیتِ لاگ، خودِ کارخانه است: اعلانِ گروهی به هیچ ردیفِ واحدی
        // گره نمی‌خورد، ولی «چه کسی به راننده‌های این کارخانه چه گفت» باید بماند.
        $this->audit->log(
            action: 'SEND_DRIVER_NOTIFICATION',
            entity: $factory,
            newValues: [
                'audience' => $audience->value,
                'title' => $data['title'],
                'recipients' => $count,
            ],
        );

        if ($count === 0) {
            return back()->with('warning', 'هیچ راننده‌ای در این گروه نبود — اعلانی ساخته نشد.');
        }

        return back()->with('success', "اعلان برای {$count} راننده ثبت شد.");
    }

    /**
     * چند نفر در هر گروه هستند.
     *
     * مدیر باید قبل از زدن دکمه بداند دارد به سه نفر خبر می‌دهد یا به هزار نفر.
     *
     * @return array<string, int>
     */
    private function reach(int $factoryId): array
    {
        $counts = [];

        foreach (Audience::cases() as $audience) {
            // «یک راننده» تا شماره وارد نشود عددی ندارد
            $counts[$audience->value] = $audience === Audience::One
                ? 0
                : $audience->query($factoryId)->count();
        }

        return $counts;
    }

    /**
     * تاریخچه‌ی ارسال‌ها، یک ردیف به‌ازای هر پیام و نه هر گیرنده.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(): array
    {
        return DB::table('driver_notifications')
            ->leftJoin('users', 'users.id', '=', 'driver_notifications.created_by')
            ->whereNotNull('driver_notifications.created_by')
            ->groupBy('driver_notifications.title', 'driver_notifications.body', 'driver_notifications.created_at', 'users.name')
            ->orderByDesc('driver_notifications.created_at')
            ->limit(20)
            ->get([
                'driver_notifications.title',
                'driver_notifications.body',
                'driver_notifications.created_at',
                'users.name as sender',
                DB::raw('count(*) as recipients'),
                DB::raw('count(driver_notifications.delivered_at) as delivered'),
                DB::raw('count(driver_notifications.read_at) as read'),
            ])
            ->map(fn ($row) => [
                'title' => $row->title,
                'body' => $row->body,
                'sender' => $row->sender,
                'recipients' => (int) $row->recipients,
                'delivered' => (int) $row->delivered,
                'read' => (int) $row->read,
                'sent_at' => (string) $row->created_at,
            ])
            ->all();
    }

    private function authorizeSend(Request $request): void
    {
        abort_unless($request->user()?->can(Permissions::NOTIFICATIONS_SEND), 403);
    }

    private function factory(Request $request): Factory
    {
        return $request->user()->factory
            ?? Factory::where('is_active', true)->orderBy('id')->firstOrFail();
    }
}
