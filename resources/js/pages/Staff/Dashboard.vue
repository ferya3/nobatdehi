<script setup lang="ts">
import AreaChart from '@/components/AreaChart.vue';
import Icon from '@/components/Icon.vue';
import ProgressRing from '@/components/ProgressRing.vue';
import StatTile from '@/components/StatTile.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import ShareBar from '@/components/ShareBar.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';
import { useLiveChannel } from '@/lib/useLiveChannel';
import type { PageProps, PlateParts, StatusTone } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';

type QueueRow = {
    ulid: string;
    number: number;
    time: string;
    status: string;
    status_label: string;
    status_tone: StatusTone;
    is_active: boolean;
    plate: PlateParts | null;
    truck_type: string | null;
    driver: string | null;
    product: string | null;
    loading_point: string | null;
    wait_minutes: number | null;
};

const props = defineProps<{
    factoryId: number;
    jalaliDate: string;
    counters: Record<string, number>;
    canSeeQueue: boolean;
    queue: QueueRow[];
    lines: {
        id: number;
        name: string;
        busy: boolean;
        number?: number;
        plate?: PlateParts | null;
        driver?: string | null;
        truck_type?: string | null;
        product?: string | null;
        elapsed_minutes?: number;
        expected_minutes?: number;
        percent?: number;
        is_late?: boolean;
    }[];
    alerts: { ulid: string; kind: string; number: number; plate: PlateParts | null; text: string }[];
    upcomingDays: { date: string; jalali: string; total: number; is_tomorrow: boolean }[];
    avgLoadingMinutes: number;
    week: {
        summary: {
            total: number;
            completed: number;
            no_show_rate: number;
            avg_wait_minutes: number | null;
            avg_loading_minutes: number | null;
        };
        daily: { jalali: string; total: number; completed: number; no_show: number }[];
        byProduct: { name: string; total: number; completed: number; tons: number }[];
    };
}>();

const page = usePage<PageProps>();

const weekChart = computed(() =>
    props.week.daily.map((row) => ({ label: row.jalali.slice(5), value: row.total })),
);

/** رنگ لبه‌ی ردیف صف — همان رنگ‌های وضعیت که در بقیه‌ی پنل هم هست */
const ROW_TONE: Record<string, string> = {
    booked: 'bg-slate-400',
    waiting: 'bg-amber-500',
    called: 'bg-blue-500',
    checkedin: 'bg-indigo-500',
    loading: 'bg-violet-500',
    loaded: 'bg-emerald-500',
    completed: 'bg-emerald-600',
    failed: 'bg-rose-500',
};

const busyLines = computed(() => props.lines.filter((line) => line.busy).length);

const productShare = computed(() => props.week.byProduct.map((row) => ({ name: row.name, value: row.tons })));

/**
 * روند و تغییر، از همان داده‌ی هفته که از قبل می‌آمد.
 *
 * هیچ عددی ساختگی نیست: اسپارک‌لاین همان هفت روزِ نمودار پایین است و
 * «تغییر» تفاضلِ امروز و دیروزِ همان فهرست.
 */
const trend = computed(() => ({
    total: props.week.daily.map((d) => d.total),
    completed: props.week.daily.map((d) => d.completed),
    noShow: props.week.daily.map((d) => d.no_show),
}));

function deltaOf(series: number[]): number | null {
    if (series.length < 2) return null;

    return series[series.length - 1] - series[series.length - 2];
}

/**
 * کامیونی که روی لاین است ولی لاینش ثبت نشده.
 *
 * بدون این، شمارنده‌ی «۱ در حال بارگیری» و کارت‌های «۰ از ۳ مشغول» همدیگر
 * را نقض می‌کنند و کسی نمی‌فهمد کدامشان دروغ می‌گوید.
 */
/**
 * ستون‌بندیِ کارت‌های لاین.
 *
 * کارخانه‌ی تک‌لاینه با شبکه‌ی سه‌ستونه یعنی یک کارت و دو سوم فضای خالی.
 */
const lineGrid = computed(() => {
    if (props.lines.length === 1) return 'sm:grid-cols-1';
    if (props.lines.length === 2) return 'sm:grid-cols-2';

    return 'sm:grid-cols-2 lg:grid-cols-3';
});

