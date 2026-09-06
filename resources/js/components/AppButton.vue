<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
        size?: 'md' | 'lg' | 'sm';
        loading?: boolean;
        disabled?: boolean;
        block?: boolean;
        type?: 'button' | 'submit';
    }>(),
    { variant: 'primary', size: 'md', type: 'button' },
);

const classes = computed(() => [
    'inline-flex items-center justify-center gap-2 rounded-xl font-medium transition',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
    'disabled:cursor-not-allowed disabled:opacity-50',
    {
        sm: 'px-3 py-1.5 text-sm',
        md: 'px-4 py-2.5 text-sm',
        lg: 'w-full px-5 py-3.5 text-base',
    }[props.size],
    {
        primary: 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 active:bg-brand-800',
        secondary: 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50',
        ghost: 'text-slate-600 hover:bg-slate-100',
        danger: 'bg-rose-600 text-white hover:bg-rose-700',
    }[props.variant],
    props.block ? 'w-full' : '',
]);
</script>

<template>
    <button :type="type" :class="classes" :disabled="disabled || loading">
        <svg v-if="loading" class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5H4z" />
        </svg>
        <slot />
    </button>
</template>
