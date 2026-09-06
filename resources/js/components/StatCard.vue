<script setup lang="ts">
import type { StatusTone } from '@/types';

defineProps<{ label: string; value: number; tone?: StatusTone | 'neutral' }>();

const toneClasses: Record<string, string> = {
    neutral: 'text-slate-900',
    waiting: 'text-amber-600',
    checkedin: 'text-indigo-600',
    loading: 'text-violet-600',
    completed: 'text-emerald-600',
    failed: 'text-rose-600',
};
</script>

<template>
    <div class="card px-4 py-3">
        <p class="text-xs text-slate-500">{{ label }}</p>
        <p :class="['num mt-1 text-2xl font-bold', toneClasses[tone ?? 'neutral']]">{{ value }}</p>
    </div>
</template>
