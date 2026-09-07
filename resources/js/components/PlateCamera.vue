<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * دوربینِ ایستگاه نگهبانی — عکس از پلاک کامیون.
 *
 * جدا از QrScanner است و عمداً: آن یکی دنبال کد می‌گردد و به‌محض پیدا کردنش
 * دوربین را می‌بندد. این یکی باید باز بماند تا نگهبان لحظه‌ی درست را ببیند
 * و عکس بگیرد.
 *
 * انتخاب دوربین در localStorage می‌ماند چون ایستگاه نگهبانی ثابت است: یک
 * دوربین رو به راننده و یکی رو به پلاک. نگهبان نباید هر شیفت دوباره انتخاب
 * کند.
 */

const props = withDefaults(defineProps<{ busy?: boolean }>(), { busy: false });

const emit = defineEmits<{ captured: [Blob] }>();

const DEVICE_KEY = 'gate.plateCamera.deviceId';

/** عرض بیشینه‌ی عکس: پلاک را خوانا نگه می‌دارد، حجم را مهار می‌کند */
const MAX_WIDTH = 1280;

const video = ref<HTMLVideoElement | null>(null);
const active = ref(false);
const error = ref<string | null>(null);
const devices = ref<MediaDeviceInfo[]>([]);
const deviceId = ref<string>(localStorage.getItem(DEVICE_KEY) ?? '');
const preview = ref<string | null>(null);

let stream: MediaStream | null = null;

const hasChoice = computed(() => devices.value.length > 1);

async function listDevices() {
    if (!navigator.mediaDevices?.enumerateDevices) return;

    try {
        const all = await navigator.mediaDevices.enumerateDevices();
        devices.value = all.filter((d) => d.kind === 'videoinput');
    } catch {
        devices.value = [];
    }
}

async function start() {
    error.value = null;

    if (!navigator.mediaDevices?.getUserMedia) {
        error.value = 'مرورگر شما به دوربین دسترسی نمی‌دهد.';
        return;
    }

    stop();

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            video: deviceId.value
                ? { deviceId: { exact: deviceId.value } }
                : { facingMode: 'environment' },
        });
    } catch {
        // دوربینِ ذخیره‌شده ممکن است دیگر وصل نباشد
        if (deviceId.value) {
            deviceId.value = '';
            localStorage.removeItem(DEVICE_KEY);
            error.value = 'دوربین انتخاب‌شده در دسترس نیست. دوباره تلاش کنید.';
            return;
        }

        error.value = 'دسترسی به دوربین ممکن نشد. صفحه باید روی HTTPS باز باشد.';
        return;
    }

    active.value = true;

    // نام دوربین‌ها فقط بعد از گرفتن اجازه معلوم می‌شود
    await listDevices();
    await new Promise((resolve) => requestAnimationFrame(resolve));

    if (!video.value) return;

    video.value.srcObject = stream;
    await video.value.play();
}

function capture() {
    const v = video.value;

    if (!v || v.readyState !== v.HAVE_ENOUGH_DATA) return;

    const scale = Math.min(1, MAX_WIDTH / v.videoWidth);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(v.videoWidth * scale);
    canvas.height = Math.round(v.videoHeight * scale);

    const context = canvas.getContext('2d');

    if (!context) return;

    context.drawImage(v, 0, 0, canvas.width, canvas.height);

    preview.value = canvas.toDataURL('image/jpeg', 0.75);

    canvas.toBlob(
        (blob) => {
            if (blob) emit('captured', blob);
        },
        'image/jpeg',
        0.75,
    );
}

function onDeviceChange() {
    if (deviceId.value) {
        localStorage.setItem(DEVICE_KEY, deviceId.value);
    } else {
        localStorage.removeItem(DEVICE_KEY);
    }

    if (active.value) start();
}

function stop() {
    active.value = false;
    stream?.getTracks().forEach((track) => track.stop());
    stream = null;
}

function clearPreview() {
    preview.value = null;
}

onMounted(listDevices);
onUnmounted(stop);

defineExpose({ stop, clearPreview });
</script>

<template>
    <div class="space-y-3">
        <div v-if="active" class="relative overflow-hidden rounded-2xl bg-slate-900">
            <video ref="video" class="block w-full" muted playsinline />
            <!-- کادر راهنما به شکل پلاک، تا نگهبان بداند کجا را بگیرد -->
            <div class="pointer-events-none absolute inset-0 grid place-items-center">
                <div class="h-20 w-64 max-w-[80%] rounded-lg border-4 border-white/70" />
            </div>
        </div>

        <img
            v-else-if="preview"
            :src="preview"
            alt="عکس ثبت‌شده‌ی پلاک"
            class="block w-full rounded-2xl border border-slate-200"
        />

        <select
            v-if="hasChoice"
            v-model="deviceId"
            class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm"
            @change="onDeviceChange"
        >
            <option value="">دوربین پیش‌فرض</option>
            <option v-for="(device, index) in devices" :key="device.deviceId" :value="device.deviceId">
                {{ device.label || `دوربین ${index + 1}` }}
            </option>
        </select>

        <div class="flex gap-2">
            <button
                v-if="!active"
                type="button"
                class="flex-1 rounded-xl bg-brand-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-brand-700"
                @click="start"
            >
                {{ preview ? 'گرفتن عکس دیگر' : 'باز کردن دوربین پلاک' }}
            </button>

            <template v-else>
                <button
                    type="button"
                    :disabled="props.busy"
                    class="flex-1 rounded-xl bg-brand-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-brand-700 disabled:opacity-50"
                    @click="capture"
                >
                    ثبت عکس پلاک
                </button>
                <button
                    type="button"
                    class="shrink-0 rounded-xl border border-slate-300 px-4 py-3 text-sm text-slate-600 transition hover:bg-slate-50"
                    @click="stop"
                >
                    بستن
                </button>
            </template>
        </div>

        <p v-if="error" class="text-sm text-amber-600">{{ error }}</p>
    </div>
</template>
