<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import BarcodeListener from '@/components/BarcodeListener.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import PlateCamera from '@/components/PlateCamera.vue';
import PlateInput from '@/components/PlateInput.vue';
import QrScanner from '@/components/QrScanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { useLiveChannel } from '@/lib/useLiveChannel';
import type { Appointment, PageProps, PlateParts, PlateReading } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    factoryId: number;
    onSiteCount: number;
    devices: { barcode: boolean; station_camera: boolean; anpr: boolean };
    readings: PlateReading[];
    result?: {
        error: string | null;
        appointment:
            | (Appointment & {
                  can_check_in: boolean;
                  is_today: boolean;
                  scanned: boolean;
                  needs_override: boolean;
                  may_override: boolean;
                  expected_plate: PlateParts | null;
              })
            | null;
    } | null;
}>();

// همان حروفی که در PlateNumber مجازند
const PLATE_LETTERS = [
    'الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ',
    'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م',
    'ن', 'و', 'ه', 'ی',
];

/** هر چند وقت خواندن‌های دوربین را بگیریم وقتی WebSocket نداریم */
const POLL_MS = 5000;

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const scanner = ref<InstanceType<typeof QrScanner> | null>(null);
const plateCamera = ref<InstanceType<typeof PlateCamera> | null>(null);
const checkingIn = ref(false);
const scanning = ref(false);

const lookup = useForm({
    plate_two: '',
    plate_letter: '',
    plate_three: '',
    plate_iran: '',
});

const found = computed(() => props.result?.appointment ?? null);
const error = computed(() => props.result?.error ?? null);

// راهبند تا وقتی پلاک تأیید نشده باز نمی‌شود — با دوربین یا با چشمِ نگهبان
const plateConfirmed = ref(false);
const overrideReason = ref('');

/** خواندنی که قرار است تصمیم‌گیرِ تطبیق پلاک باشد */
const chosenReading = ref<PlateReading | null>(null);
const capturing = ref(false);
const captureError = ref<string | null>(null);

/** خواندن‌های دوربین پلاک‌خوان — از WebSocket یا polling */
const readings = ref<PlateReading[]>(props.readings ?? []);

watch(
    () => props.readings,
    (fresh) => (readings.value = fresh ?? []),
);

const { connected } = useLiveChannel(props.devices.anpr ? `factory.${props.factoryId}.gate` : null, {
    'plate.read': () => refreshReadings(),
});

let poll: number | null = null;

function schedulePoll() {
    if (poll !== null) window.clearTimeout(poll);
    if (!props.devices.anpr) return;

    // اتصال زنده که برقرار باشد، polling فقط تور ایمنی است
    poll = window.setTimeout(() => {
        refreshReadings().finally(schedulePoll);
    }, connected.value ? POLL_MS * 6 : POLL_MS);
}

