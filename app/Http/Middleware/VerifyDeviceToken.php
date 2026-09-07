<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\SecurityLogger;
use App\Domain\Devices\DeviceTokens;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * احراز هویت دستگاه‌هایی که کاربر نیستند: دوربین پلاک‌خوان و پل باسکول.
 *
 * این مسیرها عمداً بیرون از auth معمولی‌اند — دستگاه session ندارد — ولی
 * نه بیرون از حسابرسی: هر تلاش ناموفق در لاگ امنیتی می‌نشیند، چون یک مسیر
 * بی‌session که در شبکه باز است، همان مسیری است که اول امتحان می‌شود.
 */
class VerifyDeviceToken
{
    /** هدر عمومی؛ هدر قدیمیِ گیت هم پذیرفته می‌شود */
    public const HEADER = 'X-Device-Token';

    public const GATE_HEADER = 'X-Gate-Token';

    public function __construct(
        private readonly DeviceTokens $tokens,
        private readonly SecurityLogger $security,
    ) {}

    public function handle(Request $request, Closure $next, string $kind): Response
    {
        if (! DeviceTokens::isKind($kind)) {
            return $this->deny($request, $kind, 'unknown_kind', 'نوع دستگاه ناشناخته است.', 404);
        }

        // خاموش که باشد، مسیر اصلاً وجود ندارد — وجودِ مسیر هم اطلاعات است
        if (! $this->tokens->enabled($kind)) {
            return $this->deny($request, $kind, 'disabled', 'این اتصال فعال نیست.', 404);
        }

        if (! $this->tokens->matches($kind, $this->tokenFrom($request))) {
            return $this->deny($request, $kind, 'bad_token', 'توکن دستگاه معتبر نیست.', 401);
        }

        return $next($request);
    }

    /**
     * دستگاه‌ها یکسان نیستند: بعضی هدر اختصاصی می‌فرستند، بعضی فقط
     * Authorization: Bearer دارند و بعضی جز query string چیزی نمی‌دهند.
     */
    private function tokenFrom(Request $request): ?string
    {
        return $request->header(self::HEADER)
            ?? $request->header(self::GATE_HEADER)
            ?? $request->bearerToken()
            ?? $request->query('token');
    }

    private function deny(Request $request, string $kind, string $reason, string $message, int $status): JsonResponse
    {
        $this->security->log(
            SecurityLogger::PERMISSION_DENIED,
            'device.'.$kind,
            context: ['reason' => $reason, 'path' => $request->path()],
            request: $request,
        );

        return response()->json(['message' => $message], $status);
    }
}
