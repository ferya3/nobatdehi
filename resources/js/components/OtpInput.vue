<script setup lang="ts">
import { digitsOnly } from '@/lib/format';
import { nextTick, onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ modelValue: string; length?: number; invalid?: boolean }>(), {
    length: 5,
});

const emit = defineEmits<{ 'update:modelValue': [string]; complete: [string] }>();

const boxes = ref<HTMLInputElement[]>([]);
const cells = ref<string[]>(Array.from({ length: props.length }, () => ''));

watch(
    () => props.modelValue,
    (value) => {
        const digits = digitsOnly(value).slice(0, props.length).split('');
        cells.value = Array.from({ length: props.length }, (_, i) => digits[i] ?? '');
    },
    { immediate: true },
);

function push() {
    const value = cells.value.join('');
    emit('update:modelValue', value);
    if (value.length === props.length) emit('complete', value);
}

function onInput(index: number, event: Event) {
    const target = event.target as HTMLInputElement;
    const digits = digitsOnly(target.value);

    if (digits.length > 1) {
        // چسباندن کل کد در یک خانه
        digits
            .slice(0, props.length - index)
            .split('')
            .forEach((d, offset) => (cells.value[index + offset] = d));
        target.value = cells.value[index];
        focusCell(Math.min(index + digits.length, props.length - 1));
    } else {
        cells.value[index] = digits;
        target.value = digits;
        if (digits) focusCell(index + 1);
    }

    push();
}

function onKeydown(index: number, event: KeyboardEvent) {
    if (event.key === 'Backspace' && !cells.value[index] && index > 0) {
        event.preventDefault();
        cells.value[index - 1] = '';
        push();
        focusCell(index - 1);
    }

    // در RTL جهت کلیدهای جهت‌دار برعکس ترتیب بصری است
    if (event.key === 'ArrowLeft') focusCell(index + 1);
    if (event.key === 'ArrowRight') focusCell(index - 1);
}

function focusCell(index: number) {
    if (index < 0 || index >= props.length) return;
    nextTick(() => boxes.value[index]?.focus());
}

onMounted(() => focusCell(0));
</script>

<template>
    <div class="flex justify-center gap-2" dir="ltr">
        <input
            v-for="(cell, index) in cells"
            :key="index"
            :ref="(el) => (boxes[index] = el as HTMLInputElement)"
            :value="cell"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="1"
            :aria-label="`رقم ${index + 1} از ${length}`"
            :class="[
                'size-13 rounded-xl border text-center text-2xl font-semibold tabular-nums shadow-sm transition',
                'focus:outline-none focus:ring-4',
                invalid
                    ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
                    : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
            ]"
            @input="onInput(index, $event)"
            @keydown="onKeydown(index, $event)"
            @focus="($event.target as HTMLInputElement).select()"
        />
    </div>
</template>
