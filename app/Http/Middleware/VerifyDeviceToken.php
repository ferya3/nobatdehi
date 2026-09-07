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
            return $this->deny($request, $kind, 'unknown_kind');
        }

        // خاموش که باشد، مسیر اصلاً وجود ندارد — وجودِ مسیر هم اطلاعات است
        /*
         * پاسخِ «خاموش است» و «توکن غلط است» عمداً یکی است.
         *
         * تفاوت رفتاری بین این دو، به کسی که از بیرون امتحان می‌کند
         * می‌گوید کدام مسیر واقعاً وجود دارد و کدام دستگاه روشن است. دلیل
         * واقعی در لاگ امنیتی می‌ماند، نه در پاسخ.
         */
        if (! $this->tokens->enabled($kind)) {
            return $this->deny($request, $kind, 'disabled');
        }

        if (! $this->tokens->matches($kind, $this->tokenFrom($request))) {
            return $this->deny($request, $kind, 'bad_token');
        }

        return $next($request);
    }

    /**
     * فقط هدر — هرگز query string.
     *
     * توکنی که در URL بیاید در لاگ دسترسی Nginx، در history مرورگر و در
     * هدر Referer به مقصد بعدی می‌نشیند. دستگاهی که فقط query می‌فرستد
     * باید عوض شود، نه اینکه سامانه به آن تن بدهد.
     */
    private function tokenFrom(Request $request): ?string
    {
        return $request->header(self::HEADER)
            ?? $request->header(self::GATE_HEADER)
            ?? $request->bearerToken();
    }

    private function deny(Request $request, string $kind, string $reason): JsonResponse
    {
        $this->security->log(
            SecurityLogger::PERMISSION_DENIED,
            'device.'.$kind,
            context: ['reason' => $reason, 'path' => $request->path()],
            request: $request,
        );

        return response()->json(['message' => 'یافت نشد.'], 404);
    }
}
