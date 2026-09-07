/**
 * شناسه‌ی یکتا برای کلید idempotency فرم‌ها.
 *
 * از crypto.randomUUID() استفاده نمی‌کنیم چون فقط در secure context
 * (https یا localhost) وجود دارد؛ روی سروری که با http و آی‌پی باز شود
 * undefined است و کل صفحه سفید می‌ماند. getRandomValues محدودیت
 * secure context ندارد و همه‌جا هست.
 */
export function uuid(): string {
    const c = globalThis.crypto;

    if (typeof c?.randomUUID === 'function') {
        return c.randomUUID();
    }

    const bytes = new Uint8Array(16);

    if (typeof c?.getRandomValues === 'function') {
        c.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
    }

    // نسخه ۴ و variant طبق RFC 4122
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;

    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
