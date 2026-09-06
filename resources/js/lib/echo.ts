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
 * اتصال Echo، با ساخت تنبل.
 *
 * اگر کلید Reverb تنظیم نشده باشد null برمی‌گرداند و صفحه‌ها به polling
 * برمی‌گردند — نبودِ WebSocket نباید پنل را از کار بیندازد.
 */
export function echo(): Echo<'reverb'> | null {
    if (instance) return instance;

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) return null;

    window.Pusher = Pusher;

    instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    window.Echo = instance;

    return instance;
}

export function hasRealtime(): boolean {
    return Boolean(import.meta.env.VITE_REVERB_APP_KEY);
}
