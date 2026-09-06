<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    data: { label: string; value: number }[];
    height?: number;
}>();

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)));
const barHeight = computed(() => props.height ?? 160);
</script>

<template>
    <div v-if="data.length" class="overflow-x-auto">
        <div class="flex min-w-full items-end gap-2" :style="{ height: `${barHeight}px` }">
            <div v-for="item in data" :key="item.label" class="flex min-w-10 flex-1 flex-col items-center gap-1.5">
                <span class="num text-xs font-medium text-slate-600">{{ item.value }}</span>
                <div
                    class="w-full rounded-t-md bg-brand-500/85 transition-all"
                    :style="{ height: `${Math.max(2, (item.value / max) * (barHeight - 44))}px` }"
                    :title="`${item.label}: ${item.value}`"
                />
                <span class="num text-[10px] text-slate-400">{{ item.label }}</span>
            </div>
        </div>
    </div>

    <p v-else class="py-8 text-center text-sm text-slate-500">داده‌ای برای نمایش نیست.</p>
</template>
