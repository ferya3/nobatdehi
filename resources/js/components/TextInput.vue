<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue: string;
        type?: string;
        inputmode?: 'text' | 'numeric' | 'tel';
        placeholder?: string;
        maxlength?: number;
        invalid?: boolean;
        disabled?: boolean;
        autofocus?: boolean;
        dir?: 'rtl' | 'ltr';
        id?: string;
    }>(),
    { type: 'text', dir: 'rtl' },
);

const emit = defineEmits<{ 'update:modelValue': [string] }>();

const classes = computed(() => [
    'block w-full rounded-xl border bg-white px-4 py-3 text-base text-slate-900 shadow-sm transition',
    'placeholder:text-slate-400 focus:outline-none focus:ring-4',
    props.invalid
        ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
        : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
    'disabled:bg-slate-50 disabled:text-slate-500',
]);
</script>

<template>
    <input
        :id="id"
        :type="type"
        :inputmode="inputmode"
        :placeholder="placeholder"
        :maxlength="maxlength"
        :disabled="disabled"
        :autofocus="autofocus"
        :dir="dir"
        :value="modelValue"
        :class="classes"
        :aria-invalid="invalid || undefined"
        @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
</template>
