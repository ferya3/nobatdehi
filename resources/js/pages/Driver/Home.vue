<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DriverLayout from '@/layouts/DriverLayout.vue';
import type { Appointment, PageProps } from '@/types';
import { duration } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    driver: { name: string | null; mobile: string };
    active: Appointment[];
    past: Appointment[];
    canBook: boolean;
    activeLimit: number | null;
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

function logout() {
    router.post(route('driver.logout'));
}
</script>

<template>
    <DriverLayout title="نوبت‌های من">
        <template #header-action>
            <button
                type="button"
                class="rounded-lg px-2 py-1 text-sm text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                @click="logout"
            >
                خروج
            </button>
        </template>

        <div class="space-y-5">
            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <section v-if="active.length" class="space-y-3">
                <h2 class="text-sm font-semibold text-slate-500">نوبت‌های فعال</h2>

                <Link
                    v-for="appointment in active"
                    :key="appointment.ulid"
                    :href="route('driver.appointments.show', appointment.ulid)"
                    class="card block p-4 transition hover:border-brand-300 hover:shadow-md"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-xs text-slate-500">شماره نوبت</p>
                            <p class="num text-2xl font-bold text-slate-900">{{ appointment.number }}</p>
                        </div>
                        <StatusBadge :tone="appointment.status_tone" :label="appointment.status_label" />
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-y-3 text-sm">
                        <div>
                            <dt class="text-xs text-slate-500">تاریخ</dt>
                            <dd class="num font-medium text-slate-800">{{ appointment.jalali_date }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">ساعت مراجعه</dt>
                            <dd class="num font-medium text-slate-800">{{ appointment.time }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">نوع بار</dt>
                            <dd class="font-medium text-slate-800">{{ appointment.product?.name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">پلاک</dt>
                            <dd class="mt-0.5">
                                <PlateBadge v-if="appointment.truck" :plate="appointment.truck.plate" size="sm" />
                            </dd>
                        </div>
                        <div v-if="appointment.schedule">
                            <dt class="text-xs text-slate-500">تخمین شروع بارگیری</dt>
                            <dd class="num font-semibold text-brand-700">{{ appointment.schedule.starts_at }}</dd>
                        </div>
                        <div v-if="appointment.schedule">
                            <dt class="text-xs text-slate-500">مدت بارگیری</dt>
                            <dd class="font-medium text-slate-800">
                                {{ duration(appointment.schedule.loading_minutes) }}
                            </dd>
                        </div>
                    </dl>

                    <p
                        v-if="appointment.ahead !== null && appointment.ahead !== undefined && appointment.status !== 'CALLED'"
                        class="mt-4 border-t border-slate-100 pt-3 text-sm text-slate-600"
                    >
                        <span class="num font-semibold text-slate-800">{{ appointment.ahead }}</span>
                        خودرو جلوتر از شما
                        <span v-if="appointment.eta_minutes" class="text-slate-400">·</span>
                        <span v-if="appointment.eta_minutes">
                            حدود {{ duration(appointment.eta_minutes) }} (تخمینی)
                        </span>
                    </p>

                    <p v-else-if="appointment.status === 'CALLED'" class="mt-4 border-t border-emerald-100 pt-3 text-sm font-medium text-emerald-700">
                        نوبت شما فرا رسیده است
                        <span v-if="appointment.loading_point">— {{ appointment.loading_point }}</span>
                    </p>
                </Link>
            </section>

            <section v-else class="card space-y-4 p-6 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-600">
                    <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                        <rect x="3" y="5" width="18" height="16" rx="2" />
                        <path d="M3 10h18M8 3v4M16 3v4" stroke-linecap="round" />
                    </svg>
                </div>
                <div>
                    <p class="font-semibold text-slate-800">هنوز نوبتی ندارید</p>
                    <p class="mt-1 text-sm text-slate-500">برای بارگیری، نوبت خود را رزرو کنید.</p>
                </div>
            </section>

            <AppButton v-if="canBook" size="lg" @click="router.get(route('driver.booking.create'))">
                گرفتن نوبت جدید
            </AppButton>

            <AlertBox v-else-if="active.length" tone="warning">
                با این شماره حداکثر
                <span class="num font-semibold">{{ activeLimit }}</span>
                نوبت فعال می‌توانید داشته باشید. برای گرفتن نوبت جدید، یکی از نوبت‌های فعلی باید تکمیل یا لغو شود.
            </AlertBox>

            <section v-if="past.length" class="space-y-3">
                <h2 class="text-sm font-semibold text-slate-500">نوبت‌های گذشته</h2>

                <ul class="card divide-y divide-slate-100">
                    <li
                        v-for="appointment in past"
                        :key="appointment.ulid"
                        class="flex items-center justify-between gap-3 px-4 py-3"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800">
                                نوبت <span class="num">{{ appointment.number }}</span>
                            </p>
                            <p class="num mt-0.5 text-xs text-slate-500">
                                {{ appointment.jalali_date }} · {{ appointment.time }}
                            </p>
                        </div>
                        <StatusBadge
                            :tone="appointment.status_tone"
                            :label="appointment.status_label"
                            size="sm"
                        />
                    </li>
                </ul>
            </section>
        </div>

        <template #footer>
            <p class="num">{{ driver.mobile }}</p>
        </template>
    </DriverLayout>
</template>
