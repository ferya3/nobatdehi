<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\SecurityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * لاگ امنیتی — جدا از Audit Log.
 * اینجا «چه کسی تلاش کرد» ثبت می‌شود، نه «چه داده‌ای عوض شد».
 */
final class SecurityLogger
{
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_FAILED = 'login_failed';
    public const LOGOUT = 'logout';
    public const OTP_REQUESTED = 'otp_requested';
    public const OTP_VERIFIED = 'otp_verified';
    public const OTP_FAILED = 'otp_failed';
    public const OTP_EXPIRED = 'otp_expired';
    public const RATE_LIMIT = 'rate_limit';
    public const PERMISSION_DENIED = 'permission_denied';
    public const SUSPICIOUS_REQUEST = 'suspicious_request';
    public const ADMIN_ACTION = 'admin_action';
    public const QR_INVALID = 'qr_invalid';
    public const QR_REPLAY = 'qr_replay';

    /** ورود بدون اسکن QR — یا تلاش برایش، یا استثنایی که مدیر داده */
    public const GATE_NO_QR = 'gate_no_qr';

    /** پلاکِ دیده‌شده با پلاکِ حواله یکی نبود */
    public const GATE_PLATE_MISMATCH = 'gate_plate_mismatch';

    public function log(
        string $event,
        ?string $identifier = null,
        ?Model $subject = null,
        array $context = [],
        ?Request $request = null,
    ): SecurityLog {
        $request ??= request();

        return SecurityLog::create([
            'event' => $event,
            'identifier' => $identifier,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'ip' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'context' => $context ?: null,
            'created_at' => now(),
        ]);
    }
}
