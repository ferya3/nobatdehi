const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

/**
 * ارقام لاتین را به فارسی تبدیل می‌کند.
 *
 * معمولاً لازم نیست: فونت وزیرمتن با ویژگی ss01 ارقام لاتین را فارسی نمایش
 * می‌دهد و متن زیرین لاتین می‌ماند، پس کپی‌پیست به سامانه‌های دیگر سالم است.
 * این تابع فقط برای جایی است که واقعاً به کدپوینت فارسی نیاز داریم — مثل
 * متن پیامک یا مقدار یک attribute.
 */
export function fa(value: string | number | null | undefined): string {
    if (value === null || value === undefined) return '';
    return String(value).replace(/\d/g, (d) => PERSIAN_DIGITS[Number(d)]);
}

/** ارقام فارسی/عربی را برای ارسال به سرور به لاتین برمی‌گرداند. */
export function latin(value: string): string {
    return value
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660));
}

export function digitsOnly(value: string): string {
    return latin(value).replace(/\D+/g, '');
}

/** ۰۹۱۲ ۳۴۵ ۶۷۸۹ — فاصله‌گذاری برای خوانایی، بدون دست‌زدن به خود ارقام */
export function prettyMobile(mobile: string): string {
    const d = digitsOnly(mobile);
    if (d.length !== 11) return mobile;
    return `${d.slice(0, 4)} ${d.slice(4, 7)} ${d.slice(7)}`;
}

/** ثانیه به mm:ss */
export function countdown(seconds: number): string {
    const total = Math.max(0, Math.floor(seconds));
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

/** ۱٬۲۳۴ — جداکننده‌ی هزارگان */
export function num(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return '—';
    return Number(value).toLocaleString('en-US');
}
