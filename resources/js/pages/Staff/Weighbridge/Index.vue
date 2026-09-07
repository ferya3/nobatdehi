<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import BarcodeListener from '@/components/BarcodeListener.vue';
import QrScanner from '@/components/QrScanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { useLiveChannel } from '@/lib/useLiveChannel';
import type { Appointment, PageProps, ScaleLive } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

interface Weighing {
    empty_weight_kg: string | null;
    loaded_weight_kg: string | null;
    net_weight_kg: string | null;
    variance_kg: string | null;
    is_overload: boolean;
    exit_permit_number: string | null;
}

type Found = Appointment & {
    stage: 'tare' | 'gross' | null;
    expected_net_kg: number | null;
    capacity_kg: number | null;
    weighing: Weighing | null;
};

const props = defineProps<{
    factoryId: number;
    scaleEnabled: boolean;
    requireStable: boolean;
    scales: ScaleLive[];
    pending: { tare: number; gross: number };
    barcodeEnabled: boolean;
    result?: { error: string | null; appointment: Found | null };
}>();

/** فاصله‌ی polling وقتی اتصال زنده نداریم — وزن سریع عوض می‌شود */
const POLL_MS = 2000;

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const scanner = ref<InstanceType<typeof QrScanner> | null>(null);

const found = computed(() => props.result?.appointment ?? null);
const error = computed(() => props.result?.error ?? null);
const stage = computed(() => found.value?.stage ?? null);

const form = useForm<{
    stage: string;
    weight_kg: string;
    reading_id: number | null;
    manual_reason: string;
    photo: File | null;
}>({
    stage: 'tare',
    weight_kg: '',
    reading_id: null,
    manual_reason: '',
    photo: null,
});

watch(stage, (value) => {
    if (value) form.stage = value;
});

// ---------------------------------------------------------- عددِ زنده‌ی باسکول

const scales = ref<ScaleLive[]>(props.scales ?? []);
const selectedScale = ref<string>(props.scales?.[0]?.scale ?? '');
const manualMode = ref(!props.scaleEnabled);

watch(
    () => props.scales,
    (fresh) => applyScales(fresh ?? []),
);

function applyScales(fresh: ScaleLive[]) {
    scales.value = fresh;

    // اپراتور یک بار باسکولش را انتخاب می‌کند و بعد دست نمی‌زند
    if (!fresh.some((s) => s.scale === selectedScale.value)) {
        selectedScale.value = fresh[0]?.scale ?? '';
    }
}

const live = computed(() => scales.value.find((s) => s.scale === selectedScale.value) ?? null);

/** عددی که با زدن «ثبت» واقعاً ذخیره می‌شود */
const usableReading = computed(() => {
    const reading = live.value;

    if (!reading) return null;
    if (props.requireStable && !reading.is_stable) return null;

    return reading;
});

const { connected } = useLiveChannel(props.scaleEnabled ? `factory.${props.factoryId}.weighbridge` : null, {
    'scale.read': () => refreshScales(),
});

let poll: number | null = null;

function schedulePoll() {
    if (poll !== null) window.clearTimeout(poll);
    if (!props.scaleEnabled) return;

    // اتصال زنده که برقرار باشد، polling فقط تور ایمنی است
    poll = window.setTimeout(
        () => refreshScales().finally(schedulePoll),
        connected.value ? POLL_MS * 10 : POLL_MS,
    );
}

async function refreshScales() {
    try {
        const response = await fetch(route('staff.weighbridge.readings'), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) return;

        const data = (await response.json()) as { scales: ScaleLive[] };
        applyScales(data.scales);
    } catch {
        // شبکه‌ی اتاقک باسکول قطع و وصل می‌شود؛ دفعه‌ی بعد دوباره تلاش می‌کنیم
    }
}

if (props.scaleEnabled) schedulePoll();

onUnmounted(() => {
    if (poll !== null) window.clearTimeout(poll);
});

