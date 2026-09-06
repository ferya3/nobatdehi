<script setup lang="ts">
import jsQR from 'jsqr';
import { onUnmounted, ref } from 'vue';

const emit = defineEmits<{ detected: [string] }>();

const video = ref<HTMLVideoElement | null>(null);
const active = ref(false);
const error = ref<string | null>(null);

let stream: MediaStream | null = null;
let frame: number | null = null;
let canvas: HTMLCanvasElement | null = null;

async function start() {
    error.value = null;

    if (!navigator.mediaDevices?.getUserMedia) {
        error.value = 'مرورگر شما به دوربین دسترسی نمی‌دهد. از جستجوی پلاک استفاده کنید.';
        return;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment' },
        });
    } catch {
        // معمولاً یعنی کاربر اجازه نداد یا صفحه روی HTTPS نیست
        error.value = 'دسترسی به دوربین ممکن نشد. از جستجوی پلاک استفاده کنید.';
        return;
    }

    active.value = true;

    // منتظر رندر شدن <video> بمانیم
    await new Promise((resolve) => requestAnimationFrame(resolve));

    if (!video.value) return;

    video.value.srcObject = stream;
    await video.value.play();

    canvas = document.createElement('canvas');
    scan();
}

function scan() {
    if (!active.value || !video.value || !canvas) return;

    const v = video.value;

    if (v.readyState === v.HAVE_ENOUGH_DATA) {
        canvas.width = v.videoWidth;
        canvas.height = v.videoHeight;

        const context = canvas.getContext('2d', { willReadFrequently: true });

        if (context) {
            context.drawImage(v, 0, 0, canvas.width, canvas.height);
            const image = context.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });

            if (code?.data) {
                stop();
                emit('detected', code.data);
                return;
            }
        }
    }

    frame = requestAnimationFrame(scan);
}

function stop() {
    active.value = false;

    if (frame !== null) {
        cancelAnimationFrame(frame);
        frame = null;
    }

    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

onUnmounted(stop);

defineExpose({ stop });
</script>

<template>
    <div class="space-y-3">
        <div v-if="active" class="relative overflow-hidden rounded-2xl bg-slate-900">
            <video ref="video" class="block w-full" muted playsinline />
            <div class="pointer-events-none absolute inset-0 grid place-items-center">
                <div class="size-48 rounded-2xl border-4 border-white/70 shadow-[0_0_0_9999px_rgba(0,0,0,0.35)]" />
            </div>
        </div>

        <div class="flex gap-2">
            <button
                v-if="!active"
                type="button"
                class="flex-1 rounded-xl bg-brand-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-brand-700"
                @click="start"
            >
                باز کردن دوربین و اسکن QR
            </button>
            <button
                v-else
                type="button"
                class="flex-1 rounded-xl border border-slate-300 px-4 py-3 text-sm text-slate-600 transition hover:bg-slate-50"
                @click="stop"
            >
                بستن دوربین
            </button>
        </div>

        <p v-if="error" class="text-sm text-amber-600">{{ error }}</p>
    </div>
</template>
