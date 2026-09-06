import { onUnmounted, ref, type Ref } from 'vue';

/** شمارنده‌ی معکوس ثانیه‌ای، برای «ارسال مجدد» و انقضای کد. */
export function useCountdown(initial = 0): {
    remaining: Ref<number>;
    start: (seconds: number) => void;
    stop: () => void;
} {
    const remaining = ref(initial);
    let timer: ReturnType<typeof setInterval> | null = null;

    function stop() {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    function start(seconds: number) {
        stop();
        remaining.value = Math.max(0, Math.floor(seconds));

        if (remaining.value === 0) return;

        timer = setInterval(() => {
            remaining.value -= 1;
            if (remaining.value <= 0) stop();
        }, 1000);
    }

    if (initial > 0) start(initial);

    onUnmounted(stop);

    return { remaining, start, stop };
}
