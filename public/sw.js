/*
 * Service Worker پنل راننده.
 *
 * عمداً محافظه‌کارانه است: فقط پوسته و دارایی‌های ساخت را کش می‌کند.
 * صفحه‌های نوبت هرگز کش نمی‌شوند — نمایش وضعیت کهنه‌ی یک نوبت
 * («هنوز در انتظارید») بدتر از نمایش خطای شبکه است.
 */
const CACHE = 'nobat-shell-v1';
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;

    // دارایی‌های ساخت با hash نام‌گذاری شده‌اند، پس cache-first امن است
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ??
                    fetch(request).then((response) => {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                        return response;
                    }),
            ),
        );
        return;
    }

    // صفحه‌ها: همیشه شبکه؛ اگر شبکه نبود، صفحه‌ی آفلاین
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    }
});