async function refreshReadings() {
    try {
        const response = await fetch(route('staff.gate.readings'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) return;

        const data = (await response.json()) as { readings: PlateReading[] };
        readings.value = data.readings;
    } catch {
        // شبکه‌ی ایستگاه نگهبانی قطع و وصل می‌شود؛ دفعه‌ی بعد دوباره تلاش می‌کنیم
    }
}

if (props.devices.anpr) schedulePoll();

onUnmounted(() => {
    if (poll !== null) window.clearTimeout(poll);
});

const blockedWithoutScan = computed(() => (found.value?.needs_override ?? false) && !(found.value?.may_override ?? false));

/** دوربین که پلاک را خوانده باشد، تیک نگهبان لازم نیست */
const plateSettledByDevice = computed(() => chosenReading.value?.recognised === true);

const readyToOpen = computed(() => {
    const a = found.value;
    if (!a?.can_check_in || blockedWithoutScan.value) return false;
    if (!plateConfirmed.value && !plateSettledByDevice.value) return false;
    if (a.needs_override && overrideReason.value.trim().length < 8) return false;
    return true;
});

const plateComplete = computed(
    () =>
        lookup.plate_two.length === 2 &&
        lookup.plate_letter !== '' &&
        lookup.plate_three.length === 3 &&
        lookup.plate_iran.length === 2,
);

/** خواندنِ دوربین با پلاکِ همین حواله می‌خواند؟ */
function matchesFound(reading: PlateReading): boolean {
    return reading.plate_key !== null && reading.plate_key === (found.value?.truck?.plate.key ?? null);
}

function submitScan(token: string, source: 'camera' | 'barcode') {
    scanning.value = true;

    router.post(
        route('staff.gate.scan'),
        { token, source },
        { preserveScroll: true, onFinish: () => (scanning.value = false) },
    );
}

function onDetected(token: string) {
    submitScan(token, 'camera');
}

function onBarcode(payload: string) {
    submitScan(payload, 'barcode');
}

function submitLookup() {
    lookup.post(route('staff.gate.lookup'), { preserveScroll: true });
}

/** عکسِ گرفته‌شده را می‌فرستد و همان را تصمیم‌گیرِ تطبیق می‌کند */
async function onPlateCaptured(blob: Blob) {
    capturing.value = true;
    captureError.value = null;

    const body = new FormData();
    body.append('image', blob, 'plate.jpg');

    if (found.value) body.append('appointment', found.value.ulid);

    try {
        const response = await fetch(route('staff.gate.capture'), {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        if (!response.ok) {
            captureError.value = 'ثبت عکس ناموفق بود. دوباره تلاش کنید.';
            return;
        }

        chosenReading.value = (await response.json()) as PlateReading;
        plateCamera.value?.stop();
    } catch {
        captureError.value = 'ارتباط با سرور برقرار نشد. عکس ثبت نشد.';
    } finally {
        capturing.value = false;
    }
}

function useReading(reading: PlateReading) {
    chosenReading.value = reading;
}

function checkIn() {
    if (!found.value) return;

    checkingIn.value = true;

    router.post(
        route('staff.gate.check-in', found.value.ulid),
        {
            plate_match: plateConfirmed.value,
            plate_reading_id: chosenReading.value?.id ?? null,
            override_reason: found.value.needs_override ? overrideReason.value.trim() : null,
        },
        { onFinish: () => (checkingIn.value = false) },
    );
}

function reportMismatch() {
    if (!found.value) return;

    checkingIn.value = true;

    // عمداً همان مسیر ورود است: سرور مغایرت را در لاگ امنیتی ثبت می‌کند
    router.post(
        route('staff.gate.check-in', found.value.ulid),
        { plate_match: false, plate_reading_id: chosenReading.value?.id ?? null },
        { onFinish: () => (checkingIn.value = false) },
    );
}

function reset() {
    scanner.value?.stop();
    plateCamera.value?.stop();
    plateCamera.value?.clearPreview();
    lookup.reset();
    plateConfirmed.value = false;
    overrideReason.value = '';
    chosenReading.value = null;
    captureError.value = null;
    router.get(route('staff.gate.index'));
}
</script>

<template>
    <StaffLayout title="ورود کامیون">
        <div class="mx-auto max-w-xl space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-bold text-slate-900">ورود کامیون</h1>
                <p class="text-sm text-slate-500">
                    در محوطه: <span class="num font-semibold text-slate-800">{{ onSiteCount }}</span>
                </p>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <!-- نتیجه‌ی اسکن یا جستجو -->
            <section v-if="found" class="card overflow-hidden">
                <div
                    :class="[
                        'flex items-center gap-2 px-5 py-3 text-sm font-medium',
                        error ? 'bg-rose-50 text-rose-800' : 'bg-emerald-50 text-emerald-800',
                    ]"
                >
                    <svg v-if="!error" class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path
                            fill-rule="evenodd"
                            d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.7-9.3a1 1 0 0 0-1.4-1.4L9 10.6 7.7 9.3a1 1 0 0 0-1.4 1.4l2 2a1 1 0 0 0 1.4 0l4-4Z"
                            clip-rule="evenodd"
                        />
                    </svg>
                    <span v-if="!error">نوبت معتبر</span>
                    <span v-else>{{ error }}</span>
                </div>

                <dl class="divide-y divide-slate-100">
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">شماره نوبت</dt>
                        <dd class="num text-2xl font-bold text-slate-900">{{ found.number }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">وضعیت</dt>
                        <dd><StatusBadge :tone="found.status_tone" :label="found.status_label" size="sm" /></dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">پلاک</dt>
                        <dd><PlateBadge v-if="found.truck" :plate="found.truck.plate" size="sm" /></dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">راننده</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ found.driver?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">ساعت نوبت</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ found.time }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">نوع بار</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ found.product?.name ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="space-y-3 border-t border-slate-100 p-5">
                    <AlertBox v-if="!found.is_today" tone="warning">
                        این نوبت برای امروز نیست ({{ found.jalali_long }}).
                    </AlertBox>

                    <!-- قانون ۱: بدون اسکن، ورود ممنوع -->
                    <AlertBox v-if="blockedWithoutScan" tone="error">
                        این نوبت با اسکن QR باز نشده است. ورود بدون اسکن ثبت نمی‌شود؛
                        از راننده بخواهید کد را در برنامه باز کند.
                    </AlertBox>

                    <template v-else-if="found.can_check_in">
                        <!-- قانون ۲: پلاکِ جلوی چشم باید با پلاک حواله یکی باشد -->
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-medium text-slate-700">پلاک حواله را با پلاک کامیون مقابل تطبیق دهید</p>

                            <div class="mt-3 flex justify-center">
                                <PlateBadge v-if="found.truck" :plate="found.truck.plate" />
                            </div>

                            <!-- عکسِ ثبت‌شده: حرفِ آخر را همین می‌زند، نه تیکِ نگهبان -->
                            <div
                                v-if="chosenReading"
                                :class="[
                                    'mt-4 rounded-xl border p-3',
                                    !chosenReading.recognised
                                        ? 'border-slate-200 bg-white'
                                        : matchesFound(chosenReading)
                                          ? 'border-emerald-200 bg-emerald-50'
                                          : 'border-rose-200 bg-rose-50',
                                ]"
                            >
                                <div class="flex items-start gap-3">
                                    <img
                                        v-if="chosenReading.image_url"
                                        :src="chosenReading.image_url"
                                        alt="عکس پلاک"
                                        class="size-20 shrink-0 rounded-lg border border-slate-200 object-cover"
                                    />
                                    <div class="min-w-0 flex-1 text-sm">
                                        <p class="font-medium text-slate-800">{{ chosenReading.source_label }}</p>
                                        <p v-if="chosenReading.recognised" class="num mt-1 text-slate-700">
                                            {{ chosenReading.plate_key }}
                                            <span v-if="chosenReading.confidence !== null" class="text-xs text-slate-500">
                                                (اطمینان {{ chosenReading.confidence }}٪)
                                            </span>
                                        </p>
                                        <p v-else class="mt-1 text-xs text-slate-600">
                                            پلاک از روی عکس خوانده نشد. عکس در سابقه ثبت شد؛ تطبیق با شماست.
                                        </p>
                                        <p
                                            v-if="chosenReading.recognised"
                                            :class="[
                                                'mt-1 text-xs font-medium',
                                                matchesFound(chosenReading) ? 'text-emerald-800' : 'text-rose-800',
                                            ]"
                                        >
                                            {{ matchesFound(chosenReading) ? 'با پلاک حواله یکی است.' : 'با پلاک حواله یکی نیست.' }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <PlateCamera
                                v-if="devices.station_camera"
                                ref="plateCamera"
                                class="mt-4"
                                :busy="capturing"
                                @captured="onPlateCaptured"
                            />

                            <p v-if="captureError" class="mt-2 text-sm text-rose-700">{{ captureError }}</p>

                            <!-- وقتی دوربین خوانده، تأیید چشمی موضوعیت ندارد -->
                            <label v-if="!plateSettledByDevice" class="mt-4 flex items-start gap-2.5">
                                <input
                                    v-model="plateConfirmed"
                                    type="checkbox"
                                    class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                />
                                <span class="text-sm text-slate-800">
                                    پلاک کامیون را دیدم و با پلاک بالا یکی است.
                                </span>
                            </label>

                            <button
                                type="button"
                                :disabled="checkingIn"
                                class="mt-3 text-xs font-medium text-rose-700 underline underline-offset-4 disabled:opacity-50"
                                @click="reportMismatch"
                            >
                                پلاک مغایرت دارد — ثبت و اطلاع به حراست
                            </button>
                        </div>

                        <!-- استثنا: فقط برای کسی که دسترسی‌اش را دارد -->
                        <div v-if="found.needs_override" class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                            <p class="text-sm font-medium text-amber-900">ثبت ورود بدون اسکن QR</p>
                            <p class="mt-1 text-xs text-amber-800">
                                این کار در لاگ امنیتی به نام شما ثبت می‌شود. دلیلش را بنویسید.
                            </p>
                            <TextInput
                                v-model="overrideReason"
                                class="mt-3"
                                placeholder="مثلاً گوشی راننده خاموش بود و با کارت ملی تطبیق داده شد"
                            />
                        </div>

                        <AppButton size="lg" :disabled="!readyToOpen" :loading="checkingIn" @click="checkIn">
                            ثبت ورود و باز کردن راهبند
                        </AppButton>
                    </template>

                    <AlertBox v-else-if="!error" tone="warning">
                        از این وضعیت نمی‌توان ورود ثبت کرد.
                    </AlertBox>

                    <AppButton variant="secondary" size="lg" @click="reset">بررسی کامیون بعدی</AppButton>
                </div>
            </section>

            <AlertBox v-else-if="error" tone="error">{{ error }}</AlertBox>

            <!-- اسکن و جستجو -->
            <template v-if="!found">
                <!-- بارکدخوان: بدون کلیک، همیشه گوش می‌دهد -->
                <section v-if="devices.barcode" class="card space-y-3 p-5">
                    <h2 class="text-sm font-semibold text-slate-700">بارکدخوان</h2>
                    <BarcodeListener :busy="scanning" @scanned="onBarcode" />
                </section>

                <section class="card space-y-3 p-5">
                    <h2 class="text-sm font-semibold text-slate-700">اسکن کد QR راننده</h2>
                    <QrScanner ref="scanner" @detected="onDetected" />
                </section>

                <!-- دوربین پلاک‌خوان شبکه‌ای: خودش می‌خواند، نگهبان انتخاب می‌کند -->
                <section v-if="devices.anpr" class="card space-y-3 p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-700">دوربین پلاک‌خوان</h2>
                        <span
                            :class="[
                                'rounded-full px-2.5 py-1 text-xs font-medium',
                                connected ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-600',
                            ]"
                        >
                            {{ connected ? 'اتصال زنده' : 'به‌روزرسانی دوره‌ای' }}
                        </span>
                    </div>

                    <p v-if="readings.length === 0" class="text-xs text-slate-500">
                        در چند دقیقه‌ی گذشته پلاکی خوانده نشده است.
                    </p>

                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="reading in readings" :key="reading.id" class="flex items-center gap-3 py-2.5">
                            <img
                                v-if="reading.image_url"
                                :src="reading.image_url"
                                alt="عکس پلاک"
                                class="size-12 shrink-0 rounded-lg border border-slate-200 object-cover"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="num text-sm font-medium text-slate-800">
                                    {{ reading.plate_key ?? 'خوانده نشد' }}
                                </p>
                                <p class="num text-xs text-slate-500">
                                    {{ reading.clock }}
                                    <span v-if="reading.lane"> — {{ reading.lane }}</span>
                                </p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50"
                                @click="useReading(reading)"
                            >
                                استفاده
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="card space-y-4 p-5">
                    <div>
                        <h2 class="text-sm font-semibold text-slate-700">جستجو با شماره پلاک</h2>
                        <p class="mt-1 text-xs text-slate-500">
                            فقط برای دیدن وضعیت نوبت. ورود از این راه ثبت نمی‌شود.
                        </p>
                    </div>

                    <PlateInput
                        v-model:two="lookup.plate_two"
                        v-model:letter="lookup.plate_letter"
                        v-model:three="lookup.plate_three"
                        v-model:iran="lookup.plate_iran"
                        :letters="PLATE_LETTERS"
                        :invalid="!!lookup.errors.plate_two"
                    />

                    <AppButton
                        size="lg"
                        :disabled="!plateComplete"
                        :loading="lookup.processing"
                        @click="submitLookup"
                    >
                        جستجوی نوبت
                    </AppButton>
                </section>
            </template>
        </div>
    </StaffLayout>
</template>
