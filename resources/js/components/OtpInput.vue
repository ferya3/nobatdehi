<script setup lang="ts">
import { digitsOnly } from '@/lib/format';
import { nextTick, onMounted, ref, watch } from 'vue';

/**
 * خانه‌های کد یک‌بارمصرف.
 *
 * راننده این را با یک دست، وسط جاده، روی موبایل پر می‌کند. پس هر چیزی که
 * یک ضربه کم کند ارزش دارد: چسباندن کل کد در هر خانه، رفتن خودکار به
 * خانه‌ی بعد، و Backspace که خودش عقب می‌رود.
 *
 * طول از بیرون می‌آید و پیش‌فرضش با config('otp.length') یکی است — عددِ
 * ثابتِ متفاوت در این دو، یک بار ورود را کاملاً از کار انداخت.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        length?: number;
        invalid?: boolean;
        disabled?: boolean;
        /** فاصله‌ی بصری هر چند رقم؛ صفر یعنی بدون گروه‌بندی */
        groupEvery?: number;
    }>(),
    { length: 6, invalid: false, disabled: false, groupEvery: 3 },
);

const emit = defineEmits<{ 'update:modelValue': [string]; complete: [string] }>();

const boxes = ref<HTMLInputElement[]>([]);
const cells = ref<string[]>(Array.from({ length: props.length }, () => ''));
const focused = ref(-1);
const shake = ref(false);

watch(
    () => props.modelValue,
    (value) => {
        const digits = digitsOnly(value).slice(0, props.length).split('');
        cells.value = Array.from({ length: props.length }, (_, i) => digits[i] ?? '');
    },
    { immediate: true },
);

// طول ممکن است بعد از mount عوض شود (تنظیمات سرور)
watch(
    () => props.length,
    (length) => {
        cells.value = Array.from({ length }, (_, i) => cells.value[i] ?? '');
        boxes.value.length = length;
    },
);

/**
 * لرزش فقط وقتی خطا *تازه* می‌آید.
 *
 * بدون این شرط، هر بار که Vue کامپوننت را دوباره می‌کشد صفحه می‌لرزید.
 */
watch(
    () => props.invalid,
    (isInvalid, was) => {
        if (!isInvalid || was) return;

        shake.value = false;
        nextTick(() => {
            shake.value = true;
            setTimeout(() => (shake.value = false), 400);
        });

        focusCell(0);
    },
);

function push() {
    const value = cells.value.join('');

    emit('update:modelValue', value);

    if (value.length === props.length) emit('complete', value);
}

/** از یک خانه به بعد پر می‌کند — برای paste و برای تایپ چندرقمی */
function fillFrom(index: number, text: string) {
    const digits = digitsOnly(text);

    if (!digits) return;

    let cursor = index;

    for (const digit of digits) {
        if (cursor >= props.length) break;

        cells.value[cursor] = digit;
        cursor += 1;
    }

    push();
    focusCell(Math.min(cursor, props.length - 1));
}

function onInput(index: number, event: Event) {
    const target = event.target as HTMLInputElement;
    const digits = digitsOnly(target.value);

    if (digits.length > 1) {
        fillFrom(index, digits);
        target.value = cells.value[index] ?? '';

        return;
    }

    cells.value[index] = digits;
    target.value = digits;

    if (digits) focusCell(index + 1);

    push();
}

function onKeydown(index: number, event: KeyboardEvent) {
    switch (event.key) {
        case 'Backspace':
            event.preventDefault();

            if (cells.value[index]) {
                cells.value[index] = '';
                push();

                return;
            }

            if (index > 0) {
                cells.value[index - 1] = '';
                push();
                focusCell(index - 1);
            }

            return;

        case 'Delete':
            event.preventDefault();
            cells.value[index] = '';
            push();

            return;

        // ردیف با dir="ltr" کشیده می‌شود، پس چپ و راست همان معنای بصری را دارند
        case 'ArrowLeft':
            event.preventDefault();
            focusCell(index - 1);

            return;

        case 'ArrowRight':
            event.preventDefault();
            focusCell(index + 1);

            return;

        case 'Home':
            event.preventDefault();
            focusCell(0);

            return;

        case 'End':
            event.preventDefault();
            focusCell(props.length - 1);
    }
}

