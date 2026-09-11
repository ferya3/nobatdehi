<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import BarcodeListener from '@/components/BarcodeListener.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { Appointment, PageProps, PlateParts, StatusTone } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

type Step = 'check-in' | 'tare' | 'start-loading' | 'finish-loading' | 'gross' | 'resolve' | 'exit' | null;

interface Row {
    ulid: string;
    number: number;
    time: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_active: boolean;
    plate: PlateParts | null;
    plate_key: string | null;
    driver: string | null;
    product: string | null;
    truck_type: string | null;
    next: Step;
    next_label: string | null;
    mine: boolean;
    alert: boolean;
}

type Selected = Appointment & {
    next: Step;
    next_label: string | null;
    mine: boolean;
    blocked: string | null;
    capacity_kg: number | null;
    tare_kg: string | null;
    scanned: boolean;
};

const props = defineProps<{
    counters: Record<string, number>;
    jalaliDate: string;
    rows: Row[];
    selected: Selected | null;
    loadingPoints: { id: number; name: string }[];
    barcodeEnabled: boolean;
    can: Record<string, boolean>;
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

// ------------------------------------------------------------------ صف زنده

const REFRESH_MS = 5_000;

const search = ref('');

const visible = computed(() => {
    const term = search.value.trim();

    if (term === '') return props.rows;

    const digits = term.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)));

    return props.rows.filter(
        (row) =>
            String(row.number).includes(digits) ||
            (row.plate_key ?? '').includes(digits) ||
            (row.driver ?? '').includes(term),
    );
});

/** کارهایی که همین کاربر می‌تواند انجام دهد — بقیه فقط دیدنی‌اند */
const mine = computed(() => visible.value.filter((row) => row.mine && row.next !== null));

let timer: number | null = null;

function schedule() {
    if (timer !== null) window.clearTimeout(timer);

    timer = window.setTimeout(() => {
        // صفحه‌ای که کسی نگاهش نمی‌کند، سرور را بی‌خود مشغول نکند
        if (!document.hidden && !busy.value) {
            router.reload({ only: ['rows', 'counters'], onFinish: schedule });

            return;
        }

        schedule();
    }, REFRESH_MS);
}

schedule();

onUnmounted(() => {
    if (timer !== null) window.clearTimeout(timer);
});

// ------------------------------------------------------------- انتخاب کامیون

function select(ulid: string | null) {
    router.get(
        route('staff.console'),
        ulid === null ? {} : { waybill: ulid },
        { preserveScroll: true, preserveState: true, only: ['selected'] },
    );
}

/** بارکدخوان: کد را می‌خواند و همان کامیون را جلو می‌آورد */
function onBarcode(payload: string) {
    router.post(
        route('staff.gate.scan'),
        { token: payload, source: 'barcode', console: 1 },
        { preserveScroll: true },
    );
}

// -------------------------------------------------------------------- کارها

const busy = ref(false);

const checkIn = useForm({ plate_match: true, override_reason: '', console: 1 });
const weight = useForm({ stage: '', weight_kg: '', console: 1 });
const move = useForm({ to: '', loading_point_id: null as number | null, console: 1 });
const verdict = useForm({ decision: '', reason: '', console: 1 });

/**
 * فرم‌ها بین دو قدم پاک می‌شوند، نه فقط بین دو کامیون.
 *
 * کنسول همان صفحه می‌ماند و کامپوننت دوباره ساخته نمی‌شود، پس عددی که در
 * «توزین خالی» تایپ شده بود تا «توزین پر» زنده می‌ماند. اپراتور به باسکول
 * دوم می‌رسید و وزن خالی از قبل در جعبه نشسته بود: اگر همان را ثبت می‌کرد
 * سرور ردش می‌کرد («پر از خالی بیشتر نیست») و اگر رویش تایپ می‌کرد، عددی
 * می‌ساخت که هیچ باسکولی نگفته بود.
 */
watch(
    () => `${props.selected?.ulid ?? ''}:${props.selected?.next ?? ''}`,
    () => {
        checkIn.reset();
        weight.reset();
        move.reset();
        verdict.reset();
    },
);

