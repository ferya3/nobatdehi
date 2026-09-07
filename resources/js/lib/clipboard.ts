/**
 * کپی متن در کلیپ‌بورد، بدون وابستگی به secure context.
 *
 * navigator.clipboard فقط روی https یا localhost تعریف می‌شود. سامانه اغلب
 * با http و آی‌پیِ سرور باز می‌شود و آنجا این شیء اصلاً وجود ندارد — صدا
 * زدن مستقیمش یعنی خطا در همان کلیک.
 *
 * پس اول همان API استفاده می‌شود اگر باشد، وگرنه روش قدیمیِ textarea +
 * execCommand که در http هم کار می‌کند. اگر هیچ‌کدام نشد false برمی‌گردد تا
 * صفحه بتواند بگوید «دستی انتخاب کنید» و وانمود نکند کاری انجام شده.
 */
export async function copyText(value: string): Promise<boolean> {
    const api = (navigator as Navigator & { clipboard?: Clipboard }).clipboard;

    if (api?.writeText) {
        try {
            await api.writeText(value);

            return true;
        } catch {
            // اجازه‌ی کلیپ‌بورد رد شده — می‌رویم سراغ روش دوم
        }
    }

    return legacyCopy(value);
}

function legacyCopy(value: string): boolean {
    const field = document.createElement('textarea');

    field.value = value;
    // خارج از دید، ولی نه display:none — وگرنه انتخاب‌شدنی نیست
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.top = '-1000px';
    field.style.opacity = '0';

    document.body.appendChild(field);

    try {
        field.select();
        field.setSelectionRange(0, value.length);

        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(field);
    }
}
