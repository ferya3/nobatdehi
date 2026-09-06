<script setup lang="ts">
import StatCard from '@/components/StatCard.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';

defineProps<{
    jalaliDate: string;
    counters: Record<string, number>;
    avgLoadingMinutes: number;
}>();
</script>

<template>
    <StaffLayout title="داشبورد">
        <div class="space-y-6">
            <div>
                <h1 class="text-lg font-bold text-slate-900">وضعیت بارگیری امروز</h1>
                <p class="mt-0.5 text-sm text-slate-500">{{ jalaliDate }}</p>
            </div>

            <section class="card p-6 text-center">
                <p class="num text-5xl font-bold text-slate-900">{{ counters.total }}</p>
                <p class="mt-1 text-sm text-slate-500">کامیون امروز</p>
            </section>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatCard label="در انتظار" :value="counters.waiting" tone="waiting" />
                <StatCard label="در محوطه" :value="counters.on_site" tone="checkedin" />
                <StatCard label="در حال بارگیری" :value="counters.loading" tone="loading" />
                <StatCard label="تکمیل‌شده" :value="counters.completed" tone="completed" />
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatCard label="لغو شده" :value="counters.cancelled" tone="failed" />
                <StatCard label="عدم حضور" :value="counters.no_show" tone="failed" />
                <div class="card px-4 py-3">
                    <p class="text-xs text-slate-500">میانگین زمان بارگیری</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ duration(avgLoadingMinutes) }}</p>
                </div>
            </div>
        </div>
    </StaffLayout>
</template>