const loadingWithoutLine = computed(
    () => props.queue.filter((row) => row.status === 'LOADING' && !row.loading_point).length,
);

/**
 * میان‌برهای ایستگاه‌ها.
 *
 * route().has() لازم است چون کاربری که دسترسی ندارد، مسیر برایش ثبت نشده و
 * route() خطا می‌دهد — یک دکمه‌ی بی‌فایده هم بهتر است اصلاً نباشد.
 */
const SHORTCUTS = [
    { label: 'نگهبانی', name: 'staff.gate.index', permission: 'queue.checkin' },
    { label: 'باسکول', name: 'staff.weighbridge.index', permission: 'weighing.record' },
    { label: 'بارگیری', name: 'staff.loading.index', permission: 'queue.start-loading' },
];

const permissions = computed(() => page.props.auth.user?.permissions ?? []);

const shortcuts = computed(() =>
    SHORTCUTS.filter((item) => permissions.value.includes(item.permission) && route().has(item.name)).map((item) => ({
        label: item.label,
        href: route(item.name),
    })),
);

const totalTons = computed(() => props.week.byProduct.reduce((sum, row) => sum + row.tons, 0));

/**
 * پیشرفت روز.
 *
 * عددِ «۱۲ کامیون» به‌تنهایی چیزی نمی‌گوید؛ سهمِ تکمیل‌شده از کل است که
 * می‌گوید روز جلو رفته یا گیر کرده.
 */
const done = computed(() => props.counters.completed ?? 0);
const progress = computed(() => {
    const total = props.counters.total ?? 0;

    return total > 0 ? Math.round((done.value / total) * 100) : 0;
});

const onSite = computed(() => props.counters.on_site ?? 0);
const elsewhereTotal = computed(() => props.upcomingDays.reduce((sum, day) => sum + day.total, 0));

// صفِ روی داشبورد کوتاه است: کارِ در جریان، نه بایگانی روز
const liveRows = computed(() => props.queue.filter((row) => row.is_active).slice(0, 12));
const hiddenActive = computed(() => props.queue.filter((row) => row.is_active).length - liveRows.value.length);

/** همان صفِ زنده‌ی پنل اپراتور؛ داشبورد هم نباید کهنه بماند */
const { connected } = useLiveChannel(props.canSeeQueue ? `factory.${props.factoryId}.queue` : null, {
    'queue.changed': () => refresh(),
});

let poller: ReturnType<typeof setInterval> | null = null;

function refresh() {
    if (document.hidden) return;

    router.reload({ only: ['counters', 'queue', 'lines', 'alerts', 'upcomingDays', 'avgLoadingMinutes'] });
}

function onVisible() {
    if (!document.hidden) refresh();
}

onMounted(() => {
    if (!props.canSeeQueue) return;

    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);

    poller = setInterval(refresh, 30_000);
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', onVisible);
    window.removeEventListener('focus', onVisible);

    if (poller) clearInterval(poller);
});

watch(connected, (isLive) => {
    if (poller) clearInterval(poller);

    poller = setInterval(refresh, isLive ? 120_000 : 30_000);
});
</script>

