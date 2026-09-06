<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import QrCode from '@/components/QrCode.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import DriverLayout from '@/layouts/DriverLayout.vue';
import type { Appointment, PageProps } from '@/types';
import { duration } from '@/lib/format';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{ appointment: Appointment; qr: { token: string } | null }>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const confirmingCancel = ref(false);
const cancelling = ref(false);

const canCancel = computed(
    () => props.appointment.is_active && !['CHECKED_IN', 'LOADING', 'LOADED'].includes(props.appointment.status),
);

const isCalled = computed(() => props.appointment.status === 'CALLED');

/**
 * تازه‌سازی وضعیت.
 * فعلاً polling سبک روی همین صفحه است؛ در فاز بعد جای آن را WebSocket می‌گیرد
 * و این تایمر حذف می‌شود.
 */
let poller: ReturnType<typeof setInterval> | null = null;

function refresh() {
    if (!props.appointment.is_active || document.hidden) return;
    router.reload({ only: ['appointment'] });
}

onMounted(() => {
    if (props.appointment.is_active) poller = setInterval(refresh, 30_000);
});

onUnmounted(() => {
    if (poller) clearInterval(poller);
});

function cancel() {
    cancelling.value = true;
    router.post(
        route('driver.appointments.cancel', props.appointment.ulid),
        {},
        { onFinish: () => (cancelling.value = false) },
    );
}
</script>

<template>
    <DriverLayout title="نوبت من">
        <template #header-action>
            <Link :href="route('driver.home')" class="text-sm text-slate-500 transition hover:text-slate-700">
                نوبت‌های من
            </Link>
        </template>

        <div class="space-y-5">
            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <AlertBox v-if="isCalled" tone="success">
                <p class="font-semibold">نوبت شما فرا رسیده است</p>
                <p class="mt-1">
                    لطفاً به
                    <span class="font-medium">{{ appointment.loading_point ?? 'محوطه بارگیری' }}</span>
                    مراجعه کنید.
                </p>
            </AlertBox>

            <section class="card overflow-hidden">
                <div class="bg-brand-600 px-5 py-6 text-center text-white">
                    <p class="text-sm text-brand-100">شماره نوبت</p>
                    <p class="num mt-1 text-5xl font-bold tracking-tight">{{ appointment.number }}</p>
                    <div class="mt-4 flex justify-center">
                        <span class="rounded-full bg-white/15 px-3 py-1 text-sm">
                            {{ appointment.status_label }}
                        </span>
                    </div>
                </div>

                <dl class="divide-y divide-slate-100">
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-sm text-slate-500">تاریخ</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ appointment.jalali_long }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-sm text-slate-500">ساعت مراجعه</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ appointment.time }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-sm text-slate-500">پلاک</dt>
                        <dd>
                            <PlateBadge v-if="appointment.truck" :plate="appointment.truck.plate" size="sm" />
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-sm text-slate-500">نوع بار</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ appointment.product?.name ?? '—' }}</dd>
                    </div>
                    <div v-if="appointment.loading_point" class="flex items-center justify-between gap-3 px-5 py-3">
                        <dt class="text-sm text-slate-500">لاین بارگیری</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ appointment.loading_point }}</dd>
                    </div>
                </dl>
            </section>

            <section v-if="qr" class="card space-y-3 p-5 text-center">
                <p class="text-sm font-medium text-slate-700">کد ورود به کارخانه</p>
                <QrCode :value="qr.token" :size="200" />
                <p class="text-xs text-slate-500">
                    هنگام ورود، این کد را به نگهبانی نشان دهید. کد تا پایان روز نوبت معتبر است.
                </p>
            </section>

            <!-- وضعیت صف فقط برای نوبت امروز معنی دارد -->
            <section
                v-if="appointment.is_active && !isCalled && appointment.ahead !== null && appointment.ahead !== undefined"
                class="card space-y-3 p-5"
            >
                <div class="flex items-center justify-between">
                    <p class="text-sm text-slate-500">خودروهای جلوتر از شما</p>
                    <p class="num text-2xl font-bold text-slate-900">{{ appointment.ahead }}</p>
                </div>

                <div v-if="appointment.eta_minutes" class="flex items-center justify-between border-t border-slate-100 pt-3">
                    <p class="text-sm text-slate-500">زمان تقریبی انتظار</p>
                    <p class="text-sm font-semibold text-slate-800">
                        حدود {{ duration(appointment.eta_minutes) }}
                    </p>
                </div>

                <p class="text-xs text-slate-400">
                    این عدد تخمینی است و با تغییر صف کارخانه کم و زیاد می‌شود.
                </p>
            </section>

            <AlertBox v-else-if="appointment.is_active && !isCalled && appointment.is_today === false" tone="info">
                نوبت شما برای {{ appointment.jalali_long }} است. وضعیت صف از صبح همان روز اینجا نمایش داده می‌شود.
            </AlertBox>

            <section v-if="appointment.cancel_reason" class="card p-5">
                <p class="text-sm text-slate-500">دلیل لغو</p>
                <p class="mt-1 text-sm font-medium text-slate-800">{{ appointment.cancel_reason }}</p>
            </section>

            <div v-if="canCancel" class="space-y-3">
                <AppButton v-if="!confirmingCancel" variant="secondary" size="lg" @click="confirmingCancel = true">
                    لغو نوبت
                </AppButton>

                <div v-else class="card space-y-4 p-5">
                    <p class="text-sm text-slate-700">
                        نوبت شماره <span class="num font-semibold">{{ appointment.number }}</span> لغو شود؟
                        ظرفیت آزادشده ممکن است بلافاصله توسط راننده‌ی دیگری گرفته شود.
                    </p>
                    <div class="flex gap-3">
                        <AppButton variant="secondary" size="lg" @click="confirmingCancel = false">
                            انصراف
                        </AppButton>
                        <AppButton variant="danger" size="lg" :loading="cancelling" @click="cancel">
                            بله، لغو کن
                        </AppButton>
                    </div>
                </div>
            </div>

            <AlertBox v-else-if="appointment.is_active" tone="info">
                کامیون وارد محوطه شده است؛ لغو نوبت از این مرحله به بعد با اپراتور کارخانه انجام می‌شود.
            </AlertBox>
        </div>
    </DriverLayout>
</template>