const kg = (value: string | number | null | undefined) =>
    value === null || value === undefined ? '—' : Number(value).toLocaleString('en-US');

// وزن خالص را همین‌جا هم نشان می‌دهیم تا اپراتور پیش از ثبت ببیند چه می‌شود.
// مرجع همچنان سرور است؛ این فقط پیش‌نمایش است.
/** عددی که ثبت خواهد شد — از باسکول، یا از دستِ اپراتور */
const effectiveWeight = computed(() =>
    manualMode.value ? Number(form.weight_kg) : (usableReading.value?.weight_kg ?? 0),
);

const previewNet = computed(() => {
    const tare = Number(found.value?.weighing?.empty_weight_kg ?? 0);
    const gross = effectiveWeight.value;

    if (stage.value !== 'gross' || !tare || !gross || gross <= tare) return null;

    return gross - tare;
});

/** دکمه‌ی ثبت کِی باز است */
const canSubmit = computed(() => {
    if (manualMode.value) {
        return Number(form.weight_kg) > 0 && form.manual_reason.trim().length >= 8;
    }

    return usableReading.value !== null;
});

const previewOverload = computed(
    () => previewNet.value !== null && found.value?.capacity_kg !== null && previewNet.value > (found.value?.capacity_kg ?? Infinity),
);

const scanning = ref(false);

/**
 * دوربین و بارکدخوان یک مسیر دارند.
 *
 * برخلاف گیت، این ایستگاه ورود ثبت نمی‌کند و جایی برای نگه‌داشتنِ «منبع
 * اسکن» ندارد؛ پس فرستادنش فقط یک پارامتر بی‌مصرف بود.
 */
function onDetected(token: string) {
    scanning.value = true;

    router.post(
        route('staff.weighbridge.scan'),
        { token },
        { preserveScroll: true, onFinish: () => (scanning.value = false) },
    );
}

function submit() {
    if (!found.value) return;

    // یا شناسه‌ی خواندن می‌رود یا عددِ تایپ‌شده — هرگز هر دو. سرور هم
    // وقتی شناسه ببیند، عددِ فرم را اصلاً نگاه نمی‌کند.
    form.reading_id = manualMode.value ? null : (usableReading.value?.id ?? null);

    form.post(route('staff.weighbridge.record', found.value.ulid), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset('weight_kg', 'manual_reason', 'photo'),
    });
}

function reset() {
    scanner.value?.stop();
    form.reset();
    manualMode.value = !props.scaleEnabled;
    router.get(route('staff.weighbridge.index'));
}
</script>