function onPaste(index: number, event: ClipboardEvent) {
    event.preventDefault();

    const text = digitsOnly(event.clipboardData?.getData('text') ?? '');

    // کدِ کامل همیشه از خانه‌ی اول می‌نشیند، هر جا که چسبانده شود
    fillFrom(text.length >= props.length ? 0 : index, text);
}

/**
 * پرش به اولین خانه‌ی خالی.
 *
 * راننده‌ای که وسط کد روی خانه‌ی ششم می‌زند، منظورش ادامه‌ی کد است نه
 * پریدن به آخر.
 */
function onFocus(index: number, event: FocusEvent) {
    (event.target as HTMLInputElement).select();

    const firstEmpty = cells.value.findIndex((c) => c === '');

    if (firstEmpty !== -1 && firstEmpty < index) {
        focusCell(firstEmpty);

        return;
    }

    focused.value = index;
}

function onBlur(event: FocusEvent) {
    const to = event.relatedTarget as HTMLInputElement | null;

    // جابه‌جایی بین خانه‌ها، «خروج» نیست
    if (to && boxes.value.includes(to)) return;

    focused.value = -1;
}

function focusCell(index: number) {
    if (index < 0 || index >= props.length || props.disabled) return;

    nextTick(() => boxes.value[index]?.focus());
}

function clear() {
    cells.value = Array.from({ length: props.length }, () => '');
    push();
    focusCell(0);
}

defineExpose({ focus: () => focusCell(0), clear });

onMounted(() => focusCell(0));
</script>

<template>
    <div :class="['flex justify-center gap-2', shake && 'otp-shake']" dir="ltr">
        <div
            v-for="(cell, index) in cells"
            :key="index"
            :class="['relative', groupEvery > 0 && index > 0 && index % groupEvery === 0 && 'ms-3']"
        >
            <!--
                خودِ input متن ندارد و رقم را یک لایه‌ی جدا می‌کشد.
                فقط این‌طور می‌شود ورودِ رقم را انیمیشن داد و مکان‌نمای
                خودمان را گذاشت، بدون اینکه مکان‌نمای مرورگر دوتا شود.
            -->
            <input
                :ref="(el) => (boxes[index] = el as HTMLInputElement)"
                :value="cell"
                type="text"
                inputmode="numeric"
                :autocomplete="index === 0 ? 'one-time-code' : 'off'"
                autocorrect="off"
                autocapitalize="off"
                spellcheck="false"
                maxlength="1"
                :disabled="disabled"
                :aria-label="`رقم ${index + 1} از ${length}`"
                :aria-invalid="invalid || undefined"
                :class="[
                    'size-13 rounded-xl border-2 text-center text-2xl caret-transparent transition',
                    'text-transparent selection:bg-transparent focus:outline-none disabled:opacity-50',
                    invalid
                        ? 'border-rose-400 bg-white'
                        : focused === index
                          ? 'border-brand-500 bg-white ring-4 ring-brand-100'
                          : cell
                            ? 'border-slate-300 bg-white'
                            : 'border-slate-200 bg-slate-100/70 shadow-[inset_0_1px_2px_rgba(15,23,42,0.06)]',
                ]"
                @input="onInput(index, $event)"
                @keydown="onKeydown(index, $event)"
                @paste="onPaste(index, $event)"
                @focus="onFocus(index, $event)"
                @blur="onBlur"
            />

            <span aria-hidden="true" class="pointer-events-none absolute inset-0 grid place-items-center">
                <span
                    v-if="cell"
                    :key="cell"
                    :class="[
                        'num otp-pop text-2xl font-semibold tabular-nums',
                        invalid ? 'text-rose-700' : 'text-slate-800',
                    ]"
                >
                    {{ cell }}
                </span>

                <span
                    v-else-if="focused === index && !disabled"
                    class="otp-caret block h-6 w-0.5 rounded-full bg-slate-700"
                />
            </span>
        </div>
    </div>
</template>
