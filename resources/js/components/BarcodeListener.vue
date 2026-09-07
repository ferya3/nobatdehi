<script setup lang="ts">
import { useBarcodeWedge } from '@/lib/useBarcodeWedge';
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ enabled?: boolean; busy?: boolean }>(), {
    enabled: true,
    busy: false,
});

const emit = defineEmits<{ scanned: [string] }>();

const manual = ref('');
const showManual = ref(false);

const { lastScanAt } = useBarcodeWedge((payload) => emit('scanned', payload), {
    enabled: () => props.enabled && !props.busy,
});

// «چند لحظه پیش چیزی خواند» — تنها نشانه‌ی در دسترسِ اینکه دستگاه وصل است
const recentlyScanned = computed(() => lastScanAt.value !== null && Date.now() - lastScanAt.value < 60_000);

function submitManual() {
    const value = manual.value.trim();

    if (value.length === 0) return;

    manual.value = '';
    emit('scanned', value);
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
            <span
                :class="[
                    'size-2.5 shrink-0 rounded-full',
                    busy ? 'bg-amber-500' : recentlyScanned ? 'bg-emerald-500' : 'bg-slate-300',
                ]"
                aria-hidden="true"
            />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-slate-800">
                    {{ busy ? 'در حال بررسی…' : 'بارکدخوان آماده است' }}
                </p>
                <p class="mt-0.5 text-xs text-slate-500">
                    بارکد یا QR روی حواله‌ی راننده را بخوانید — لازم نیست جایی کلیک کنید.
                </p>
            </div>
        </div>

        <button
            type="button"
            class="text-xs font-medium text-slate-500 underline underline-offset-4 hover:text-slate-700"
            @click="showManual = !showManual"
        >
            {{ showManual ? 'بستن ورود دستی کد' : 'بارکدخوان کار نمی‌کند؟ کد را دستی وارد کنید' }}
        </button>

        <!-- پشتیبان: دستگاهی که باتری‌اش تمام شده نباید کل گیت را بخواباند -->
        <form v-if="showManual" class="flex gap-2" @submit.prevent="submitManual">
            <input
                v-model="manual"
                type="text"
                inputmode="text"
                autocomplete="off"
                placeholder="کد روی حواله"
                class="num min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
            />
            <button
                type="submit"
                :disabled="busy || manual.trim().length === 0"
                class="shrink-0 rounded-xl bg-slate-800 px-4 py-2.5 text-sm font-medium text-white disabled:opacity-40"
            >
                بررسی
            </button>
        </form>
    </div>
</template>