<template>
    <StaffLayout title="داشبورد">
        <div class="space-y-6">
            <!--
                سربرگِ تیره.
                عنوان و سه عددِ روز و نوار پیشرفت، همه در یک بلوک — تا نگاهِ
                اول یک‌جا جواب بگیرد و بعد چشم به جزئیات برود.
            -->
            <section class="overflow-hidden rounded-2xl bg-gradient-to-bl from-brand-800 via-brand-700 to-brand-600 shadow-lg shadow-brand-900/10">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-5 py-3.5">
                    <div>
                        <h1 class="flex items-center gap-2 text-base font-bold text-white">
                            <span class="flex size-8 items-center justify-center rounded-xl bg-white/15">
                                <Icon name="activity" class="size-4" />
                            </span>
                            وضعیت بارگیری امروز
                        </h1>
                        <p class="mt-0.5 text-xs text-brand-100/80">{{ jalaliDate }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <div class="hidden items-center gap-1 rounded-xl bg-white/10 p-1 sm:flex">
                            <Link
                                v-for="shortcut in shortcuts"
                                :key="shortcut.href"
                                :href="shortcut.href"
                                class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-50 transition hover:bg-white/15"
                            >
                                {{ shortcut.label }}
                            </Link>
                        </div>

                        <span
                            v-if="canSeeQueue"
                            :title="connected ? 'به‌روزرسانی زنده برقرار است' : 'اتصال زنده برقرار نیست؛ هر ۳۰ ثانیه تازه می‌شود'"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                                connected ? 'bg-emerald-400/20 text-emerald-100' : 'bg-amber-400/20 text-amber-100',
                            ]"
                        >
                            <span
                                :class="['size-2 rounded-full', connected ? 'animate-pulse bg-emerald-300' : 'bg-amber-300']"
                                aria-hidden="true"
                            />
                            {{ connected ? 'زنده' : 'هر ۳۰ ثانیه' }}
                        </span>
                    </div>
                </div>

                <div class="grid gap-px sm:grid-cols-3">
                    <div class="px-6 py-6 text-center">
                        <p class="num text-5xl font-bold tracking-tight text-white">{{ counters.total }}</p>
                        <p class="mt-1.5 text-sm text-brand-100/90">کامیون امروز</p>
                    </div>
                    <div class="border-t border-white/10 px-6 py-6 text-center sm:border-s sm:border-t-0">
                        <p class="num text-5xl font-bold tracking-tight text-white">{{ done }}</p>
                        <p class="mt-1.5 text-sm text-brand-100/90">تکمیل‌شده</p>
                    </div>
                    <div class="border-t border-white/10 px-6 py-6 text-center sm:border-s sm:border-t-0">
                        <p class="num text-5xl font-bold tracking-tight text-white">{{ onSite }}</p>
                        <p class="mt-1.5 text-sm text-brand-100/90">همین حالا در محوطه</p>
                    </div>
                </div>

                <!-- سنجه‌ی نسبت به سقفِ روز؛ عدد کنارش می‌آید چون نوار به‌تنهایی خوانده نمی‌شود -->
                <div class="px-6 pb-5">
                    <div class="h-2 overflow-hidden rounded-full bg-white/15">
                        <div
                            class="h-full rounded-full bg-emerald-300 transition-all duration-700"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                    <p class="mt-2 text-center text-xs text-brand-100/80">
                        <span class="num font-semibold text-white">٪{{ progress }}</span>
                        از نوبت‌های امروز تکمیل شده
                    </p>
                </div>
            </section>

            <!--
                هشدارها.

                داشبوردی که فقط عدد نشان دهد، خبر بد را قایم می‌کند. اینجا
                بالای همه‌چیز است چون تنها بخشی است که «همین حالا کاری بکن»
                می‌گوید.
            -->
            <section v-if="alerts.length" class="overflow-hidden rounded-2xl border border-rose-200 bg-rose-50">
                <div class="flex items-center gap-2 border-b border-rose-200/70 px-4 py-2.5">
                    <span class="flex size-5 items-center justify-center rounded-full bg-rose-500 text-[11px] font-bold text-white">
                        {{ alerts.length }}
                    </span>
                    <h2 class="flex items-center gap-1.5 text-sm font-semibold text-rose-900"><Icon name="alert" class="size-4" />رسیدگی لازم است</h2>
                </div>

                <ul class="divide-y divide-rose-200/60">
                    <li v-for="alert in alerts" :key="alert.ulid" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5">
                        <span class="num rounded-lg bg-white px-2 py-0.5 text-xs font-bold text-rose-700">
                            #{{ alert.number }}
                        </span>
                        <PlateBadge v-if="alert.plate" :plate="alert.plate" size="sm" />
                        <span class="text-sm text-rose-900">{{ alert.text }}</span>
                        <Link
                            :href="route('staff.queue.index')"
                            class="ms-auto rounded-lg border border-rose-300 bg-white px-2.5 py-1 text-xs font-medium text-rose-700 transition hover:bg-rose-100"
                        >
                            رسیدگی
                        </Link>
                    </li>
                </ul>
            </section>

            <!--
                خطوط بارگیری، همین حالا.

                مهم‌ترین چیزی که مدیر با یک نگاه می‌خواهد بداند: کدام خط
                مشغول است، با چه کامیونی، و چقدر از کارش گذشته.
            -->
            <section v-if="canSeeQueue && lines.length">
                <div class="mb-2.5 flex items-center justify-between">
                    <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Icon name="layers" class="size-4 text-brand-500" />خطوط بارگیری</h2>
                    <span class="text-xs text-slate-500">
                        <span class="num font-medium text-slate-700">{{ busyLines }}</span>
                        از
                        <span class="num">{{ lines.length }}</span>
                        مشغول
                    </span>
                </div>

                <p
                    v-if="loadingWithoutLine > 0"
                    class="mb-2.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-900"
                >
                    <span class="num font-medium">{{ loadingWithoutLine }}</span>
                    کامیون در حال بارگیری است ولی لاینش ثبت نشده، پس در کارت‌های زیر دیده نمی‌شود.
                </p>

                <div :class="['grid gap-3', lineGrid]">
                    <div
                        v-for="line in lines"
                        :key="line.id"
                        :class="[
                            'relative flex min-h-[9.5rem] flex-col overflow-hidden rounded-2xl border p-4 transition',
                            !line.busy
                                ? 'border-dashed border-slate-200 bg-white/50'
                                : line.is_late
                                  ? 'border-rose-200 bg-white shadow-md shadow-rose-100/60'
                                  : 'border-slate-200/70 bg-white shadow-sm hover:shadow-md',
                        ]"
                    >
                        <span
                            v-if="line.busy"
                            :class="['absolute inset-y-0 start-0 w-1', line.is_late ? 'bg-rose-500' : 'bg-violet-500']"
                            aria-hidden="true"
                        />

                        <div class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                                <Icon name="layers" class="size-4 text-slate-400" />
                                {{ line.name }}
                            </span>
                            <span
                                :class="[
                                    'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    !line.busy
                                        ? 'bg-slate-100 text-slate-500'
                                        : line.is_late
                                          ? 'bg-rose-100 text-rose-700'
                                          : 'bg-violet-100 text-violet-700',
                                ]"
                            >
                                <span
                                    :class="[
                                        'size-1.5 rounded-full',
                                        !line.busy ? 'bg-slate-400' : line.is_late ? 'bg-rose-500' : 'animate-pulse bg-violet-500',
                                    ]"
                                />
                                {{ !line.busy ? 'آزاد' : line.is_late ? 'طولانی شده' : 'در حال بارگیری' }}
                            </span>
                        </div>

                        <div v-if="line.busy" class="mt-3 flex flex-1 items-center gap-4">
                            <ProgressRing :percent="line.percent ?? 0" :late="line.is_late" />

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="num rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">
                                        #{{ line.number }}
                                    </span>
                                    <PlateBadge v-if="line.plate" :plate="line.plate" size="sm" />
                                </div>

                                <p class="mt-1.5 truncate text-sm text-slate-700">{{ line.driver ?? '—' }}</p>
                                <p class="truncate text-xs text-slate-400">
                                    {{ line.truck_type }}<span v-if="line.product"> · {{ line.product }}</span>
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    گذشته
                                    <span class="num font-medium text-slate-700">{{ line.elapsed_minutes }}</span>
                                    دقیقه از
                                    <span class="num">{{ line.expected_minutes }}</span>
                                </p>
                            </div>
                        </div>

                        <div v-else class="flex flex-1 flex-col items-center justify-center gap-2 text-sm text-slate-400">
                            <Icon name="truck" class="size-9 text-slate-200" />
                            کامیونی روی این خط نیست.
                        </div>
                    </div>
                </div>
            </section>

            <!-- نوبت‌هایی که روی روزهای دیگر نشسته‌اند -->
            <div
                v-if="upcomingDays.length"
                class="flex flex-wrap items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-900"
            >
                <span class="font-medium">{{ elsewhereTotal }} نوبت روی روزهای دیگر ثبت شده:</span>
                <Link
                    v-for="day in upcomingDays"
                    :key="day.date"
                    :href="route('staff.queue.index', { date: day.date })"
                    class="rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-900 transition hover:bg-amber-100"
                >
                    {{ day.is_tomorrow ? 'فردا' : day.jalali }} — {{ day.total }} نوبت
                </Link>
            </div>

            <!--
                کاشی‌های آمار.

                هر کاشی آیکون دارد، رنگِ لبه دارد، و اگر داده‌اش اجازه بدهد
                تغییرِ نسبت به دیروز و روندِ هفته را هم نشان می‌دهد. عدد با
                رنگِ متن می‌آید و نه با رنگِ وضعیت، تا همیشه خوانا بماند.
            -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                <StatTile
                    label="در انتظار"
                    :value="counters.waiting"
                    icon="clock"
                    tone="amber"
                />
                <StatTile
                    label="در حال بارگیری"
                    :value="counters.loading"
                    icon="truck"
                    tone="violet"
                />
                <StatTile
                    label="تکمیل‌شده"
                    :value="counters.completed"
                    icon="check"
                    tone="emerald"
                    :delta="deltaOf(trend.completed)"
                    :trend="trend.completed"
                />
                <StatTile
                    label="لغو شده"
                    :value="counters.cancelled"
                    icon="ban"
                    tone="rose"
                />
                <StatTile
                    label="عدم حضور"
                    :value="counters.no_show"
                    icon="alert"
                    tone="rose"
                    :delta="deltaOf(trend.noShow)"
                    :higher-is-better="false"
                    :trend="trend.noShow"
                />
                <StatTile
                    label="میانگین بارگیری"
                    :value="avgLoadingMinutes > 0 ? duration(avgLoadingMinutes) : '—'"
                    icon="gauge"
                    tone="brand"
                />
            </div>

            <!--
                صف امروز، همین‌جا.

                فقط خواندنی است: عمل کردن روی نوبت کارِ پنل اپراتور است و
                دکمه‌هایش نباید در داشبورد تکرار شوند.
            -->
            <section v-if="canSeeQueue" class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                    <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Icon name="truck" class="size-4 text-brand-500" />صف امروز</h2>
                    <Link
                        :href="route('staff.queue.index')"
                        class="rounded-lg border border-slate-200 px-3 py-1 text-xs font-medium text-slate-600 transition hover:bg-slate-50"
                    >
                        مدیریت صف
                    </Link>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-2 text-start font-medium">نوبت</th>
                                <th class="px-4 py-2 text-start font-medium">ساعت</th>
                                <th class="px-4 py-2 text-start font-medium">پلاک</th>
                                <th class="px-4 py-2 text-start font-medium">راننده</th>
                                <th class="px-4 py-2 text-start font-medium">محصول</th>
                                <th class="px-4 py-2 text-start font-medium">وضعیت</th>
                                <th class="px-4 py-2 text-start font-medium">انتظار</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in liveRows" :key="row.ulid" class="group transition hover:bg-slate-50/70">
                                <td class="relative px-4 py-2.5">
                                    <!-- لبه‌ی رنگیِ وضعیت: ردیف‌ها بدون آن یک تخته‌ی یکنواخت‌اند -->
                                    <span
                                        :class="['absolute inset-y-1 start-0 w-1 rounded-full', ROW_TONE[row.status_tone] ?? 'bg-slate-300']"
                                        aria-hidden="true"
                                    />
                                    <span class="num font-semibold text-slate-800">{{ row.number }}</span>
                                </td>
                                <td class="num px-4 py-2.5 text-slate-700">{{ row.time }}</td>
                                <td class="px-4 py-2.5">
                                    <PlateBadge v-if="row.plate" :plate="row.plate" size="sm" />
                                    <span v-else class="text-slate-400">—</span>
                                    <p v-if="row.truck_type" class="mt-0.5 text-xs text-slate-400">{{ row.truck_type }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-slate-700">{{ row.driver ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-slate-600">
                                    {{ row.product ?? '—' }}
                                    <p v-if="row.loading_point" class="mt-0.5 text-xs text-slate-400">{{ row.loading_point }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <StatusBadge :label="row.status_label" :tone="row.status_tone" />
                                </td>
                                <td class="num px-4 py-2.5 text-slate-500">
                                    {{ row.wait_minutes !== null ? duration(row.wait_minutes) : '—' }}
                                </td>
                            </tr>

                            <tr v-if="!liveRows.length">
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">
                                    <p>هیچ نوبتی در جریان نیست.</p>
                                    <p v-if="upcomingDays.length" class="mt-1.5 text-slate-600">
                                        {{ elsewhereTotal }} نوبت برای روزهای دیگر ثبت شده.
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="hiddenActive > 0" class="border-t border-slate-100 px-5 py-2.5 text-center text-xs text-slate-500">
                    <span class="num">{{ hiddenActive }}</span>
                    نوبت دیگر در جریان است —
                    <Link :href="route('staff.queue.index')" class="font-medium text-brand-700 hover:underline">
                        دیدن همه
                    </Link>
                </p>
            </section>

            <div class="grid gap-5 lg:grid-cols-3">
                <section class="card p-5 lg:col-span-2">
                    <h2 class="mb-4 flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Icon name="activity" class="size-4 text-brand-500" />ورود کامیون در ۷ روز اخیر</h2>
                    <AreaChart :data="weekChart" unit="کامیون" />
                </section>

                <section class="card space-y-4 p-5">
                    <h2 class="flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Icon name="chart" class="size-4 text-brand-500" />هفته‌ی گذشته</h2>

                    <dl class="space-y-2.5">
                        <div class="flex items-center justify-between">
                            <dt class="text-sm text-slate-500">کل کامیون</dt>
                            <dd class="num text-lg font-bold text-slate-900">{{ week.summary.total }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <dt class="text-sm text-slate-500">تکمیل‌شده</dt>
                            <dd class="num text-lg font-bold text-emerald-600">{{ week.summary.completed }}</dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <dt class="text-sm text-slate-500">متوسط انتظار</dt>
                            <dd class="text-sm font-semibold text-slate-800">
                                {{ week.summary.avg_wait_minutes ? duration(week.summary.avg_wait_minutes) : '—' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <dt class="text-sm text-slate-500">متوسط بارگیری</dt>
                            <dd class="text-sm font-semibold text-slate-800">
                                {{ week.summary.avg_loading_minutes ? duration(week.summary.avg_loading_minutes) : '—' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <dt class="text-sm text-slate-500">تناژ بارگیری‌شده</dt>
                            <dd class="text-sm font-semibold text-slate-800">
                                <span class="num">{{ totalTons }}</span> تن
                            </dd>
                        </div>
                    </dl>
                </section>
            </div>

            <div v-if="week.byProduct.length" class="grid gap-5 lg:grid-cols-3">
                <section class="card p-5">
                    <h2 class="mb-4 flex items-center gap-1.5 text-sm font-semibold text-slate-700"><Icon name="box" class="size-4 text-brand-500" />سهم محصولات از تناژ هفته</h2>
                    <ShareBar :data="productShare" unit="تن" />
                </section>

                <section class="card overflow-hidden lg:col-span-2">
                    <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                        محصولات هفته
                    </h2>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[28rem] text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500">
                                <tr>
                                    <th class="px-5 py-2 text-start font-medium">محصول</th>
                                    <th class="px-5 py-2 text-start font-medium">نوبت</th>
                                    <th class="px-5 py-2 text-start font-medium">تکمیل‌شده</th>
                                    <th class="px-5 py-2 text-start font-medium">تناژ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="row in week.byProduct" :key="row.name" class="transition hover:bg-slate-50/60">
                                    <td class="px-5 py-2.5 text-slate-800">{{ row.name }}</td>
                                    <td class="num px-5 py-2.5 text-slate-700">{{ row.total }}</td>
                                    <td class="num px-5 py-2.5 text-slate-700">{{ row.completed }}</td>
                                    <td class="num px-5 py-2.5 text-slate-700">{{ row.tons }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

        </div>
    </StaffLayout>
</template>
