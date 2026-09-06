<script setup lang="ts">
defineProps<{
    label?: string;
    error?: string;
    hint?: string;
    for?: string;
}>();
</script>

<template>
    <div class="space-y-1.5">
        <label v-if="label" :for="$props.for" class="block text-sm font-medium text-slate-700">
            {{ label }}
        </label>

        <slot />

        <p v-if="error" class="flex items-start gap-1 text-sm text-rose-600">
            <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-.75-11.5a.75.75 0 0 1 1.5 0v4a.75.75 0 0 1-1.5 0v-4ZM10 14a1 1 0 1 1 0-2 1 1 0 0 1 0 2Z"
                    clip-rule="evenodd"
                />
            </svg>
            <span>{{ error }}</span>
        </p>

        <p v-else-if="hint" class="text-sm text-slate-500">{{ hint }}</p>
    </div>
</template>
