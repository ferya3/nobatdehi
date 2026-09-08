<script setup lang="ts">
import { computed } from 'vue';

/**
 * سنجه‌ی دایره‌ای برای «چقدر از کارِ این کامیون گذشته».
 *
 * نسبت به یک سقفِ مشخص است و نه سهم از کل، پس دایره اینجا درست است —
 * برخلاف دونات که برای مقایسه‌ی چند بخش با هم به کار می‌رود. عدد وسطش
 * می‌آید چون نوار و دایره به‌تنهایی خوانده نمی‌شوند.
 */
const props = withDefaults(defineProps<{ percent: number; size?: number; late?: boolean }>(), {
    size: 64,
    late: false,
});

const R = 26;
const CIRC = 2 * Math.PI * R;

const offset = computed(() => CIRC * (1 - Math.min(100, Math.max(0, props.percent)) / 100));
</script>

<template>
    <div class="relative shrink-0" :style="{ width: `${size}px`, height: `${size}px` }">
        <svg viewBox="0 0 64 64" class="size-full -rotate-90">
            <circle cx="32" cy="32" :r="R" fill="none" stroke="currentColor" class="text-slate-100" stroke-width="6" />
            <circle
                cx="32"
                cy="32"
                :r="R"
                fill="none"
                :stroke="late ? 'var(--color-state-failed)' : 'var(--color-state-loading)'"
                stroke-width="6"
                stroke-linecap="round"
                :stroke-dasharray="CIRC"
                :stroke-dashoffset="offset"
                class="transition-all duration-700"
            />
        </svg>

        <span class="absolute inset-0 flex items-center justify-center">
            <span :class="['num text-sm font-bold', late ? 'text-rose-600' : 'text-violet-700']">
                ٪{{ Math.min(100, Math.round(percent)) }}
            </span>
        </span>
    </div>
</template>
