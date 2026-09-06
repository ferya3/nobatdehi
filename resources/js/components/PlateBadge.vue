<script setup lang="ts">
import type { PlateParts } from '@/types';

withDefaults(defineProps<{ plate: PlateParts; size?: 'sm' | 'md' }>(), { size: 'md' });
</script>

<template>
    <!--
        بازنمایی پلاک ایرانی. ترتیب بصری از چپ به راست است (مثل خود پلاک فیزیکی)
        پس dir="ltr" روی کل قاب گذاشته می‌شود.
    -->
    <span
        dir="ltr"
        :class="[
            'inline-flex items-stretch overflow-hidden rounded-md border border-slate-300 bg-white font-semibold text-slate-800 shadow-sm',
            size === 'sm' ? 'text-xs' : 'text-sm',
        ]"
        :title="plate.full"
    >
        <span
            :class="[
                'flex flex-col items-center justify-center bg-blue-700 px-1 text-[0.55em] leading-tight text-white',
                size === 'sm' ? 'py-0.5' : 'py-1',
            ]"
            aria-hidden="true"
        >
            <span>I.R.</span>
            <span>IRAN</span>
        </span>

        <span :class="['flex items-center gap-1 px-2', size === 'sm' ? 'py-0.5' : 'py-1']">
            <span class="num">{{ plate.two }}</span>
            <span class="text-slate-500">{{ plate.letter }}</span>
            <span class="num">{{ plate.three }}</span>
        </span>

        <span
            :class="[
                'flex items-center border-s border-slate-300 bg-slate-50 px-2',
                size === 'sm' ? 'py-0.5' : 'py-1',
            ]"
        >
            <span class="num">{{ plate.iran }}</span>
        </span>
    </span>
</template>
