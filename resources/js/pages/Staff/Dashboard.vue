<script setup lang="ts">
import BarChart from '@/components/BarChart.vue';
import StatCard from '@/components/StatCard.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';
import { computed } from 'vue';

const props = defineProps<{
    jalaliDate: string;
    counters: Record<string, number>;
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
</script>

<template>
    <StaffLayout title="داشبورد">
        <div class="space-y-6">
            <div>
                <h1 class="text-lg font-bold text-slate-900">وضعیت بارگیری امروز</h1>
                <p class="mt-0.5 text-sm text-slate-500">{{ jalaliDate }}</p>
            </div>

            <section class="card bg-gradient-to-b from-brand-600 to-brand-700 p-8 text-center text-white">
                <p class="num text-6xl font-bold tracking-tight">{{ counters.total }}</p>
                <p class="mt-2 text-sm text-brand-100">کامیون امروز</p>
            </section>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="در انتظار" :value="counters.waiting" tone="waiting" />
                <StatCard label="در محوطه" :value="counters.on_site" tone="checkedin" />
                <StatCard label="در حال بارگیری" :value="counters.loading" tone="loading" />
                <StatCard label="تکمیل‌شده" :value="counters.completed" tone="completed" />
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="لغو شده" :value="counters.cancelled" tone="failed" />
                <StatCard label="عدم حضور" :value="counters.no_show" tone="failed" />
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">میانگین بارگیری امروز</p>
                    <p class="mt-1 text-xl font-bold text-slate-900">{{ duration(avgLoadingMinutes) }}</p>
                </div>
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">نرخ عدم حضور (هفته)</p>
                    <p class="num mt-1 text-xl font-bold text-rose-600">٪{{ week.summary.no_show_rate }}</p>
                </div>
            </div>

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
