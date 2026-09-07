<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Digits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * کد ملی ایران با رقم کنترل.
 *
 * فقط «۱۰ رقم بودن» کافی نیست: کد ملی جعلی در حواله یعنی بار به نام کسی
 * خارج می‌شود که وجود ندارد. رقم کنترل، تایپ اشتباه و عددِ ساختگی را می‌گیرد.
 */
final class NationalCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = Digits::toLatin((string) $value);

        if (! preg_match('/^\d{10}$/', $code)) {
            $fail('کد ملی باید ۱۰ رقم باشد.');

            return;
        }

        // ۰۰۰۰۰۰۰۰۰۰ تا ۹۹۹۹۹۹۹۹۹۹ در رقم کنترل می‌گذرند ولی کد ملی نیستند
        if (preg_match('/^(\d)\1{9}$/', $code)) {
            $fail('کد ملی معتبر نیست.');

            return;
        }

        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $check = (int) $code[9];

        $valid = $remainder < 2
            ? $check === $remainder
            : $check === 11 - $remainder;

        if (! $valid) {
            $fail('کد ملی معتبر نیست.');
        }
    }
}
