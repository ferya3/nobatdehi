import { echo } from '@/lib/echo';
import { onUnmounted, ref, type Ref } from 'vue';

/**
 * اشتراک در یک کانال خصوصی، با پاک‌سازی خودکار.
 *
 * `connected` می‌گوید آیا واقعاً به WebSocket وصل شدیم یا نه؛ صفحه با همین
 * تصمیم می‌گیرد که polling پشتیبان را روشن نگه دارد یا نه.
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

    const subscription = client.private(channel);

    for (const [event, handler] of Object.entries(events)) {
        subscription.listen(`.${event}`, handler);
    }

    subscription.subscribed(() => (connected.value = true));
    subscription.error(() => (connected.value = false));

    onUnmounted(() => {
        for (const event of Object.keys(events)) {
            subscription.stopListening(`.${event}`);
        }

        client.leave(`private-${channel}`);
        connected.value = false;
    });

    return { connected };
}
