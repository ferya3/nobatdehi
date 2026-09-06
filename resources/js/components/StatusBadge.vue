<script setup lang="ts">
import type { StatusTone } from '@/types';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ tone: StatusTone; label: string; size?: 'sm' | 'md' }>(),
    { size: 'md' },
);

const toneClasses: Record<StatusTone, string> = {
    booked: 'bg-slate-100 text-slate-700 ring-slate-200',
    waiting: 'bg-amber-50 text-amber-800 ring-amber-200',
    called: 'bg-blue-50 text-blue-800 ring-blue-200',
    checkedin: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
    loading: 'bg-violet-50 text-violet-800 ring-violet-200',
    loaded: 'bg-emerald-50 text-emerald-800 ring-emerald-200',
    completed: 'bg-emerald-600/10 text-emerald-800 ring-emerald-300',
    failed: 'bg-rose-50 text-rose-800 ring-rose-200',
};

const dotClasses: Record<StatusTone, string> = {
    booked: 'bg-slate-400',
    waiting: 'bg-amber-500',
    called: 'bg-blue-500',
    checkedin: 'bg-indigo-500',
    loading: 'bg-violet-500',
    loaded: 'bg-emerald-500',
    completed: 'bg-emerald-600',
    failed: 'bg-rose-500',
};

const classes = computed(() => [
    'inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset',
    props.size === 'sm' ? 'px-2 py-0.5 text-xs' : 'px-2.5 py-1 text-sm',
    toneClasses[props.tone],
]);

// «فراخوانده‌شده» و «در حال بارگیری» باید در نگاه اول از بقیه جدا شوند
const pulses = computed(() => props.tone === 'called' || props.tone === 'loading');
</script>

<template>
    <span :class="classes">
        <span class="relative flex size-2">
            <span
                v-if="pulses"
                :class="['absolute inline-flex size-full animate-ping rounded-full opacity-75', dotClasses[tone]]"
            />
            <span :class="['relative inline-flex size-2 rounded-full', dotClasses[tone]]" />
        </span>
        {{ label }}
    </span>
</template>
