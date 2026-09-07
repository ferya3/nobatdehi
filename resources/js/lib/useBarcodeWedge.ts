import { onMounted, onUnmounted, ref } from 'vue';

/**
 * بارکدخوان سخت‌افزاری (USB یا بلوتوث).
 *
 * این دستگاه‌ها خودشان را کیبورد معرفی می‌کنند: محتوای بارکد را «تایپ»
 * می‌کنند و آخرش Enter می‌زنند. یعنی نه رویداد اختصاصی دارند و نه درایوری
 * که بشود صدایش زد — تنها تفاوتشان با انسان، سرعت است.
 *
 * پس تشخیص بر اساس فاصله‌ی زمانی بین کلیدهاست: چند ده میلی‌ثانیه برای
 * دستگاه، و حداقل صد و خرده‌ای میلی‌ثانیه برای انگشتِ آدم. تایپِ انسان
 * بافر را دور می‌ریزد، پس نگهبانی که چیزی تایپ می‌کند سهواً ورود ثبت
 * نمی‌کند.
 *
 * فیلدهای ورودی هم استثنا هستند: وقتی مکان‌نما داخل input است، کاربر دارد
 * تایپ می‌کند و ما اصلاً گوش نمی‌دهیم.
 */

interface Options {
    /** بیشترین فاصله‌ی مجاز بین دو کلید تا هنوز «دستگاه» حساب شود (ms) */
    maxGapMs?: number;
    /** کوتاه‌تر از این، بارکد نیست */
    minLength?: number;
    /** وقتی false باشد، اصلاً گوش نمی‌دهد */
    enabled?: () => boolean;
}

export function useBarcodeWedge(onScan: (payload: string) => void, options: Options = {}) {
    const maxGapMs = options.maxGapMs ?? 35;
    const minLength = options.minLength ?? 6;
    const isEnabled = options.enabled ?? (() => true);

    /** آخرین باری که دستگاه چیزی خواند — برای نشان‌دادن «آماده است» */
    const lastScanAt = ref<number | null>(null);

    let buffer = '';
    let lastKeyAt = 0;

    function reset() {
        buffer = '';
        lastKeyAt = 0;
    }

    /** وقتی کاربر داخل فیلدی تایپ می‌کند، این کلیدها مال ما نیستند */
    function typingInAField(target: EventTarget | null): boolean {
        const el = target as HTMLElement | null;

        if (!el) return false;

        return (
            el.tagName === 'INPUT' ||
            el.tagName === 'TEXTAREA' ||
            el.tagName === 'SELECT' ||
            el.isContentEditable
        );
    }

    function onKeydown(event: KeyboardEvent) {
        if (!isEnabled() || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        if (typingInAField(event.target)) {
            reset();
            return;
        }

        const now = event.timeStamp || performance.now();
        const gap = lastKeyAt === 0 ? 0 : now - lastKeyAt;

        if (event.key === 'Enter') {
            const payload = buffer;
            reset();

            if (payload.length >= minLength) {
                lastScanAt.value = Date.now();
                onScan(payload);
            }

            return;
        }

        // فقط کاراکترهای چاپی؛ Shift و Tab و امثالش بافر را خراب نکنند
        if (event.key.length !== 1) {
            return;
        }

        // فاصله‌ی زیاد یعنی این کلید ادامه‌ی خواندنِ قبلی نیست
        if (gap > maxGapMs) {
            buffer = '';
        }

        buffer += event.key;
        lastKeyAt = now;
    }

    onMounted(() => document.addEventListener('keydown', onKeydown));
    onUnmounted(() => document.removeEventListener('keydown', onKeydown));

    return { lastScanAt };
}
