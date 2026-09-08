<script setup lang="ts">
import { computed } from 'vue';

/**
 * روندِ کوچک، پشت یک عدد.
 *
 * محور و برچسب ندارد و عمداً: کارش این است که بگوید «بالا می‌رود یا
 * پایین»، نه اینکه عدد را بخوانی. عدد بزرگ کنارش همان را می‌گوید.
 */
const props = withDefaults(defineProps<{ data: number[]; tone?: string }>(), { tone: 'currentColor' });

const W = 100;
const H = 28;

const path = computed(() => {
    if (props.data.length < 2) return '';

    const max = Math.max(...props.data);
    const min = Math.min(...props.data);
    const span = max - min || 1;

    return props.data
        .map((v, i) => {
            const x = (i / (props.data.length - 1)) * W;
            const y = H - 2 - ((v - min) / span) * (H - 4);

            return `${i === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg v-if="path" :viewBox="`0 0 ${W} ${H}`" class="h-7 w-full" preserveAspectRatio="none" aria-hidden="true">
        <path :d="path" fill="none" :stroke="tone" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" opacity="0.55" />
    </svg>
</template>
