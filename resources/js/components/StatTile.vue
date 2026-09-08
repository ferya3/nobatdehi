<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Sparkline from '@/components/Sparkline.vue';
import { computed } from 'vue';

/**
 * کاشیِ آمار: آیکون، عدد، و در صورت وجود، تغییر نسبت به دیروز و روندِ هفته.
 *
 * رنگ روی آیکون و روند می‌نشیند و نه روی خودِ عدد — عدد با رنگِ متن خوانده
 * می‌شود تا در هر شرایطی کنتراست داشته باشد.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        value: string | number;
        icon?: string;
        tone?: 'brand' | 'amber' | 'violet' | 'emerald' | 'rose' | 'slate';
        /** تغییر نسبت به دیروز؛ null یعنی مبنایی برای مقایسه نیست */
        delta?: number | null;
        /** بالا رفتن این عدد خبر خوبی است؟ برای رنگِ درستِ فلش */
        higherIsBetter?: boolean;
        trend?: number[];
    }>(),
    { icon: 'chart', tone: 'slate', delta: null, higherIsBetter: true, trend: () => [] },
);

const TONES = {
    brand: { chip: 'bg-brand-50 text-brand-600', bar: 'bg-brand-500', line: 'var(--color-brand-500)' },
    amber: { chip: 'bg-amber-50 text-amber-600', bar: 'bg-amber-500', line: 'var(--color-state-waiting)' },
    violet: { chip: 'bg-violet-50 text-violet-600', bar: 'bg-violet-500', line: 'var(--color-state-loading)' },
    emerald: { chip: 'bg-emerald-50 text-emerald-600', bar: 'bg-emerald-500', line: 'var(--color-state-completed)' },
    rose: { chip: 'bg-rose-50 text-rose-600', bar: 'bg-rose-500', line: 'var(--color-state-failed)' },
    slate: { chip: 'bg-slate-100 text-slate-500', bar: 'bg-slate-400', line: 'var(--color-slate-400)' },
} as const;

const tone = computed(() => TONES[props.tone]);

// «بهتر» به معنای خودِ عدد نیست: بالا رفتنِ عدم حضور خبر بدی است
const deltaIsGood = computed(() =>
    props.delta === null || props.delta === 0 ? null : props.delta > 0 === props.higherIsBetter,
);
</script>

<template>
    <div class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200/70 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
        <!-- نوار رنگی لبه: کارت‌ها را از هم جدا می‌کند بدون اینکه شلوغ شوند -->
        <span :class="['absolute inset-y-0 start-0 w-1', tone.bar]" aria-hidden="true" />

        <div class="flex-1 p-4 ps-5">
            <div class="flex items-center justify-between gap-2">
                <span :class="['flex size-8 items-center justify-center rounded-lg', tone.chip]">
                    <Icon :name="icon" class="size-4" />
                </span>

                <span
                    v-if="delta !== null && delta !== 0"
                    :class="[
                        'inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 text-[11px] font-medium',
                        deltaIsGood ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700',
                    ]"
                    title="نسبت به دیروز"
                    dir="ltr"
                >
                    <span aria-hidden="true">{{ delta > 0 ? '▲' : '▼' }}</span>
                    <span class="num">{{ Math.abs(delta) }}</span>
                </span>
            </div>

            <p class="mt-3 truncate text-xs text-slate-500">{{ label }}</p>
            <p class="num mt-0.5 text-2xl font-bold text-slate-900">{{ value }}</p>
        </div>

        <!-- روند هفته، نواری در کف کارت — نه کنارِ عدد، که آنجا جا نمی‌شود -->
        <div v-if="trend.length > 1" class="px-2 pb-1">
            <Sparkline :data="trend" :tone="tone.line" />
        </div>
        <div v-else class="h-2" />
    </div>
</template>