<template>
    <StaffLayout title="باسکول">
        <div class="mx-auto max-w-xl space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-bold text-slate-900">باسکول</h1>
                <p class="text-xs text-slate-500">
                    منتظر توزین خالی: <span class="num font-semibold text-slate-800">{{ pending.tare }}</span>
                    &nbsp;·&nbsp;
                    منتظر توزین پر: <span class="num font-semibold text-slate-800">{{ pending.gross }}</span>
                </p>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>
            <AlertBox v-if="error" tone="error">{{ error }}</AlertBox>

            <section v-if="found" class="card overflow-hidden">
                <div class="flex items-center justify-between bg-slate-50 px-5 py-3">
                    <div>
                        <p class="text-xs text-slate-500">حواله</p>
                        <p class="num text-xl font-bold text-slate-900">{{ found.number }}</p>
                    </div>
                    <StatusBadge :tone="found.status_tone" :label="found.status_label" size="sm" />
                </div>

                <dl class="divide-y divide-slate-100">
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">پلاک</dt>
                        <dd><PlateBadge v-if="found.truck" :plate="found.truck.plate" size="sm" /></dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">راننده</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ found.driver?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">بار</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ found.product?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">تناژ حواله</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ kg(found.expected_net_kg) }} کیلوگرم</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">ظرفیت مجاز کامیون</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ kg(found.capacity_kg) }} کیلوگرم</dd>
                    </div>
                </dl>

                <!-- آنچه تا حالا ثبت شده -->
                <div v-if="found.weighing" class="grid grid-cols-3 divide-x divide-x-reverse divide-slate-100 border-t border-slate-100 text-center">
                    <div class="p-3">
                        <p class="text-xs text-slate-500">خالی</p>
                        <p class="num mt-1 text-sm font-semibold text-slate-800">{{ kg(found.weighing.empty_weight_kg) }}</p>
                    </div>
                    <div class="p-3">
                        <p class="text-xs text-slate-500">پر</p>
                        <p class="num mt-1 text-sm font-semibold text-slate-800">{{ kg(found.weighing.loaded_weight_kg) }}</p>
                    </div>
                    <div class="p-3">
                        <p class="text-xs text-slate-500">خالص</p>
                        <p class="num mt-1 text-sm font-bold text-brand-700">{{ kg(found.weighing.net_weight_kg) }}</p>
                    </div>
                </div>

                <div class="space-y-4 border-t border-slate-100 p-5">
                    <AlertBox v-if="found.weighing?.exit_permit_number" tone="success">
                        برگه خروج صادر شد:
                        <span class="font-mono font-bold" dir="ltr">{{ found.weighing.exit_permit_number }}</span>
                    </AlertBox>

                    <AlertBox v-else-if="found.weighing?.is_overload" tone="error">
                        اضافه‌بار ثبت شده است. تا کاهش بار و توزین دوباره، برگه خروج صادر نمی‌شود.
                    </AlertBox>

                    <template v-else-if="stage">
                        <h2 class="text-sm font-semibold text-slate-700">
                            {{ stage === 'tare' ? 'باسکول اول — توزین خالی' : 'باسکول دوم — توزین پر' }}
                        </h2>

                        <!-- عددِ زنده‌ی نشان‌دهنده -->
                        <div v-if="scaleEnabled && !manualMode" class="space-y-3">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-medium text-slate-700">عدد باسکول</p>
                                <span
                                    :class="[
                                        'rounded-full px-2.5 py-1 text-xs font-medium',
                                        connected ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-600',
                                    ]"
                                >
                                    {{ connected ? 'اتصال زنده' : 'به‌روزرسانی دوره‌ای' }}
                                </span>
                            </div>

                            <!-- وقتی چند باسکول هست، اپراتور باید بداند کدام -->
                            <div v-if="scales.length > 1" class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="option in scales"
                                    :key="option.scale"
                                    type="button"
                                    :class="[
                                        'rounded-xl border px-3 py-2 text-sm font-medium transition',
                                        selectedScale === option.scale
                                            ? 'border-brand-500 bg-brand-50 text-brand-800'
                                            : 'border-slate-300 text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="selectedScale = option.scale"
                                >
                                    {{ option.scale }}
                                </button>
                            </div>

                            <div
                                :class="[
                                    'rounded-2xl border-2 p-5 text-center transition',
                                    live === null
                                        ? 'border-slate-200 bg-slate-50'
                                        : live.is_stable
                                          ? 'border-emerald-300 bg-emerald-50'
                                          : 'border-amber-300 bg-amber-50',
                                ]"
                            >
                                <p v-if="live === null" class="py-3 text-sm text-slate-500">
                                    از باسکول عددی نمی‌رسد. اتصال پل و کابل را بررسی کنید.
                                </p>

                                <template v-else>
                                    <p class="num text-4xl font-bold tabular-nums text-slate-900" dir="ltr">
                                        {{ kg(live.weight_kg) }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-500">کیلوگرم</p>
                                    <p
                                        class="mt-2 text-xs font-medium"
                                        :class="live.is_stable ? 'text-emerald-800' : 'text-amber-800'"
                                    >
                                        {{ live.is_stable ? 'عقربه آرام گرفته' : 'هنوز نوسان دارد — صبر کنید' }}
                                        <span class="num text-slate-500">· {{ live.clock }}</span>
                                    </p>
                                </template>
                            </div>

                            <button
                                type="button"
                                class="text-xs font-medium text-slate-500 underline underline-offset-4 hover:text-slate-700"
                                @click="manualMode = true"
                            >
                                باسکول کار نمی‌کند؟ وزن را دستی وارد کنید
                            </button>
                        </div>

                        <!-- ورود دستی: باز است، ولی بی‌سروصدا نیست -->
                        <template v-else>
                            <FormField label="وزن (کیلوگرم)" for="weight_kg" :error="form.errors.weight_kg">
                                <TextInput
                                    id="weight_kg"
                                    v-model="form.weight_kg"
                                    inputmode="numeric"
                                    dir="ltr"
                                    placeholder="14500"
                                    :invalid="!!form.errors.weight_kg"
                                />
                            </FormField>

                            <FormField
                                v-if="scaleEnabled"
                                label="دلیل ورود دستی"
                                for="manual_reason"
                                :error="form.errors.manual_reason"
                                hint="در لاگ امنیتی به نام شما ثبت می‌شود."
                            >
                                <TextInput
                                    id="manual_reason"
                                    v-model="form.manual_reason"
                                    placeholder="مثلاً کابل باسکول قطع بود و عدد از روی نشان‌دهنده خوانده شد"
                                    :invalid="!!form.errors.manual_reason"
                                />
                            </FormField>

                            <button
                                v-if="scaleEnabled"
                                type="button"
                                class="text-xs font-medium text-slate-500 underline underline-offset-4 hover:text-slate-700"
                                @click="manualMode = false"
                            >
                                بازگشت به خواندن از باسکول
                            </button>
                        </template>

                        <FormField
                            label="عکس لحظه‌ی توزین"
                            for="photo"
                            :error="form.errors.photo"
                            hint="اختیاری ولی توصیه‌شده — سند اثباتِ وضعیت چرخ و بار"
                        >
                            <input
                                id="photo"
                                type="file"
                                accept="image/*"
                                capture="environment"
                                class="block w-full text-sm text-slate-600 file:ml-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700"
                                @change="form.photo = ($event.target as HTMLInputElement).files?.[0] ?? null"
                            />
                        </FormField>

                        <AlertBox v-if="previewOverload" tone="error">
                            با این وزن، خالص <span class="num font-bold">{{ kg(previewNet) }}</span> کیلوگرم می‌شود که از
                            ظرفیت مجاز بیشتر است. ثبت شود ولی برگه خروج صادر نخواهد شد.
                        </AlertBox>
                        <AlertBox v-else-if="previewNet !== null" tone="info">
                            وزن خالص: <span class="num font-bold">{{ kg(previewNet) }}</span> کیلوگرم
                        </AlertBox>

                        <AppButton size="lg" :loading="form.processing" :disabled="!canSubmit" @click="submit">
                            ثبت وزن
                        </AppButton>
                    </template>

                    <AlertBox v-else tone="warning">
                        این حواله الان کاری در باسکول ندارد. توزین خالی بعد از ورود به محوطه و توزین پر بعد از
                        پایان بارگیری انجام می‌شود.
                    </AlertBox>

                    <AppButton variant="secondary" size="lg" @click="reset">حواله‌ی بعدی</AppButton>
                </div>
            </section>

            <!-- بارکدخوان: بدون کلیک، همیشه گوش می‌دهد -->
            <section v-if="!found && barcodeEnabled" class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-700">بارکدخوان</h2>
                <BarcodeListener :busy="scanning" @scanned="onDetected" />
            </section>

            <section v-if="!found" class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-700">اسکن QR حواله</h2>
                <p class="text-xs text-slate-500">
                    همان کدی که راننده در گیت نشان داد. سامانه خودش تشخیص می‌دهد نوبت کدام باسکول است.
                </p>
                <QrScanner ref="scanner" @detected="onDetected" />
            </section>
        </div>
    </StaffLayout>
</template>
