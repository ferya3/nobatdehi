<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import QrScanner from '@/components/QrScanner.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { Appointment, PageProps, PlateParts } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Busy {
    ulid: string;
    number: number;
    plate: PlateParts | null;
    product: string | null;
    loading_point: string | null;
    elapsed_minutes: number | null;
    expected_minutes: number;
    is_late: boolean;
}

type Found = Appointment & {
    action: 'start' | 'finish' | null;
    has_tare: boolean;
    expected_minutes: number;
    elapsed_minutes: number | null;
    is_late: boolean;
};

const props = defineProps<{
    loadingPoints: { id: number; name: string }[];
    inProgress: Busy[];
    result?: { error: string | null; appointment: Found | null };
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const scanner = ref<InstanceType<typeof QrScanner> | null>(null);

const found = computed(() => props.result?.appointment ?? null);
const error = computed(() => props.result?.error ?? null);

const form = useForm({ to: '', loading_point_id: null as number | null });

const lateCount = computed(() => props.inProgress.filter((row) => row.is_late).length);

function onDetected(token: string) {
    router.post(route('staff.loading.scan'), { token }, { preserveScroll: true });
}

function act(to: 'LOADING' | 'LOADED') {
    if (!found.value) return;

    form.to = to;
    form.post(route('staff.loading.transition', found.value.ulid), { preserveScroll: true });
}

function reset() {
    scanner.value?.stop();
    form.reset();
    router.get(route('staff.loading.index'));
}
</script>

<template>
    <StaffLayout title="لاین بارگیری">
        <div class="mx-auto max-w-xl space-y-5">
            <div class="flex items-center justify-between">
                <h1 class="text-lg font-bold text-slate-900">لاین بارگیری</h1>
                <p v-if="lateCount" class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-900">
                    <span class="num">{{ lateCount }}</span> کامیون با تأخیر
                </p>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>
            <AlertBox v-if="error" tone="error">{{ error }}</AlertBox>

            <section v-if="found" class="card overflow-hidden">
                <div class="flex items-center justify-between bg-slate-50 px-5 py-3">
                    <div>
                        <p class="text-xs text-slate-500">حواله</p>
                        <p class="num text-xl font-bold text-slate-900">{{ found.number }}</p>
                    </div>
                    <StatusBadge :tone="found.status_tone" :label="found.status_label" size="sm" />
                </div>

                <dl class="divide-y divide-slate-100">
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">پلاک</dt>
                        <dd><PlateBadge v-if="found.truck" :plate="found.truck.plate" size="sm" /></dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">بار</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ found.product?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">مدت مورد انتظار</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ found.expected_minutes }} دقیقه</dd>
                    </div>
                    <div v-if="found.elapsed_minutes !== null" class="flex items-center justify-between px-5 py-3">
                        <dt class="text-sm text-slate-500">از شروع بارگیری</dt>
                        <dd :class="['num text-sm font-semibold', found.is_late ? 'text-amber-700' : 'text-slate-800']">
                            {{ found.elapsed_minutes }} دقیقه
                        </dd>
                    </div>
                </dl>

                <div class="space-y-4 border-t border-slate-100 p-5">
                    <AlertBox v-if="found.action === 'start' && !found.has_tare" tone="error">
                        وزن خالی این کامیون ثبت نشده است. اول باید از باسکول اول رد شود.
                    </AlertBox>

                    <template v-else-if="found.action === 'start'">
                        <FormField label="لاین بارگیری" :error="form.errors.loading_point_id">
                            <div class="grid grid-cols-2 gap-2">
                                <button
                                    v-for="point in loadingPoints"
                                    :key="point.id"
                                    type="button"
                                    :class="[
                                        'rounded-xl border px-3 py-2.5 text-sm font-medium transition',
                                        form.loading_point_id === point.id
                                            ? 'border-brand-500 bg-brand-50 text-brand-800'
                                            : 'border-slate-300 text-slate-600 hover:bg-slate-50',
                                    ]"
                                    @click="form.loading_point_id = point.id"
                                >
                                    {{ point.name }}
                                </button>
                            </div>
                        </FormField>

                        <AppButton size="lg" :loading="form.processing" @click="act('LOADING')">شروع بارگیری</AppButton>
                    </template>

                    <template v-else-if="found.action === 'finish'">
                        <AlertBox v-if="found.is_late" tone="warning">
                            بارگیری این کامیون از مدت مورد انتظار گذشته است.
                        </AlertBox>

                        <AppButton size="lg" :loading="form.processing" @click="act('LOADED')">پایان بارگیری</AppButton>
                    </template>

                    <AlertBox v-else tone="warning">
                        این حواله الان کاری روی لاین ندارد.
                    </AlertBox>

                    <AppButton variant="secondary" size="lg" @click="reset">حواله‌ی بعدی</AppButton>
                </div>
            </section>

            <section v-else class="card space-y-3 p-5">
                <h2 class="text-sm font-semibold text-slate-700">اسکن QR حواله</h2>
                <p class="text-xs text-slate-500">
                    بدون اسکن حواله، بارگیری شروع نمی‌شود.
                </p>
                <QrScanner ref="scanner" @detected="onDetected" />
            </section>

            <!-- روی لاین‌ها همین حالا -->
            <section v-if="inProgress.length" class="card overflow-hidden">
                <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                    در حال بارگیری
                </h2>

                <ul class="divide-y divide-slate-100">
                    <li
                        v-for="row in inProgress"
                        :key="row.ulid"
                        :class="['flex items-center justify-between gap-3 px-5 py-3', row.is_late && 'bg-amber-50/60']"
                    >
                        <div class="min-w-0">
                            <p class="num text-sm font-semibold text-slate-800">{{ row.number }}</p>
                            <p class="truncate text-xs text-slate-500">
                                {{ row.product ?? '—' }}<span v-if="row.loading_point"> · {{ row.loading_point }}</span>
                            </p>
                        </div>
                        <div class="text-left">
                            <p :class="['num text-sm font-semibold', row.is_late ? 'text-amber-700' : 'text-slate-700']">
                                {{ row.elapsed_minutes }} / {{ row.expected_minutes }} دقیقه
                            </p>
                            <p v-if="row.is_late" class="text-xs font-medium text-amber-700">تأخیر</p>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </StaffLayout>
</template>
