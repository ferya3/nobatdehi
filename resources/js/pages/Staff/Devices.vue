<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { copyText } from '@/lib/clipboard';
import type { PageProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface RecentScaleReading {
    id: number;
    scale: string;
    device: string | null;
    weight_kg: number;
    is_stable: boolean;
    raw_frame: string | null;
    at: string | null;
}

interface RecentReading {
    id: number;
    source_label: string;
    device: string | null;
    lane: string | null;
    raw_plate: string | null;
    plate_key: string | null;
    confidence: number | null;
    recognised: boolean;
    appointment: number | null;
    image_url: string | null;
    at: string | null;
}

const props = defineProps<{
    settings: {
        gate_barcode_enabled: boolean;
        gate_station_camera_enabled: boolean;
        gate_anpr_enabled: boolean;
        gate_anpr_min_confidence: number;
        gate_reading_retention_days: number;
        scale_device_enabled: boolean;
        scale_require_stable: boolean;
        scale_reading_retention_days: number;
    };
    tokenIsSet: boolean;
    scaleTokenIsSet: boolean;
    endpoint: string;
    scaleEndpoint: string;
    headerName: string;
    recent: RecentReading[];
    recentScale: RecentScaleReading[];
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

// توکن تازه فقط همین یک بار در flash می‌آید و بعد دیگر قابل دیدن نیست
const freshToken = computed(() => (page.props.flash as Record<string, unknown>).token as string | undefined);
const freshTokenKind = computed(() => (page.props.flash as Record<string, unknown>).tokenKind as string | undefined);

const copied = ref(false);
const copyFailed = ref(false);
const rotating = ref(false);

const form = useForm({ ...props.settings });

function save() {
    form.put(route('staff.settings.devices.update'), { preserveScroll: true });
}

function rotate(kind: 'gate' | 'scale') {
    rotating.value = true;
    router.post(
        route('staff.settings.devices.token'),
        { kind },
        { preserveScroll: true, onFinish: () => (rotating.value = false) },
    );
}

const kg = (value: number) => value.toLocaleString('en-US');

async function copy(value: string) {
    const ok = await copyText(value);

    copied.value = ok;
    copyFailed.value = !ok;

    window.setTimeout(() => {
        copied.value = false;
        copyFailed.value = false;
    }, 2500);
}
</script>

<template>
    <StaffLayout title="دستگاه‌های گیت">
        <div class="mx-auto max-w-2xl space-y-5">
            <h1 class="text-lg font-bold text-slate-900">دستگاه‌های گیت</h1>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <!-- توکن تازه: یک بار نشان داده می‌شود و تمام -->
            <div v-if="freshToken" class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                <p class="text-sm font-semibold text-amber-900">
                    توکن تازه‌ی {{ freshTokenKind === 'scale' ? 'پلِ باسکول' : 'دوربین' }}
                </p>
                <p class="mt-1 text-xs text-amber-800">
                    همین حالا در تنظیمات دستگاه بگذارید. بعد از خروج از این صفحه دیگر نمایش داده نمی‌شود.
                </p>
                <div class="mt-3 flex gap-2">
                    <code class="min-w-0 flex-1 truncate rounded-lg bg-white px-3 py-2.5 text-xs text-slate-800">
                        {{ freshToken }}
                    </code>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg border border-amber-300 bg-white px-3 py-2.5 text-xs font-medium text-amber-900"
                        @click="copy(freshToken)"
                    >
                        {{ copied ? 'کپی شد' : 'کپی' }}
                    </button>
                </div>
                <p v-if="copyFailed" class="mt-2 text-xs text-amber-800">
                    کپی خودکار ممکن نشد. متن بالا را دستی انتخاب کنید.
                </p>
            </div>

            <!-- بارکدخوان و دوربین ایستگاه -->
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold text-slate-700">ایستگاه نگهبانی</h2>

                <label class="flex items-start gap-2.5">
                    <input
                        v-model="form.gate_barcode_enabled"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-sm">
                        <span class="font-medium text-slate-800">بارکدخوان</span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            بارکدخوان USB یا بلوتوث که خودش را کیبورد معرفی می‌کند. لازم نیست
                            نگهبان جایی کلیک کند؛ صفحه خودش کدِ خوانده‌شده را می‌گیرد.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-2.5">
                    <input
                        v-model="form.gate_station_camera_enabled"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-sm">
                        <span class="font-medium text-slate-800">دوربین عکس پلاک</span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            وب‌کم یا دوربین تبلتِ نگهبانی. عکسِ پلاک در سابقه‌ی نوبت ثبت می‌شود،
                            حتی وقتی راهبند باز نشود.
                        </span>
                    </span>
                </label>
            </section>

            <!-- دوربین پلاک‌خوان شبکه‌ای -->
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold text-slate-700">دوربین پلاک‌خوان شبکه‌ای</h2>

                <label class="flex items-start gap-2.5">
                    <input
                        v-model="form.gate_anpr_enabled"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-sm">
                        <span class="font-medium text-slate-800">دریافت خواندن از دوربین</span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            تا وقتی خاموش است، مسیر دریافت اصلاً وجود ندارد و دوربین ۴۰۴ می‌گیرد.
                        </span>
                    </span>
                </label>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <p class="font-medium text-slate-700">تنظیماتی که در دوربین وارد می‌شود</p>

                    <dl class="mt-3 space-y-2.5">
                        <div>
                            <dt class="text-xs text-slate-500">آدرس (POST)</dt>
                            <dd class="mt-1 flex gap-2">
                                <code class="min-w-0 flex-1 truncate rounded-lg bg-white px-3 py-2 text-xs">{{ endpoint }}</code>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"
                                    @click="copy(endpoint)"
                                >
                                    کپی
                                </button>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">هدر احراز هویت</dt>
                            <dd class="mt-1">
                                <code class="rounded-lg bg-white px-3 py-2 text-xs">{{ headerName }}: &lt;توکن&gt;</code>
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-500">
                        فیلدهای پذیرفته‌شده: <code class="num">plate</code>، <code class="num">confidence</code>،
                        <code class="num">lane</code>، <code class="num">device</code>،
                        <code class="num">captured_at</code> و عکس به‌صورت <code class="num">image</code>
                        (base64) یا <code class="num">image_file</code> (multipart).
                    </p>

                    <div class="mt-4 flex items-center gap-3">
                        <AppButton variant="secondary" :loading="rotating" @click="rotate('gate')">
                            {{ tokenIsSet ? 'ساخت توکن تازه' : 'ساخت توکن' }}
                        </AppButton>
                        <p class="text-xs" :class="tokenIsSet ? 'text-emerald-700' : 'text-amber-700'">
                            {{ tokenIsSet ? 'توکن تنظیم شده است.' : 'هنوز توکنی ساخته نشده.' }}
                        </p>
                    </div>

                    <p v-if="tokenIsSet" class="mt-2 text-xs text-slate-500">
                        ساخت توکن تازه، توکن قبلی را از همان لحظه بی‌اعتبار می‌کند.
                    </p>
                </div>

                <FormField
                    label="حداقل اطمینان دوربین (٪)"
                    :error="form.errors.gate_anpr_min_confidence"
                    hint="خواندنِ کم‌اطمینان‌تر از این تصمیم‌گیر نیست و تطبیق به نگهبان برمی‌گردد — «مطمئن نیستم» با «غلط است» یکی نیست."
                >
                    <input
                        v-model.number="form.gate_anpr_min_confidence"
                        type="number"
                        min="0"
                        max="100"
                        class="num w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm"
                    />
                </FormField>

                <FormField
                    label="مدت نگهداری خواندن‌ها (روز)"
                    :error="form.errors.gate_reading_retention_days"
                    hint="خواندن‌هایی که به نوبتی گره نخورده‌اند بعد از این مدت پاک می‌شوند. عکسِ ورودِ ثبت‌شده با خودِ نوبت می‌ماند."
                >
                    <input
                        v-model.number="form.gate_reading_retention_days"
                        type="number"
                        min="1"
                        max="365"
                        class="num w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm"
                    />
                </FormField>
            </section>

            <!-- پلِ نشان‌دهنده‌ی باسکول -->
            <section class="card space-y-4 p-5">
                <h2 class="text-sm font-semibold text-slate-700">باسکول</h2>

                <label class="flex items-start gap-2.5">
                    <input
                        v-model="form.scale_device_enabled"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-sm">
                        <span class="font-medium text-slate-800">دریافت وزن از نشان‌دهنده</span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            پلِ باسکول روی کامپیوترِ اتاقک اجرا می‌شود، پورت COM را می‌خواند و
                            عدد را می‌فرستد. تا وقتی این خاموش است، مسیرش ۴۰۴ می‌دهد و اپراتور
                            وزن را دستی وارد می‌کند.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-2.5">
                    <input
                        v-model="form.scale_require_stable"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-sm">
                        <span class="font-medium text-slate-800">فقط وزن پایدار ثبت شود</span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            تا عقربه آرام نگرفته، دکمه‌ی ثبت باز نمی‌شود. اگر نشان‌دهنده‌ی شما
                            پرچم پایداری نمی‌فرستد این را خاموش کنید — وگرنه هیچ وزنی ثبت
                            نخواهد شد.
                        </span>
                    </span>
                </label>

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                    <p class="font-medium text-slate-700">تنظیماتی که در پل وارد می‌شود</p>

                    <dl class="mt-3 space-y-2.5">
                        <div>
                            <dt class="text-xs text-slate-500">آدرس (POST)</dt>
                            <dd class="mt-1 flex gap-2">
                                <code class="min-w-0 flex-1 truncate rounded-lg bg-white px-3 py-2 text-xs">{{ scaleEndpoint }}</code>
                                <button
                                    type="button"
                                    class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs"
                                    @click="copy(scaleEndpoint)"
                                >
                                    کپی
                                </button>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">هدر احراز هویت</dt>
                            <dd class="mt-1">
                                <code class="rounded-lg bg-white px-3 py-2 text-xs">{{ headerName }}: &lt;توکن&gt;</code>
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-500">
                        این‌ها در <code class="num">bridge/scale/config.json</code> روی کامپیوترِ باسکول
                        گذاشته می‌شوند. راهنمای کامل در <code class="num">bridge/scale/README.md</code> است.
                    </p>

                    <div class="mt-4 flex items-center gap-3">
                        <AppButton variant="secondary" :loading="rotating" @click="rotate('scale')">
                            {{ scaleTokenIsSet ? 'ساخت توکن تازه' : 'ساخت توکن' }}
                        </AppButton>
                        <p class="text-xs" :class="scaleTokenIsSet ? 'text-emerald-700' : 'text-amber-700'">
                            {{ scaleTokenIsSet ? 'توکن تنظیم شده است.' : 'هنوز توکنی ساخته نشده.' }}
                        </p>
                    </div>
                </div>

                <FormField
                    label="مدت نگهداری خواندن‌های باسکول (روز)"
                    :error="form.errors.scale_reading_retention_days"
                    hint="عددهایی که به حواله‌ای گره نخورده‌اند بعد از این مدت پاک می‌شوند. وزنِ ثبت‌شده‌ی هر حواله با خودش می‌ماند."
                >
                    <input
                        v-model.number="form.scale_reading_retention_days"
                        type="number"
                        min="1"
                        max="365"
                        class="num w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm"
                    />
                </FormField>
            </section>

            <AppButton size="lg" :loading="form.processing" @click="save">ذخیره تنظیمات</AppButton>

            <!-- «دوربین وصل است؟» با تیک تنظیمات جواب داده نمی‌شود -->
            <section class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-slate-700">آخرین خواندن‌ها</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        اگر دوربین درست وصل باشد، اینجا ردیف تازه می‌بینید.
                    </p>
                </div>

                <p v-if="recent.length === 0" class="px-5 py-8 text-center text-sm text-slate-500">
                    هنوز خواندنی ثبت نشده است.
                </p>

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="reading in recent" :key="reading.id" class="flex items-center gap-3 px-5 py-3">
                        <a v-if="reading.image_url" :href="reading.image_url" target="_blank" class="shrink-0">
                            <img
                                :src="reading.image_url"
                                alt="عکس پلاک"
                                class="size-12 rounded-lg border border-slate-200 object-cover"
                            />
                        </a>
                        <div v-else class="grid size-12 shrink-0 place-items-center rounded-lg bg-slate-100 text-xs text-slate-400">
                            بی‌عکس
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="num text-sm font-medium text-slate-800">
                                {{ reading.plate_key ?? reading.raw_plate ?? 'خوانده نشد' }}
                                <span v-if="reading.confidence !== null" class="text-xs font-normal text-slate-500">
                                    ({{ reading.confidence }}٪)
                                </span>
                            </p>
                            <p class="num text-xs text-slate-500">
                                {{ reading.at }} — {{ reading.source_label }}
                                <span v-if="reading.device"> / {{ reading.device }}</span>
                                <span v-if="reading.lane"> / {{ reading.lane }}</span>
                            </p>
                        </div>

                        <span
                            v-if="reading.appointment"
                            class="num shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-900"
                        >
                            نوبت {{ reading.appointment }}
                        </span>
                    </li>
                </ul>
            </section>

            <!-- «پل وصل است؟» با تیک تنظیمات جواب داده نمی‌شود -->
            <section class="card overflow-hidden">
                <div class="border-b border-slate-100 px-5 py-4">
                    <h2 class="text-sm font-semibold text-slate-700">آخرین عددهای باسکول</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        اگر پل درست کار کند، اینجا ردیف تازه می‌بینید — حتی وقتی باسکول خالی است.
                    </p>
                </div>

                <p v-if="recentScale.length === 0" class="px-5 py-8 text-center text-sm text-slate-500">
                    هنوز عددی از باسکول نرسیده است.
                </p>

                <ul v-else class="divide-y divide-slate-100">
                    <li v-for="reading in recentScale" :key="reading.id" class="flex items-center gap-3 px-5 py-3">
                        <span
                            :class="[
                                'size-2.5 shrink-0 rounded-full',
                                reading.is_stable ? 'bg-emerald-500' : 'bg-amber-400',
                            ]"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="num text-sm font-medium text-slate-800" dir="ltr">
                                {{ kg(reading.weight_kg) }}
                                <span class="text-xs font-normal text-slate-500">kg</span>
                            </p>
                            <p class="num text-xs text-slate-500">
                                {{ reading.at }} — {{ reading.scale }}
                                <span v-if="reading.device"> / {{ reading.device }}</span>
                            </p>
                        </div>
                        <!-- فریم خام: وقتی پارسر اشتباه بخواند، اینجا معلوم می‌شود -->
                        <code
                            v-if="reading.raw_frame"
                            class="shrink-0 max-w-[40%] truncate rounded bg-slate-50 px-2 py-1 text-xs text-slate-500"
                            dir="ltr"
                        >
                            {{ reading.raw_frame }}
                        </code>
                    </li>
                </ul>
            </section>
        </div>
    </StaffLayout>
</template>
