<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\SecurityLogger;
use App\Domain\Gate\GateDevices;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * احراز هویت دوربین پلاک‌خوان.
 *
 * دوربین کاربر نیست و session ندارد؛ تنها چیزی که دارد یک توکن ثابت است که
 * در تنظیماتش تایپ شده. پس این مسیر عمداً بیرون از auth معمولی است — ولی
 * نه بیرون از حسابرسی: هر تلاشِ ناموفق در لاگ امنیتی می‌نشیند، چون یک مسیر
 * بی‌session که در اینترنت باز است، همان مسیری است که اول امتحان می‌شود.
 */
class VerifyGateDeviceToken
{
    public const HEADER = 'X-Gate-Token';

    public function __construct(
        private readonly GateDevices $devices,
        private readonly SecurityLogger $security,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->devices->anprEnabled()) {
            return $this->deny($request, 'disabled', 'اتصال دوربین پلاک‌خوان فعال نیست.', 404);
        }

        if (! $this->devices->tokenMatches($this->tokenFrom($request))) {
            return $this->deny($request, 'bad_token', 'توکن دستگاه معتبر نیست.', 401);
        }

        return $next($request);
    }

    /**
     * دوربین‌ها یکسان نیستند: بعضی هدر اختصاصی می‌فرستند، بعضی فقط
     * Authorization: Bearer دارند و بعضی جز query string چیزی نمی‌دهند.
     */
    private function tokenFrom(Request $request): ?string
    {
        return $request->header(self::HEADER)
            ?? $request->bearerToken()
            ?? $request->query('token');
    }

    private function deny(Request $request, string $reason, string $message, int $status): JsonResponse
    {
        $this->security->log(
            SecurityLogger::PERMISSION_DENIED,
            'gate.anpr',
            context: ['reason' => $reason, 'path' => $request->path()],
            request: $request,
        );

        return response()->json(['message' => $message], $status);
    }
}
