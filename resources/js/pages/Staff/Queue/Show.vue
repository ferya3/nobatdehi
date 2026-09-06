<script setup lang="ts">
import PlateBadge from '@/components/PlateBadge.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';
import type { Appointment, StatusTone } from '@/types';
import { Link } from '@inertiajs/vue3';

defineProps<{
    appointment: Appointment & { wait_minutes: number | null; loading_minutes: number | null };
    timeline: {
        id: number;
        from: string | null;
        to: string;
        tone: StatusTone;
        is_rollback: boolean;
        reason: string | null;
        actor: string;
        at: string | null;
        clock: string | null;
    }[];
}>();
</script>

<template>
    <StaffLayout :title="`نوبت ${appointment.number}`">
        <div class="space-y-5">
            <Link :href="route('staff.queue.index')" class="text-sm text-slate-500 transition hover:text-slate-700">
                ← بازگشت به صف
            </Link>

            <div class="grid gap-5 lg:grid-cols-3">
                <section class="card space-y-4 p-5 lg:col-span-2">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs text-slate-500">شماره نوبت</p>
                            <p class="num text-3xl font-bold text-slate-900">{{ appointment.number }}</p>
                        </div>
                        <StatusBadge :tone="appointment.status_tone" :label="appointment.status_label" />
                    </div>

                    <dl class="grid gap-x-6 gap-y-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-slate-500">تاریخ</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">{{ appointment.jalali_long }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">ساعت نوبت</dt>
                            <dd class="num mt-0.5 text-sm font-medium text-slate-800">{{ appointment.time }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">راننده</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ appointment.driver?.name ?? '—' }}
                                <span class="num block text-xs text-slate-400">{{ appointment.driver?.mobile }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">پلاک</dt>
                            <dd class="mt-1">
                                <PlateBadge v-if="appointment.truck" :plate="appointment.truck.plate" size="sm" />
                                <span v-if="appointment.truck?.type" class="mt-1 block text-xs text-slate-400">
                                    {{ appointment.truck.type }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">نوع بار</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ appointment.product?.name ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">لاین بارگیری</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ appointment.loading_point ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="appointment.wait_minutes !== null">
                            <dt class="text-xs text-slate-500">مدت انتظار در محوطه</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ duration(appointment.wait_minutes) }}
                            </dd>
                        </div>
                        <div v-if="appointment.loading_minutes !== null">
                            <dt class="text-xs text-slate-500">مدت بارگیری</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ duration(appointment.loading_minutes) }}
                            </dd>
                        </div>
                        <div v-if="appointment.cancel_reason" class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">دلیل لغو</dt>
                            <dd class="mt-0.5 text-sm font-medium text-rose-700">{{ appointment.cancel_reason }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="card p-5">
                    <h2 class="text-sm font-semibold text-slate-700">تاریخچه وضعیت</h2>

                    <ol class="mt-4 space-y-4">
                        <li v-for="entry in timeline" :key="entry.id" class="relative ps-6">
                            <span
                                class="absolute end-auto start-0 top-1.5 size-2.5 rounded-full ring-4 ring-white"
                                :class="entry.is_rollback ? 'bg-amber-400' : 'bg-brand-500'"
                            />
                            <span
                                v-if="entry.id !== timeline[timeline.length - 1].id"
                                class="absolute start-[4.5px] top-5 h-[calc(100%+0.5rem)] w-px bg-slate-200"
                            />

                            <p class="text-sm font-medium text-slate-800">
                                {{ entry.to }}
                                <span v-if="entry.is_rollback" class="text-xs font-normal text-amber-600">(بازگردانی)</span>
                            </p>
                            <p class="num mt-0.5 text-xs text-slate-500">{{ entry.clock }} · {{ entry.actor }}</p>
                            <p v-if="entry.reason" class="mt-1 text-xs text-slate-600">{{ entry.reason }}</p>
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </StaffLayout>
</template>