const options = { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) };

function doCheckIn() {
    if (!props.selected) return;

    checkIn.post(route('staff.gate.check-in', props.selected.ulid), options);
}

function doWeigh(stage: 'tare' | 'gross') {
    if (!props.selected) return;

    weight.stage = stage;
    weight.post(route('staff.weighbridge.record', props.selected.ulid), options);
}

function doMove(to: 'LOADING' | 'LOADED') {
    if (!props.selected) return;

    move.to = to;
    move.post(route('staff.loading.transition', props.selected.ulid), options);
}

function doExit() {
    if (!props.selected) return;

    router.post(
        route('staff.queue.transition', props.selected.ulid),
        { to: 'COMPLETED', console: 1 },
        options,
    );
}

function doVerdict(decision: 'approved' | 'rejected') {
    if (!props.selected) return;

    verdict.decision = decision;
    verdict.post(route('staff.queue.weight-discrepancy', props.selected.ulid), options);
}

// ------------------------------------------------------------------- نمایش

const needsOverride = computed(() => props.selected !== null && !props.selected.scanned);

const kg = (value: string | number | null | undefined) =>
    value === null || value === undefined ? '—' : Number(value).toLocaleString('en-US');

const TILES = [
    { key: 'waiting', label: 'در انتظار' },
    { key: 'on_site', label: 'در محوطه' },
    { key: 'loading', label: 'بارگیری' },
    { key: 'completed', label: 'خارج‌شده' },
];
</script>

