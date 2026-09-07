<script setup lang="ts">
import BarChart from '@/components/BarChart.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';
import { useLiveChannel } from '@/lib/useLiveChannel';
import type { PlateParts, StatusTone } from '@/types';
import { Link, router } from '@inertiajs/vue3';
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

const weekChart = computed(() =>
    props.week.daily.map((row) => ({ label: row.jalali.slice(5), value: row.total })),
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

    router.reload({ only: ['counters', 'queue', 'upcomingDays', 'avgLoadingMinutes'] });
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

                <span
                    v-if="canSeeQueue"
                    :title="connected ? 'به‌روزرسانی زنده برقرار است' : 'اتصال زنده برقرار نیست؛ هر ۳۰ ثانیه تازه می‌شود'"
                    :class="[
                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                        connected ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800',
                    ]"
                >
                    <span :class="['size-2 rounded-full', connected ? 'bg-emerald-500' : 'bg-amber-500']" aria-hidden="true" />
                    {{ connected ? 'زنده' : 'هر ۳۰ ثانیه' }}
                </span>
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

            <!-- ریزِ وضعیت‌ها: چهار عددی که کارت بالا در خود جمع کرده -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">در انتظار</p>
                    <p class="num mt-1 text-2xl font-bold text-amber-600">{{ counters.waiting }}</p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">در حال بارگیری</p>
                    <p class="num mt-1 text-2xl font-bold text-violet-600">{{ counters.loading }}</p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">لغو شده</p>
                    <p class="num mt-1 text-2xl font-bold text-rose-600">{{ counters.cancelled }}</p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">عدم حضور</p>
                    <p class="num mt-1 text-2xl font-bold text-rose-600">{{ counters.no_show }}</p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">میانگین بارگیری</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ duration(avgLoadingMinutes) }}</p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">نرخ عدم حضور (هفته)</p>
                    <p class="num mt-1 text-xl font-bold text-rose-600">٪{{ week.summary.no_show_rate }}</p>
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
                                {{ week.summary.avg_wait_minutes !== null ? duration(week.summary.avg_wait_minutes) : '—' }}
                            </dd>
                        </div>
                        <div class="flex items-center justify-between border-t border-slate-100 pt-3">
                            <dt class="text-sm text-slate-500">متوسط بارگیری</dt>
                            <dd class="text-sm font-semibold text-slate-800">
                                {{ week.summary.avg_loading_minutes !== null ? duration(week.summary.avg_loading_minutes) : '—' }}
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
