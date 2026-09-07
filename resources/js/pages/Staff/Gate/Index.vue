<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import PlateInput from '@/components/PlateInput.vue';
import QrScanner from '@/components/QrScanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { Appointment, PageProps, PlateParts } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    onSiteCount: number;
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
    };
}>();

// همان حروفی که در PlateNumber مجازند
const PLATE_LETTERS = [
    'الف', 'ب', 'پ', 'ت', 'ث', 'ج', 'چ', 'ح', 'خ', 'د', 'ذ', 'ر', 'ز', 'ژ',
    'س', 'ش', 'ص', 'ض', 'ط', 'ظ', 'ع', 'غ', 'ف', 'ق', 'ک', 'گ', 'ل', 'م',
    'ن', 'و', 'ه', 'ی',
];

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const scanner = ref<InstanceType<typeof QrScanner> | null>(null);
const checkingIn = ref(false);

const lookup = useForm({
    plate_two: '',
    plate_letter: '',
    plate_three: '',
    plate_iran: '',
});

const found = computed(() => props.result?.appointment ?? null);
const error = computed(() => props.result?.error ?? null);

// راهبند تا وقتی نگهبان پلاک را تأیید نکرده باز نمی‌شود
const plateConfirmed = ref(false);
const overrideReason = ref('');

const blockedWithoutScan = computed(() => (found.value?.needs_override ?? false) && !(found.value?.may_override ?? false));

const readyToOpen = computed(() => {
    const a = found.value;
    if (!a?.can_check_in || blockedWithoutScan.value) return false;
    if (!plateConfirmed.value) return false;
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

function onDetected(token: string) {
    router.post(route('staff.gate.scan'), { token }, { preserveScroll: true });
}

function submitLookup() {
    lookup.post(route('staff.gate.lookup'), { preserveScroll: true });
}

function checkIn() {
    if (!found.value) return;

    checkingIn.value = true;

    router.post(
        route('staff.gate.check-in', found.value.ulid),
        {
            plate_match: plateConfirmed.value,
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
        { plate_match: false },
        { onFinish: () => (checkingIn.value = false) },
    );
}

function reset() {
    scanner.value?.stop();
    lookup.reset();
    plateConfirmed.value = false;
    overrideReason.value = '';
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

                            <label class="mt-4 flex items-start gap-2.5">
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
                <section class="card space-y-3 p-5">
                    <h2 class="text-sm font-semibold text-slate-700">اسکن کد QR راننده</h2>
                    <QrScanner ref="scanner" @detected="onDetected" />
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