<template>
    <StaffLayout title="کنسول محوطه">
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-bold text-slate-900">کنسول محوطه</h1>
                    <p class="mt-0.5 text-xs text-slate-500">{{ jalaliDate }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <div
                        v-for="tile in TILES"
                        :key="tile.key"
                        class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-center"
                    >
                        <p class="num text-base font-bold text-slate-900">{{ counters[tile.key] ?? 0 }}</p>
                        <p class="text-[11px] text-slate-500">{{ tile.label }}</p>
                    </div>
                </div>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                <!-- صف امروز -->
                <section class="card overflow-hidden lg:order-first">
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3">
                        <h2 class="shrink-0 text-sm font-semibold text-slate-700">
                            صف امروز
                            <span v-if="mine.length" class="mr-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs text-brand-800">
                                <span class="num">{{ mine.length }}</span> کار
                            </span>
                        </h2>

                        <TextInput v-model="search" placeholder="پلاک، شماره نوبت یا نام راننده" class="text-sm" />
                    </div>

                    <div v-if="barcodeEnabled" class="border-b border-slate-100 px-4 py-3">
                        <BarcodeListener :busy="busy" @scanned="onBarcode" />
                    </div>

                    <ul class="max-h-[34rem] divide-y divide-slate-100 overflow-y-auto">
                        <li v-for="row in visible" :key="row.ulid">
                            <button
                                type="button"
                                class="flex w-full items-center gap-3 px-4 py-2.5 text-right transition"
                                :class="[
                                    selected?.ulid === row.ulid ? 'bg-brand-50' : 'hover:bg-slate-50',
                                    !row.is_active && 'opacity-60',
                                ]"
                                @click="select(row.ulid)"
                            >
                                <span class="num w-8 shrink-0 text-sm font-bold text-slate-800">{{ row.number }}</span>

                                <span class="min-w-0 flex-1">
                                    <PlateBadge v-if="row.plate" :plate="row.plate" size="sm" />
                                    <span class="mt-0.5 block truncate text-xs text-slate-500">
                                        {{ row.driver ?? '—' }}
                                        <span v-if="row.product"> · {{ row.product }}</span>
                                    </span>
                                </span>

                                <span class="shrink-0 text-left">
                                    <span
                                        v-if="row.alert"
                                        class="block rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-800"
                                    >
                                        ⚠ اضافه‌بار
                                    </span>
                                    <span
                                        v-else-if="row.next_label"
                                        class="block rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="
                                            row.mine
                                                ? 'bg-brand-600 text-white'
                                                : 'bg-slate-100 text-slate-500'
                                        "
                                    >
                                        {{ row.next_label }}
                                    </span>
                                    <StatusBadge v-else :tone="row.status_tone" :label="row.status_label" size="sm" />

                                    <span class="num mt-0.5 block text-[11px] text-slate-400">{{ row.time }}</span>
                                </span>
                            </button>
                        </li>

                        <li v-if="!visible.length" class="px-4 py-12 text-center text-sm text-slate-500">
                            {{ search ? 'چیزی پیدا نشد.' : 'امروز نوبتی ثبت نشده است.' }}
                        </li>
                    </ul>
                </section>

                <!-- کامیونِ انتخاب‌شده و کارِ بعدی‌اش -->
                <section v-if="selected" class="card order-first space-y-4 p-5 lg:order-none">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="num text-lg font-bold text-slate-900">نوبت {{ selected.number }}</p>
                            <PlateBadge v-if="selected.truck" :plate="selected.truck.plate" class="mt-1.5" />
                        </div>

                        <button
                            type="button"
                            class="shrink-0 text-xs text-slate-400 transition hover:text-slate-600"
                            @click="select(null)"
                        >
                            بستن
                        </button>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500">راننده</dt>
                            <dd class="text-slate-800">{{ selected.driver?.name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">وضعیت</dt>
                            <dd><StatusBadge :tone="selected.status_tone" :label="selected.status_label" size="sm" /></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">نوع بار</dt>
                            <dd class="text-slate-800">{{ selected.product?.name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">ظرفیت مجاز</dt>
                            <dd class="num text-slate-800" dir="ltr">{{ kg(selected.capacity_kg) }}</dd>
                        </div>
                        <div v-if="selected.tare_kg">
                            <dt class="text-xs text-slate-500">وزن خالی</dt>
                            <dd class="num text-slate-800" dir="ltr">{{ kg(selected.tare_kg) }}</dd>
                        </div>
                        <div v-if="selected.weighing?.net_kg">
                            <dt class="text-xs text-slate-500">وزن خالص</dt>
                            <dd class="num font-semibold text-slate-900" dir="ltr">{{ kg(selected.weighing.net_kg) }}</dd>
                        </div>
                    </dl>

                    <div
                        v-if="selected.weighing?.exit_permit_number"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-900"
                    >
                        <span>برگه خروج <span class="num">{{ selected.weighing.exit_permit_number }}</span></span>

                        <a
                            :href="route('staff.queue.exit-permit', selected.ulid)"
                            target="_blank"
                            class="rounded-lg border border-emerald-300 bg-white px-2.5 py-1 text-xs transition hover:bg-emerald-100"
                        >
                            چاپ برگه
                        </a>
                    </div>

                    <!-- کارِ بعدی -->
                    <div v-if="selected.next && selected.mine" class="space-y-3 border-t border-slate-100 pt-4">
                        <p class="text-sm font-semibold text-slate-700">{{ selected.next_label }}</p>

                        <!-- ثبت ورود -->
                        <template v-if="selected.next === 'check-in'">
                            <label class="flex items-center gap-2 text-sm text-slate-700">
                                <input
                                    v-model="checkIn.plate_match"
                                    type="checkbox"
                                    class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                                />
                                پلاک را دیدم و با حواله مطابق است
                            </label>

                            <template v-if="needsOverride">
                                <p class="text-xs text-amber-700">
                                    QR این حواله اسکن نشده. ثبت ورود بدون اسکن نیاز به دلیل دارد.
                                </p>

                                <TextInput
                                    v-if="can.override"
                                    v-model="checkIn.override_reason"
                                    placeholder="مثلاً: گوشی راننده خاموش بود و پلاک با کارت شناسایی تطبیق داده شد"
                                    :invalid="!!checkIn.errors.override_reason"
                                />

                                <AlertBox v-else tone="warning">
                                    ورود بدون اسکن فقط با مدیر شیفت انجام می‌شود.
                                </AlertBox>
                            </template>

                            <AppButton
                                size="lg"
                                :disabled="!checkIn.plate_match || (needsOverride && (!can.override || checkIn.override_reason.trim().length < 8))"
                                :loading="checkIn.processing"
                                @click="doCheckIn"
                            >
                                ثبت ورود
                            </AppButton>
                        </template>

                        <!-- توزین -->
                        <template v-else-if="selected.next === 'tare' || selected.next === 'gross'">
                            <TextInput
                                v-model="weight.weight_kg"
                                inputmode="numeric"
                                dir="ltr"
                                placeholder="14500"
                                :invalid="!!weight.errors.weight_kg"
                            />
                            <p v-if="weight.errors.weight_kg" class="text-xs text-rose-600">
                                {{ weight.errors.weight_kg }}
                            </p>

                            <AppButton
                                size="lg"
                                :disabled="Number(weight.weight_kg) <= 0"
                                :loading="weight.processing"
                                @click="doWeigh(selected.next === 'tare' ? 'tare' : 'gross')"
                            >
                                ثبت {{ selected.next === 'tare' ? 'وزن خالی' : 'وزن پر' }}
                            </AppButton>
                        </template>

                        <!-- شروع بارگیری -->
                        <template v-else-if="selected.next === 'start-loading'">
                            <div v-if="loadingPoints.length" class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="point in loadingPoints"
                                    :key="point.id"
                                    type="button"
                                    class="rounded-xl border px-3 py-2 text-sm font-medium transition"
                                    :class="
                                        move.loading_point_id === point.id
                                            ? 'border-brand-500 bg-brand-50 text-brand-800'
                                            : 'border-slate-300 text-slate-600 hover:bg-slate-50'
                                    "
                                    @click="move.loading_point_id = point.id"
                                >
                                    {{ point.name }}
                                </button>
                            </div>

                            <AppButton size="lg" :loading="move.processing" @click="doMove('LOADING')">
                                شروع بارگیری
                            </AppButton>
                        </template>

                        <AppButton
                            v-else-if="selected.next === 'finish-loading'"
                            size="lg"
                            :loading="move.processing"
                            @click="doMove('LOADED')"
                        >
                            پایان بارگیری
                        </AppButton>

                        <AppButton v-else-if="selected.next === 'exit'" size="lg" :loading="busy" @click="doExit">
                            ثبت خروج
                        </AppButton>

                        <!-- تعیین تکلیف اضافه‌بار -->
                        <template v-else-if="selected.next === 'resolve'">
                            <AlertBox tone="error">
                                {{ selected.weighing?.discrepancy_label }} — برگه خروج تا تعیین تکلیف صادر نمی‌شود.
                            </AlertBox>

                            <TextInput
                                v-model="verdict.reason"
                                placeholder="دلیل تصمیم — در سابقه به نام شما می‌ماند"
                                :invalid="!!verdict.errors.reason"
                            />

                            <div class="flex flex-wrap gap-2">
                                <AppButton
                                    :disabled="verdict.reason.trim().length < 8"
                                    :loading="verdict.processing"
                                    @click="doVerdict('approved')"
                                >
                                    تأیید و صدور برگه
                                </AppButton>
                                <AppButton
                                    variant="secondary"
                                    :disabled="verdict.reason.trim().length < 8"
                                    :loading="verdict.processing"
                                    @click="doVerdict('rejected')"
                                >
                                    رد
                                </AppButton>
                            </div>
                        </template>
                    </div>

                    <AlertBox v-else-if="selected.blocked" tone="warning">{{ selected.blocked }}</AlertBox>

                    <p v-else-if="selected.next" class="border-t border-slate-100 pt-4 text-sm text-slate-500">
                        کارِ بعدی: <span class="font-medium text-slate-700">{{ selected.next_label }}</span> —
                        شما دسترسی این مرحله را ندارید.
                    </p>

                    <p v-else class="border-t border-slate-100 pt-4 text-sm text-slate-500">
                        کارِ باز‌ی روی این حواله نیست.
                    </p>
                </section>

                <section v-else class="card hidden items-center justify-center p-10 text-center lg:flex">
                    <p class="text-sm text-slate-500">
                        یک کامیون از صف انتخاب کنید — کارِ بعدی‌اش همین‌جا باز می‌شود.
                    </p>
                </section>
            </div>
        </div>
    </StaffLayout>
</template>
