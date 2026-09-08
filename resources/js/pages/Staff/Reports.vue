<script setup lang="ts">
import AreaChart from '@/components/AreaChart.vue';
import BarChart from '@/components/BarChart.vue';
import StatCard from '@/components/StatCard.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    range: string;
    ranges: { key: string; label: string }[];
    from: string;
    to: string;
    summary: {
        total: number;
        completed: number;
        no_show: number;
        cancelled: number;
        no_show_rate: number;
        completion_rate: number;
        avg_wait_minutes: number | null;
        avg_loading_minutes: number | null;
        avg_on_site_minutes: number | null;
    };
    daily: { date: string; jalali: string; total: number; completed: number; no_show: number }[];
    byHour: { hour: string; total: number }[];
    byProduct: { name: string; total: number; completed: number; tons: number }[];
    byOperator: { name: string; actions: number; completed: number; rollbacks: number }[];
}>();

const dailyChart = computed(() =>
    props.daily.map((row) => ({ label: row.jalali.slice(5), value: row.total })),
);

const hourChart = computed(() => props.byHour.map((row) => ({ label: row.hour, value: row.total })));

function pick(range: string) {
    router.get(route('staff.reports'), { range }, { preserveScroll: true });
}
</script>

<template>
    <StaffLayout title="گزارش‌ها">
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-bold text-slate-900">گزارش‌ها</h1>
                    <p class="num mt-0.5 text-sm text-slate-500">{{ from }} تا {{ to }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex gap-1 rounded-xl border border-slate-200 bg-white p-1">
                        <button
                            v-for="item in ranges"
                            :key="item.key"
                            type="button"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm transition',
                                range === item.key
                                    ? 'bg-brand-600 font-medium text-white'
                                    : 'text-slate-600 hover:bg-slate-100',
                            ]"
                            @click="pick(item.key)"
                        >
                            {{ item.label }}
                        </button>
                    </div>

                    <a
                        :href="route('staff.reports.export', { range })"
                        class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                    >
                        خروجی CSV
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="کل نوبت" :value="summary.total" />
                <StatCard label="تکمیل‌شده" :value="summary.completed" tone="completed" />
                <StatCard label="عدم حضور" :value="summary.no_show" tone="failed" />
                <StatCard label="لغو شده" :value="summary.cancelled" tone="failed" />
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">متوسط انتظار در محوطه</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">
                        {{ summary.avg_wait_minutes !== null ? duration(summary.avg_wait_minutes) : '—' }}
                    </p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">متوسط زمان بارگیری</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">
                        {{ summary.avg_loading_minutes !== null ? duration(summary.avg_loading_minutes) : '—' }}
                    </p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">متوسط کل حضور در کارخانه</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">
                        {{ summary.avg_on_site_minutes !== null ? duration(summary.avg_on_site_minutes) : '—' }}
                    </p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">نرخ عدم حضور</p>
                    <p class="num mt-1 text-xl font-bold text-rose-600">٪{{ summary.no_show_rate }}</p>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <!-- روز یک روند است و ساعت یک مقایسه؛ دو کار متفاوت، دو شکل متفاوت -->
                <section class="card p-5">
                    <h2 class="mb-4 text-sm font-semibold text-slate-700">نوبت به تفکیک روز</h2>
                    <AreaChart :data="dailyChart" unit="نوبت" />
                </section>

                <section class="card p-5">
                    <h2 class="mb-4 text-sm font-semibold text-slate-700">شلوغ‌ترین ساعات</h2>
                    <BarChart :data="hourChart" unit="نوبت" />
                </section>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section class="card overflow-hidden">
                    <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                        تفکیک محصول
                    </h2>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">محصول</th>
                                <th class="px-5 py-2 text-start font-medium">نوبت</th>
                                <th class="px-5 py-2 text-start font-medium">تکمیل</th>
                                <th class="px-5 py-2 text-start font-medium">تناژ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in byProduct" :key="row.name">
                                <td class="px-5 py-2.5 text-slate-800">{{ row.name }}</td>
                                <td class="num px-5 py-2.5 text-slate-700">{{ row.total }}</td>
                                <td class="num px-5 py-2.5 text-slate-700">{{ row.completed }}</td>
                                <td class="num px-5 py-2.5 text-slate-700">{{ row.tons }}</td>
                            </tr>
                            <tr v-if="!byProduct.length">
                                <td colspan="4" class="px-5 py-8 text-center text-slate-500">داده‌ای نیست.</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="card overflow-hidden">
                    <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                        عملکرد اپراتورها
                    </h2>
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">کاربر</th>
                                <th class="px-5 py-2 text-start font-medium">عملیات</th>
                                <th class="px-5 py-2 text-start font-medium">تکمیل</th>
                                <th class="px-5 py-2 text-start font-medium">بازگردانی</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in byOperator" :key="row.name">
                                <td class="px-5 py-2.5 text-slate-800">{{ row.name }}</td>
                                <td class="num px-5 py-2.5 text-slate-700">{{ row.actions }}</td>
                                <td class="num px-5 py-2.5 text-slate-700">{{ row.completed }}</td>
                                <td class="num px-5 py-2.5" :class="row.rollbacks > 0 ? 'text-amber-600' : 'text-slate-400'">
                                    {{ row.rollbacks }}
                                </td>
                            </tr>
                            <tr v-if="!byOperator.length">
                                <td colspan="4" class="px-5 py-8 text-center text-slate-500">داده‌ای نیست.</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
    </StaffLayout>
</template>
