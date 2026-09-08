<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        data: { label: string; value: number }[];
        height?: number;
        /** واحدِ عدد، برای tooltip — مثلاً «کامیون» */
        unit?: string;
    }>(),
    { height: 170, unit: '' },
);

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)));

/**
 * ارتفاع ناحیه‌ی میله‌ها، جدا از ارتفاع کل.
 * برچسب بالا و پایین هر کدام جای ثابتی می‌خواهند.
 */
const plot = computed(() => Math.max(40, props.height - 42));

function heightOf(value: number): number {
    if (value === 0) return 3;

    return Math.max(6, (value / max.value) * plot.value);
}

/**
 * سه خط راهنما پشت میله‌ها.
 *
 * بدون آن‌ها چشم باید ارتفاع دو میله را با هم بسنجد؛ با آن‌ها یک‌بار نگاه
 * کافی است. عددهایشان رُند می‌شوند چون «۳٫۳۳ کامیون» چیزی نیست.
 */
const guides = computed(() => {
    const top = max.value;

    return [top, Math.round(top / 2), 0].filter((v, i, all) => all.indexOf(v) === i);
});

const busiest = computed(() => props.data.reduce((best, row) => (row.value > best.value ? row : best), props.data[0]));
</script>

<template>
    <div v-if="data.length" class="overflow-x-auto">
        <div class="relative flex min-w-full gap-2" :style="{ height: `${height}px` }">
            <!-- محور عمودی: فقط سه عدد، وگرنه شلوغ می‌شود -->
            <div class="flex w-6 shrink-0 flex-col justify-between pb-9 pt-1 text-[10px] text-slate-300">
                <span v-for="g in guides" :key="g" class="num leading-none">{{ g }}</span>
            </div>

            <div class="relative flex flex-1 items-end justify-start gap-1.5">
                <!-- خطوط راهنما، پشت میله‌ها -->
                <div class="pointer-events-none absolute inset-x-0 bottom-9 top-1 flex flex-col justify-between">
                    <div v-for="g in guides" :key="g" class="border-t border-dashed border-slate-100" />
                </div>

                <div
                    v-for="item in data"
                    :key="item.label"
                    class="group relative flex min-w-8 max-w-24 flex-1 flex-col items-center justify-end"
                    :style="{ height: '100%' }"
                >
                    <span
                        :class="[
                            'num mb-1 text-xs tabular-nums transition',
                            item.value === busiest.value && item.value > 0
                                ? 'font-bold text-brand-700'
                                : 'font-medium text-slate-500',
                        ]"
                    >
                        {{ item.value }}
                    </span>

                    <div
                        :class="[
                            'w-full rounded-t-lg transition-all duration-500 group-hover:brightness-110',
                            item.value === 0
                                ? 'bg-slate-200'
                                : item.value === busiest.value
                                  ? 'bg-gradient-to-t from-brand-600 to-brand-400'
                                  : 'bg-gradient-to-t from-brand-500/80 to-brand-400/60',
                        ]"
                        :style="{ height: `${heightOf(item.value)}px` }"
                    />

                    <span class="num mt-2 h-6 text-[10px] leading-tight text-slate-400">{{ item.label }}</span>

                    <!-- راهنمای شناور: عدد روی میله جا نمی‌شود وقتی میله کوتاه است -->
                    <span
                        class="pointer-events-none absolute -top-1 z-10 hidden whitespace-nowrap rounded-lg bg-slate-800 px-2 py-1 text-[11px] text-white shadow-lg group-hover:block"
                    >
                        {{ item.label }} — <span class="num">{{ item.value }}</span> {{ unit }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <p v-else class="py-10 text-center text-sm text-slate-400">داده‌ای برای نمایش نیست.</p>
</template>
