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

const kg = (value: string | null | undefined) =>
    value === null || value === undefined ? '—' : Number(value).toLocaleString('en-US');
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
                        <div v-if="appointment.cancelled_by_label" class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">لغوکننده</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-rose-700">{{ appointment.cancelled_by_label }}</dd>
                        </div>
                        <div v-if="appointment.cancel_reason" class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">دلیل لغو</dt>
                            <dd class="mt-0.5 text-sm font-medium text-rose-700">{{ appointment.cancel_reason }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- «چطور وارد شد» — همان چیزی که در بازبینیِ یک حادثه پرسیده می‌شود -->
                <section v-if="appointment.gate" class="card space-y-4 p-5 lg:col-span-2">
                    <h2 class="text-sm font-semibold text-slate-700">ورود از گیت</h2>

                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-slate-500">روش تأیید حواله</dt>
                            <dd
                                class="mt-0.5 text-sm font-medium"
                                :class="appointment.gate.entry_method === 'manual' ? 'text-amber-700' : 'text-slate-800'"
                            >
                                {{ appointment.gate.entry_method_label ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">دستگاه اسکن</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ appointment.gate.scan_source_label ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">تأییدکننده پلاک</dt>
                            <dd class="mt-0.5 text-sm font-medium text-slate-800">
                                {{ appointment.gate.plate_source_label ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">پلاک ثبت‌شده</dt>
                            <dd class="num mt-0.5 text-sm font-medium text-slate-800">
                                {{ appointment.gate.observed_plate ?? '—' }}
                            </dd>
                        </div>
                        <div v-if="appointment.gate.override_reason" class="sm:col-span-2">
                            <dt class="text-xs text-slate-500">دلیل ثبت دستی</dt>
                            <dd class="mt-0.5 text-sm font-medium text-amber-700">
                                {{ appointment.gate.override_reason }}
                            </dd>
                        </div>
                    </dl>

                    <!-- عکسِ لحظه‌ی ورود -->
                    <div
                        v-if="appointment.gate.reading"
                        class="flex items-start gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4"
                    >
                        <a
                            v-if="appointment.gate.reading.image_url"
                            :href="appointment.gate.reading.image_url"
                            target="_blank"
                            class="shrink-0"
                        >
                            <img
                                :src="appointment.gate.reading.image_url"
                                alt="عکس پلاک هنگام ورود"
                                class="size-24 rounded-lg border border-slate-200 object-cover"
                            />
                        </a>
                        <div class="min-w-0 flex-1 text-sm">
                            <p class="font-medium text-slate-800">{{ appointment.gate.reading.source_label }}</p>
                            <p class="num mt-1 text-slate-700">
                                {{ appointment.gate.reading.plate_key ?? 'پلاک خوانده نشد' }}
                                <span v-if="appointment.gate.reading.confidence !== null" class="text-xs text-slate-500">
                                    (اطمینان {{ appointment.gate.reading.confidence }}٪)
                                </span>
                            </p>
                            <p class="num mt-1 text-xs text-slate-500">
                                {{ appointment.gate.reading.clock }}
                                <span v-if="appointment.gate.reading.lane"> — {{ appointment.gate.reading.lane }}</span>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- توزین: عددها و اینکه برگه‌ی خروج چه شد -->
                <section
                    v-if="appointment.weighing"
                    class="card space-y-4 p-5 lg:col-span-2"
                    :class="appointment.weighing.discrepancy ? 'border-rose-300' : ''"
                >
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-slate-700">توزین</h2>
                        <span
                            v-if="appointment.weighing.discrepancy_label"
                            class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-bold text-rose-800"
                        >
                            ⚠ {{ appointment.weighing.discrepancy_label }}
                        </span>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs text-slate-500">وزن خالص</dt>
                            <dd class="num mt-0.5 text-sm font-medium text-slate-800" dir="ltr">
                                {{ kg(appointment.weighing.net_kg) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">تناژ حواله</dt>
                            <dd class="num mt-0.5 text-sm font-medium text-slate-800" dir="ltr">
                                {{ kg(appointment.weighing.expected_kg) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">اختلاف</dt>
                            <dd
                                class="num mt-0.5 text-sm font-medium"
                                :class="appointment.weighing.discrepancy ? 'text-rose-700' : 'text-slate-800'"
                                dir="ltr"
                            >
                                {{ kg(appointment.weighing.variance_kg) }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="appointment.weighing.exit_permit_number"
                        class="rounded-xl bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900"
                    >
                        برگه خروج <span class="num">{{ appointment.weighing.exit_permit_number }}</span> صادر شد.
                    </p>

                    <p
                        v-else-if="appointment.weighing.discrepancy"
                        class="rounded-xl bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900"
                    >
                        برگه خروج تا تعیین تکلیف صادر نمی‌شود.
                        <span v-if="appointment.weighing.alerted_at" class="block text-xs font-normal">
                            اخطار برای مدیر ارسال شد.
                        </span>
                    </p>
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
