import { echo } from '@/lib/echo';
import { onUnmounted, ref, type Ref } from 'vue';

/**
 * اشتراک در یک کانال خصوصی، با پاک‌سازی خودکار.
 *
 * `connected` می‌گوید آیا واقعاً به WebSocket وصل شدیم یا نه؛ صفحه با همین
 * تصمیم می‌گیرد که polling پشتیبان را روشن نگه دارد یا نه.
 *
 * وضعیت از خودِ اتصال خوانده می‌شود و نه فقط از نتیجه‌ی subscribe. تفاوتش
 * مهم است: با روش قبلی، قطع‌شدن اتصال بعد از یک subscribe موفق هیچ‌جا دیده
 * نمی‌شد — نشانگر «زنده» می‌ماند در حالی که هیچ رویدادی نمی‌رسید. و
 * برعکس، وصل‌شدن دوباره هم دیده نمی‌شد و نشانگر تا refresh بعدی زرد
 * می‌ماند.
 */
export function useLiveChannel(
    channel: string | null,
    events: Record<string, (payload: unknown) => void>,
): { connected: Ref<boolean> } {
    const connected = ref(false);

    if (!channel) {
        return { connected };
    }

    const client = echo();

    if (!client) {
        return { connected };
    }

    /*
     * pusher-js خودش دوباره وصل می‌شود؛ کاری که اینجا می‌کنیم فقط گزارشِ
     * درستِ وضعیت است. state_change هر گذاری را می‌دهد:
     * connecting → connected → unavailable → connecting → ...
     */
    const connection = client.connector?.pusher?.connection;

    const onState = (states: { current: string }) => {
        connected.value = states.current === 'connected';
    };

    if (connection) {
        connected.value = connection.state === 'connected';
        connection.bind('state_change', onState);
    }

    const subscription = client.private(channel);

    for (const [event, handler] of Object.entries(events)) {
        subscription.listen(`.${event}`, handler);
    }

    // خطای مجوزِ کانال یعنی حتی با اتصالِ برقرار، رویدادی نمی‌رسد
    subscription.error(() => (connected.value = false));

    onUnmounted(() => {
        connection?.unbind('state_change', onState);

        for (const event of Object.keys(events)) {
            subscription.stopListening(`.${event}`);
        }

        client.leave(`private-${channel}`);
        connected.value = false;
    });

    return { connected };
}
