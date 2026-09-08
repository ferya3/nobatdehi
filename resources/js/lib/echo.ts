import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

let instance: Echo<'reverb'> | null = null;

/**
 * آدرس WebSocket، از روی همان صفحه‌ای که باز است.
 *
 * قبلاً میزبان از VITE_REVERB_HOST می‌آمد و آن در زمان build داخل
 * جاوااسکریپت پخته می‌شد. یعنی هر بار که دامنه عوض می‌شد، تا وقتی کسی
 * دوباره build نمی‌گرفت مرورگر سراغ میزبان قبلی می‌رفت — اتصال بی‌صدا
 * شکست می‌خورد و پنل تا ابد روی «هر ۲۰ ثانیه» می‌ماند. دقیقاً همین اتفاق
 * روی سرور افتاد.
 *
 * Reverb همیشه از پشت همان Nginx و روی همان دامنه سرو می‌شود، پس درست‌ترین
 * منبع، خودِ آدرس صفحه است. VITE_* فقط برای محیط توسعه می‌ماند، جایی که
 * Vite روی ۸۰۰۰ است و Reverb جدا روی ۸۰۸۰.
 */
function endpoint(): { host: string; port: number; tls: boolean } {
    const override = import.meta.env.VITE_REVERB_HOST;

    if (override) {
        const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';

        return {
            host: override,
            port: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
            tls: scheme === 'https',
        };
    }

    const tls = window.location.protocol === 'https:';

    return {
        host: window.location.hostname,
        // پورتِ صریحِ صفحه اگر باشد همان، وگرنه پیش‌فرضِ پروتکل
        port: Number(window.location.port || (tls ? 443 : 80)),
        tls,
    };
}

/**
 * اتصال Echo، با ساخت تنبل.
 *
 * اگر کلید Reverb تنظیم نشده باشد null برمی‌گرداند و صفحه‌ها به polling
 * برمی‌گردند — نبودِ WebSocket نباید پنل را از کار بیندازد.
 */
export function echo(): Echo<'reverb'> | null {
    if (instance) return instance;

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) return null;

    const { host, port, tls } = endpoint();

    window.Pusher = Pusher;

    instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: tls,
        enabledTransports: ['ws', 'wss'],

        // قطع‌شدن باید خودش برگردد و نه اینکه تا refresh بعدی منتظر بماند
        activityTimeout: 30_000,
        pongTimeout: 10_000,
    });

    window.Echo = instance;

    return instance;
}

export function hasRealtime(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}
