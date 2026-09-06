<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{ tone?: 'info' | 'success' | 'warning' | 'error' }>(),
    { tone: 'info' },
);

const classes = computed(
    () =>
        ({
            info: 'bg-brand-50 text-brand-900 border-brand-200',
            success: 'bg-emerald-50 text-emerald-900 border-emerald-200',
            warning: 'bg-amber-50 text-amber-900 border-amber-200',
            error: 'bg-rose-50 text-rose-900 border-rose-200',
        })[props.tone],
);
</script>

<template>
    <div :class="['rounded-xl border px-4 py-3 text-sm', classes]" role="status">
        <slot />
    </div>
</template>
