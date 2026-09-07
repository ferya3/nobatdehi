<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import QrScanner from '@/components/QrScanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { Appointment, PageProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

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
    pending: { tare: number; gross: number };
    result?: { error: string | null; appointment: Found | null };
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const scanner = ref<InstanceType<typeof QrScanner> | null>(null);

const found = computed(() => props.result?.appointment ?? null);
const error = computed(() => props.result?.error ?? null);
const stage = computed(() => found.value?.stage ?? null);

const form = useForm<{ stage: string; weight_kg: string; source: string; photo: File | null }>({
    stage: 'tare',
    weight_kg: '',
    source: 'manual',
    photo: null,
});

watch(stage, (value) => {
    if (value) form.stage = value;
});

const kg = (value: string | number | null | undefined) =>
    value === null || value === undefined ? '—' : Number(value).toLocaleString('en-US');

// وزن خالص را همین‌جا هم نشان می‌دهیم تا اپراتور پیش از ثبت ببیند چه می‌شود.
// مرجع همچنان سرور است؛ این فقط پیش‌نمایش است.
const previewNet = computed(() => {
    const tare = Number(found.value?.weighing?.empty_weight_kg ?? 0);
    const gross = Number(form.weight_kg);

    if (stage.value !== 'gross' || !tare || !gross || gross <= tare) return null;

    return gross - tare;
});

const previewOverload = computed(
    () => previewNet.value !== null && found.value?.capacity_kg !== null && previewNet.value > (found.value?.capacity_kg ?? Infinity),
);

function onDetected(token: string) {
    router.post(route('staff.weighbridge.scan'), { token }, { preserveScroll: true });
}

function submit() {
    if (!found.value) return;

    form.post(route('staff.weighbridge.record', found.value.ulid), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset('weight_kg', 'photo'),
    });
}

function reset() {
    scanner.value?.stop();
    form.reset();
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

                        <FormField label="منبع وزن" :error="form.errors.source">
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="option in [
                                        { value: 'device', label: 'مستقیم از باسکول' },
                                        { value: 'manual', label: 'ورود دستی' },
                                    ]"
                                    :key="option.value"
                                    type="button"
                                    :class="[
                                        'rounded-xl border px-3 py-2.5 text-sm font-medium transition',
                                        form.source === option.value
                                            ? 'border-brand-500 bg-brand-50 text-brand-800'
                                            : 'border-slate-300 text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="form.source = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </FormField>

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

                        <AppButton size="lg" :loading="form.processing" :disabled="!form.weight_kg" @click="submit">
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

            <section v-else class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-700">اسکن QR حواله</h2>
                <p class="text-xs text-slate-500">
                    همان کدی که راننده در گیت نشان داد. سامانه خودش تشخیص می‌دهد نوبت کدام باسکول است.
                </p>
                <QrScanner ref="scanner" @detected="onDetected" />
            </section>
        </div>
    </StaffLayout>
</template>
