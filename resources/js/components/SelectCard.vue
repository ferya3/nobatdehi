<script setup lang="ts">
defineProps<{ selected: boolean; disabled?: boolean }>();
</script>

<template>
    <button
        type="button"
        :disabled="disabled"
        :aria-pressed="selected"
        :class="[
            'w-full rounded-xl border p-4 text-start transition',
            'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500',
            disabled
                ? 'cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400'
                : selected
                  ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                  : 'border-slate-200 bg-white hover:border-brand-300 hover:bg-slate-50',
        ]"
    >
        <slot />
    </button>
</template>
