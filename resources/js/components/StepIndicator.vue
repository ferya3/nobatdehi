<script setup lang="ts">
defineProps<{ steps: string[]; current: number }>();
</script>

<template>
    <ol class="flex items-center gap-1.5" :aria-label="`مرحله ${current + 1} از ${steps.length}`">
        <li v-for="(step, index) in steps" :key="step" class="flex flex-1 flex-col gap-1.5">
            <span
                :class="[
                    'h-1.5 rounded-full transition-colors',
                    index < current ? 'bg-brand-500' : index === current ? 'bg-brand-600' : 'bg-slate-200',
                ]"
            />
            <span
                :class="[
                    'truncate text-[11px] transition-colors',
                    index === current ? 'font-medium text-brand-700' : 'text-slate-400',
                ]"
            >
                {{ step }}
            </span>
        </li>
    </ol>
</template>
