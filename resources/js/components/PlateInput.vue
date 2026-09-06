<script setup lang="ts">
import { digitsOnly } from '@/lib/format';
import { computed } from 'vue';

const props = defineProps<{
    two: string;
    letter: string;
    three: string;
    iran: string;
    letters: string[];
    invalid?: boolean;
}>();

const emit = defineEmits<{
    'update:two': [string];
    'update:letter': [string];
    'update:three': [string];
    'update:iran': [string];
}>();

const boxClass = computed(() => [
    'num rounded-lg border bg-white px-2 py-2.5 text-center text-lg font-semibold shadow-sm',
    'focus:outline-none focus:ring-4',
    props.invalid
        ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
        : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
]);

function onDigits(field: 'two' | 'three' | 'iran', event: Event, max: number, nextId?: string) {
    const target = event.target as HTMLInputElement;
    const value = digitsOnly(target.value).slice(0, max);
    target.value = value;

    if (field === 'two') emit('update:two', value);
    if (field === 'three') emit('update:three', value);
    if (field === 'iran') emit('update:iran', value);

    if (value.length === max && nextId) {
        document.getElementById(nextId)?.focus();
    }
}
</script>

<template>
    <!-- ترتیب بصری پلاک از چپ به راست است، مثل خود پلاک -->
    <div dir="ltr" class="flex items-stretch gap-2">
        <div class="flex flex-col items-center justify-center rounded-lg bg-blue-700 px-2 py-1 text-[10px] font-bold leading-tight text-white" aria-hidden="true">
            <span>I.R.</span>
            <span>IRAN</span>
        </div>

        <input
            id="plate_two"
            :value="two"
            type="text"
            inputmode="numeric"
            maxlength="2"
            aria-label="دو رقم اول پلاک"
            :class="[boxClass, 'w-14']"
            @input="onDigits('two', $event, 2, 'plate_letter')"
        />

        <select
            id="plate_letter"
            :value="letter"
            aria-label="حرف پلاک"
            :class="[
                'min-w-20 rounded-lg border bg-white px-2 py-2.5 text-center text-base font-semibold shadow-sm',
                'focus:outline-none focus:ring-4',
                invalid
                    ? 'border-rose-300 focus:border-rose-400 focus:ring-rose-100'
                    : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100',
            ]"
            @change="emit('update:letter', ($event.target as HTMLSelectElement).value)"
        >
            <option value="" disabled>—</option>
            <option v-for="option in letters" :key="option" :value="option">{{ option }}</option>
        </select>

        <input
            id="plate_three"
            :value="three"
            type="text"
            inputmode="numeric"
            maxlength="3"
            aria-label="سه رقم میانی پلاک"
            :class="[boxClass, 'w-18']"
            @input="onDigits('three', $event, 3, 'plate_iran')"
        />

        <input
            id="plate_iran"
            :value="iran"
            type="text"
            inputmode="numeric"
            maxlength="2"
            aria-label="کد ایران"
            :class="[boxClass, 'w-14 bg-slate-50']"
            @input="onDigits('iran', $event, 2)"
        />
    </div>
</template>
