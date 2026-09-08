<script setup lang="ts">
import BarChart from '@/components/BarChart.vue';
import PlateBadge from '@/components/PlateBadge.vue';
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

const busyLines = computed(() => props.lines.filter((line) => line.busy).length);

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
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-lg font-bold text-slate-900">وضعیت بارگیری امروز</h1>
                    <p class="mt-0.5 text-sm text-slate-500">{{ jalaliDate }}</p>
                </div>

                <div class="flex items-center gap-2">
                    <!-- سه ایستگاهی که مدیر بیشتر از همه سراغشان می‌رود -->
                    <div class="hidden items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 sm:flex">
                        <Link
                            v-for="shortcut in shortcuts"
                            :key="shortcut.href"
                            :href="shortcut.href"
                            class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900"
                        >
                            {{ shortcut.label }}
                        </Link>
                    </div>

                    <span
                        v-if="canSeeQueue"
                        :title="connected ? 'به‌روزرسانی زنده برقرار است' : 'اتصال زنده برقرار نیست؛ هر ۳۰ ثانیه تازه می‌شود'"
                        :class="[
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                            connected ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800',
                        ]"
                    >
                        <span
                            :class="['size-2 rounded-full', connected ? 'animate-pulse bg-emerald-500' : 'bg-amber-500']"
                            aria-hidden="true"
                        />
                        {{ connected ? 'زنده' : 'هر ۳۰ ثانیه' }}
                    </span>
                </div>
            </div>

            <!--
                کارتِ سرصفحه: سه عددی که مدیر صبح می‌خواهد بداند.

                کل روز، چقدرش تمام شده، و همین حالا چند کامیون داخل محوطه است.
                نوار پیشرفت همان نسبت را بدون خواندن عدد نشان می‌دهد.
            -->
            <section class="card overflow-hidden bg-gradient-to-bl from-brand-600 via-brand-600 to-brand-700 text-white">
                <div class="grid gap-px sm:grid-cols-3">
                    <div class="px-6 py-6 text-center">
                        <p class="num text-5xl font-bold tracking-tight">{{ counters.total }}</p>
                        <p class="mt-1.5 text-sm text-brand-100">کامیون امروز</p>
                    </div>
                    <div class="border-t border-white/15 px-6 py-6 text-center sm:border-s sm:border-t-0">
                        <p class="num text-5xl font-bold tracking-tight">{{ done }}</p>
                        <p class="mt-1.5 text-sm text-brand-100">تکمیل‌شده</p>
                    </div>
                    <div class="border-t border-white/15 px-6 py-6 text-center sm:border-s sm:border-t-0">
                        <p class="num text-5xl font-bold tracking-tight">{{ onSite }}</p>
                        <p class="mt-1.5 text-sm text-brand-100">همین حالا در محوطه</p>
                    </div>
                </div>

                <div class="px-6 pb-5">
                    <div class="h-1.5 overflow-hidden rounded-full bg-white/20">
                        <div class="h-full rounded-full bg-white transition-all duration-500" :style="{ width: `${progress}%` }" />
                    </div>
                    <p class="mt-2 text-center text-xs text-brand-100">
                        <span class="num">٪{{ progress }}</span>
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
                    <h2 class="text-sm font-semibold text-rose-900">رسیدگی لازم است</h2>
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
                    <h2 class="text-sm font-semibold text-slate-700">خطوط بارگیری</h2>
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
                            'rounded-2xl border p-4 transition',
                            !line.busy
                                ? 'border-dashed border-slate-200 bg-slate-50/60'
                                : line.is_late
                                  ? 'border-rose-200 bg-white shadow-sm ring-1 ring-rose-100'
                                  : 'border-slate-200 bg-white shadow-sm',
                        ]"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-slate-800">{{ line.name }}</span>
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

                        <template v-if="line.busy">
                            <div class="mt-3 flex items-center gap-2">
                                <span class="num rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">
                                    #{{ line.number }}
                                </span>
                                <PlateBadge v-if="line.plate" :plate="line.plate" size="sm" />
                            </div>

                            <p class="mt-2 truncate text-sm text-slate-600">
                                {{ line.driver ?? '—' }}
                                <span v-if="line.truck_type" class="text-slate-400">· {{ line.truck_type }}</span>
                            </p>
                            <p v-if="line.product" class="truncate text-xs text-slate-400">{{ line.product }}</p>

                            <div class="mt-3">
                                <div class="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        :class="[
                                            'h-full rounded-full transition-all duration-700',
                                            line.is_late ? 'bg-rose-500' : 'bg-violet-500',
                                        ]"
                                        :style="{ width: `${line.percent}%` }"
                                    />
                                </div>
                                <p class="mt-1.5 flex items-center justify-between text-[11px] text-slate-500">
                                    <span>گذشته: <span class="num font-medium">{{ line.elapsed_minutes }}</span> دقیقه</span>
                                    <span class="text-slate-400">از <span class="num">{{ line.expected_minutes }}</span></span>
                                </p>
                            </div>
                        </template>

                        <p v-else class="mt-3 text-sm text-slate-400">کامیونی روی این خط نیست.</p>
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
                ریزِ وضعیت‌ها.

                دو گروه جدا و نه شش کارتِ یک‌شکل: چپ، جریانِ امروز؛ راست،
                آنچه به هم خورده. شش کادرِ هم‌وزن، چشم را جایی نمی‌برد.
            -->
            <div class="grid gap-3 lg:grid-cols-3">
                <div class="card p-4 lg:col-span-2">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">جریان امروز</h2>

                    <div class="grid grid-cols-3 divide-x divide-x-reverse divide-slate-100">
                        <div class="px-2 text-center">
                            <p class="num text-2xl font-bold text-amber-600">{{ counters.waiting }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">در انتظار</p>
                        </div>
                        <div class="px-2 text-center">
                            <p class="num text-2xl font-bold text-violet-600">{{ counters.loading }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">در حال بارگیری</p>
                        </div>
                        <div class="px-2 text-center">
                            <!-- «۰ دقیقه» یعنی هنوز بارگیریِ تمام‌شده‌ای نداریم، نه اینکه صفر طول کشیده -->
                            <p class="text-2xl font-bold text-slate-900">
                                {{ avgLoadingMinutes > 0 ? duration(avgLoadingMinutes) : '—' }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">میانگین بارگیری</p>
                        </div>
                    </div>
                </div>

                <div class="card p-4">
                    <h2 class="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-400">به نتیجه نرسید</h2>

                    <div class="grid grid-cols-3 divide-x divide-x-reverse divide-slate-100">
                        <div class="px-2 text-center">
                            <p class="num text-2xl font-bold text-rose-600">{{ counters.cancelled }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">لغو</p>
                        </div>
                        <div class="px-2 text-center">
                            <p class="num text-2xl font-bold text-rose-600">{{ counters.no_show }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">عدم حضور</p>
                        </div>
                        <div class="px-2 text-center">
                            <p class="num text-2xl font-bold text-rose-500">٪{{ week.summary.no_show_rate }}</p>
                            <p class="mt-0.5 text-xs text-slate-500">نرخ هفته</p>
                        </div>
                    </div>
                </div>
            </div>

            <!--
                صف امروز، همین‌جا.

                فقط خواندنی است: عمل کردن روی نوبت کارِ پنل اپراتور است و
                دکمه‌هایش نباید در داشبورد تکرار شوند.
            -->
            <section v-if="canSeeQueue" class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-slate-700">صف امروز</h2>
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
                            <tr v-for="row in liveRows" :key="row.ulid" class="transition hover:bg-slate-50/60">
                                <td class="num px-4 py-2.5 font-semibold text-slate-800">{{ row.number }}</td>
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
                    <h2 class="mb-4 text-sm font-semibold text-slate-700">ورود کامیون در ۷ روز اخیر</h2>
                    <BarChart :data="weekChart" />
                </section>

                <section class="card space-y-4 p-5">
                    <h2 class="text-sm font-semibold text-slate-700">هفته‌ی گذشته</h2>

                    <dl class="space-y-3">
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

            <section v-if="week.byProduct.length" class="card overflow-hidden">
                <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                    محصولات هفته
                </h2>
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500">
                        <tr>
                            <th class="px-5 py-2 text-start font-medium">محصول</th>
                            <th class="px-5 py-2 text-start font-medium">نوبت</th>
                            <th class="px-5 py-2 text-start font-medium">تکمیل‌شده</th>
                            <th class="px-5 py-2 text-start font-medium">تناژ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="row in week.byProduct" :key="row.name">
                            <td class="px-5 py-2.5 text-slate-800">{{ row.name }}</td>
                            <td class="num px-5 py-2.5 text-slate-700">{{ row.total }}</td>
                            <td class="num px-5 py-2.5 text-slate-700">{{ row.completed }}</td>
                            <td class="num px-5 py-2.5 text-slate-700">{{ row.tons }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </StaffLayout>
</template>
