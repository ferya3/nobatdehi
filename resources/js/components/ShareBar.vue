<script setup lang="ts">
import { computed } from 'vue';

/**
 * سهم هر بخش از کل، روی یک نوار افقی.
 *
 * عمداً دونات نیست. مقایسه‌ی دو قطعه‌ی دایره با چشم سخت است و وقتی
 * نزدیک هم باشند تقریباً غیرممکن؛ روی یک نوار، همان دو عدد بلافاصله
 * خوانده می‌شوند. نام‌های فارسی هم روی نوار افقی جا می‌شوند، که داخل
 * دایره نمی‌شدند.
 *
 * فاصله‌ی ۲ پیکسلی بین قطعه‌ها عمدی است: بدون آن، دو رنگِ کنار هم برای
 * کسی که کوررنگی دارد یک قطعه‌ی پیوسته دیده می‌شود.
 */
const props = withDefaults(
    defineProps<{
        data: { name: string; value: number }[];
        unit?: string;
        /** بیشتر از این تعداد، بقیه در «سایر» جمع می‌شوند */
        max?: number;
    }>(),
    { unit: '', max: 6 },
);

const SERIES = [
    'var(--color-series-1)',
    'var(--color-series-2)',
    'var(--color-series-3)',
    'var(--color-series-4)',
    'var(--color-series-5)',
    'var(--color-series-6)',
];

const rows = computed(() => {
    const sorted = [...props.data].filter((d) => d.value > 0).sort((a, b) => b.value - a.value);

    if (sorted.length <= props.max) return sorted;

    // رنگ نهم ساخته نمی‌شود؛ دنباله در «سایر» جمع می‌شود
    const head = sorted.slice(0, props.max - 1);
    const tail = sorted.slice(props.max - 1).reduce((sum, d) => sum + d.value, 0);

    return [...head, { name: 'سایر', value: tail }];
});

const total = computed(() => rows.value.reduce((sum, r) => sum + r.value, 0));

const segments = computed(() =>
    rows.value.map((row, i) => ({
        ...row,
        color: SERIES[i] ?? 'var(--color-slate-400)',
        percent: total.value > 0 ? (row.value / total.value) * 100 : 0,
    })),
);
</script>

<template>
    <!--
        یک بخش یعنی «سهم» معنایی ندارد.
        نوارِ ۱۰۰٪ چیزی به کسی نمی‌گوید؛ همان عدد، خودش گزارش است.
    -->
    <div v-if="segments.length === 1" class="py-2">
        <p class="text-sm text-slate-500">{{ segments[0].name }}</p>
        <p class="mt-1">
            <span class="num text-3xl font-bold text-slate-900">{{ segments[0].value }}</span>
            <span class="ms-1.5 text-sm text-slate-500">{{ unit }}</span>
        </p>
        <p class="mt-1 text-xs text-slate-400">تنها محصولِ این بازه</p>
    </div>

    <div v-else-if="segments.length">
        <div class="flex h-9 gap-0.5 overflow-hidden rounded-xl">
            <div
                v-for="s in segments"
                :key="s.name"
                class="flex items-center justify-center transition-all duration-500"
                :style="{ width: `${s.percent}%`, background: s.color }"
                :title="`${s.name}: ${s.value} ${unit}`"
            >
                <!-- عدد فقط وقتی جا دارد؛ وگرنه راهنمای پایین آن را می‌گوید -->
                <span v-if="s.percent >= 12" class="num text-xs font-semibold text-white">
                    ٪{{ Math.round(s.percent) }}
                </span>
            </div>
        </div>

        <!--
            راهنما همیشه هست.
            رنگ به‌تنهایی هویت را حمل نمی‌کند — متن با رنگ متن می‌آید و
            مربع رنگی کنارش، هویت را می‌رساند.
        -->
        <ul class="mt-3 space-y-1.5">
            <li v-for="s in segments" :key="s.name" class="flex items-center gap-2 text-sm">
                <span class="size-2.5 shrink-0 rounded-sm" :style="{ background: s.color }" aria-hidden="true" />
                <span class="truncate text-slate-700">{{ s.name }}</span>
                <span class="num ms-auto shrink-0 font-medium text-slate-800">{{ s.value }}</span>
                <span class="w-10 shrink-0 text-end text-xs text-slate-400">
                    <span class="num">٪{{ Math.round(s.percent) }}</span>
                </span>
            </li>
        </ul>
    </div>

    <p v-else class="py-8 text-center text-sm text-slate-400">داده‌ای برای نمایش نیست.</p>
</template>
