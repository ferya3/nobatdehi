<script setup lang="ts">
import QRCode from 'qrcode';
import { onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{ value: string; size?: number }>(), { size: 220 });

const canvas = ref<HTMLCanvasElement | null>(null);
const failed = ref(false);

async function draw() {
    if (!canvas.value) return;

    try {
        await QRCode.toCanvas(canvas.value, props.value, {
            width: props.size,
            margin: 1,
            errorCorrectionLevel: 'M',
            color: { dark: '#142633', light: '#ffffff' },
        });
        failed.value = false;
    } catch {
        failed.value = true;
    }
}

onMounted(draw);
watch(() => props.value, draw);
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <canvas ref="canvas" :width="size" :height="size" class="block" aria-label="کد QR نوبت" />
        </div>
        <p v-if="failed" class="text-sm text-rose-600">نمایش کد QR ممکن نشد.</p>
    </div>
</template>
