<?php

declare(strict_types=1);

namespace App\Domain\Appointment\Exceptions;

use RuntimeException;

/**
 * خطای قابل نمایش به کاربر هنگام نوبت‌گیری.
 * $reason کلید ماشین‌خوان است تا Frontend بتواند رفتار متفاوت نشان دهد.
 */
class BookingException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function slotFull(): self
    {
        return new self('slot_full', 'ظرفیت این ساعت تکمیل شده است. لطفاً ساعت دیگری انتخاب کنید.');
    }

    public static function slotBlocked(): self
    {
        return new self('slot_blocked', 'این ساعت توسط کارخانه بسته شده است.');
    }

    public static function slotPast(): self
    {
        return new self('slot_past', 'این ساعت گذشته است یا برای نوبت‌گیری خیلی نزدیک است.');
    }

    public static function slotOutOfHorizon(int $days): self
    {
        return new self('slot_out_of_horizon', "نوبت‌گیری فقط تا {$days} روز آینده امکان‌پذیر است.");
    }

    public static function slotMismatch(): self
    {
        return new self('slot_mismatch', 'ساعت انتخاب‌شده متعلق به این کارخانه نیست.');
    }

    public static function dailyCapacityReached(): self
    {
        return new self('daily_capacity', 'ظرفیت نوبت‌دهی این روز تکمیل شده است.');
    }

    public static function mobileLimit(int $limit): self
    {
        return new self('mobile_limit', "با این شماره موبایل حداکثر {$limit} نوبت فعال می‌توانید داشته باشید.");
    }

    public static function plateLimit(int $limit): self
    {
        return new self('plate_limit', "برای این پلاک حداکثر {$limit} نوبت فعال ثبت می‌شود.");
    }

    public static function driverBlocked(?string $reason): self
    {
        return new self('driver_blocked', $reason ?: 'امکان نوبت‌گیری برای این راننده وجود ندارد.');
    }

    public static function truckBlocked(?string $reason): self
    {
        return new self('truck_blocked', $reason ?: 'امکان نوبت‌گیری برای این پلاک وجود ندارد.');
    }

    public static function productUnavailable(): self
    {
        return new self('product_unavailable', 'این محصول در حال حاضر قابل بارگیری نیست.');
    }
}
