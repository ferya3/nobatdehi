<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import PlateInput from '@/components/PlateInput.vue';
import SelectCard from '@/components/SelectCard.vue';
import StepIndicator from '@/components/StepIndicator.vue';
import TextInput from '@/components/TextInput.vue';
import DriverLayout from '@/layouts/DriverLayout.vue';
import { uuid } from '@/lib/uuid';
import { duration } from '@/lib/format';
import type { DayOption, PlateParts, SlotOption } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    driverName: string | null;
    driverNationalCode: string | null;
    lastTruck: { plate: PlateParts; truck_type_id: number | null } | null;
    truckTypes: { id: number; name: string; capacity_tons: string | null; loading_minutes: number }[];
    products: { id: number; name: string; load_tons: string | null; description: string | null }[];
    days: DayOption[];
    slots: SlotOption[];
    selectedDate: string | null;
    plateLetters: string[];
}>();

const STEPS = ['کامیون', 'نوع بار', 'تاریخ و ساعت', 'تأیید'];
const step = ref(0);

const form = useForm({
    driver_name: props.driverName ?? '',
    national_code: props.driverNationalCode ?? '',
    plate_two: props.lastTruck?.plate.two ?? '',
    plate_letter: props.lastTruck?.plate.letter ?? '',
    plate_three: props.lastTruck?.plate.three ?? '',
    plate_iran: props.lastTruck?.plate.iran ?? '',
    truck_type_id: props.lastTruck?.truck_type_id ?? null as number | null,
    product_id: null as number | null,
    slot_id: null as number | null,
    date: props.selectedDate ?? '',
    // هر بار که فرم باز می‌شود یک کلید تازه: کلیک دوم و سوم نوبت جدید نمی‌سازد
    idempotency_key: uuid(),
});

const loadingSlots = ref(false);

const openDays = computed(() => props.days.filter((day) => day.is_open && day.remaining > 0));
const selectedProduct = computed(() => props.products.find((p) => p.id === form.product_id) ?? null);
const selectedSlot = computed(() => props.slots.find((s) => s.id === form.slot_id) ?? null);
const selectedDay = computed(() => props.days.find((d) => d.date === form.date) ?? null);
const selectedTruckType = computed(() => props.truckTypes.find((t) => t.id === form.truck_type_id) ?? null);

const plateComplete = computed(
    () =>
        form.plate_two.length === 2 &&
        form.plate_letter !== '' &&
        form.plate_three.length === 3 &&
        form.plate_iran.length === 2,
);

const canContinue = computed(() => {
    if (step.value === 0)
        return (
            form.driver_name.trim().length >= 3 &&
            form.national_code.trim().length === 10 &&
            plateComplete.value &&
            form.truck_type_id !== null
        );
    if (step.value === 1) return form.product_id !== null;
    if (step.value === 2) return form.slot_id !== null;
    return true;
});

// خطای سرور روی هر فیلد، کاربر را به همان مرحله برمی‌گرداند
watch(
    () => form.errors,
    (errors) => {
        const keys = Object.keys(errors);
        if (!keys.length) return;

        if (keys.some((k) => k.startsWith('plate') || k === 'driver_name' || k === 'national_code' || k === 'truck_type_id'))
            step.value = 0;
        else if (keys.includes('product_id')) step.value = 1;
        else if (keys.includes('slot_id')) step.value = 2;
    },
);

function pickDay(date: string) {
    form.date = date;
    form.slot_id = null;
    loadingSlots.value = true;

    router.reload({
        only: ['slots', 'selectedDate'],
        data: { date },
        onFinish: () => (loadingSlots.value = false),
    });
}

function next() {
    if (canContinue.value && step.value < STEPS.length - 1) step.value += 1;
}

function back() {
    if (step.value > 0) step.value -= 1;
    else router.get(route('driver.home'));
}

function submit() {
    form.post(route('driver.booking.store'), { preserveScroll: true });
}
</script>

<template>
    <DriverLayout title="گرفتن نوبت">
        <div class="space-y-6">
            <StepIndicator :steps="STEPS" :current="step" />

            <AlertBox v-if="form.errors.slot_id && step !== 2" tone="error">
                {{ form.errors.slot_id }}
            </AlertBox>

            <!-- مرحله ۱: کامیون -->
            <section v-show="step === 0" class="card space-y-5 p-5">
                <FormField label="نام و نام خانوادگی راننده" for="driver_name" :error="form.errors.driver_name">
                    <TextInput
                        id="driver_name"
                        v-model="form.driver_name"
                        placeholder="مثلاً علی رضایی"
                        :invalid="!!form.errors.driver_name"
                    />
                </FormField>

                <FormField
                    label="کد ملی راننده"
                    for="national_code"
                    :error="form.errors.national_code"
                    hint="حواله و برگه‌ی خروج به همین نام و کد ملی صادر می‌شود"
                >
                    <TextInput
                        id="national_code"
                        v-model="form.national_code"
                        inputmode="numeric"
                        dir="ltr"
                        :maxlength="10"
                        placeholder="۰۰۱۲۳۴۵۶۷۸"
                        :invalid="!!form.errors.national_code"
                    />
                </FormField>

                <FormField
                    label="شماره پلاک"
                    :error="form.errors.plate_two || form.errors.plate_letter || form.errors.plate_three || form.errors.plate_iran"
                >
                    <PlateInput
                        v-model:two="form.plate_two"
                        v-model:letter="form.plate_letter"
                        v-model:three="form.plate_three"
                        v-model:iran="form.plate_iran"
                        :letters="plateLetters"
                        :invalid="!!form.errors.plate_two"
                    />
                </FormField>

                <FormField label="نوع خودرو" :error="form.errors.truck_type_id">
                    <div class="grid grid-cols-2 gap-2">
                        <SelectCard
                            v-for="type in truckTypes"
                            :key="type.id"
                            :selected="form.truck_type_id === type.id"
                            @click="form.truck_type_id = type.id"
                        >
                            <p class="font-medium text-slate-800">{{ type.name }}</p>
                            <p v-if="type.capacity_tons" class="mt-0.5 text-xs text-slate-500">
                                تا <span class="num">{{ Number(type.capacity_tons) }}</span> تن
                            </p>
                            <p class="mt-0.5 text-xs text-slate-400">
                                بارگیری ≈ {{ duration(type.loading_minutes) }}
                            </p>
                        </SelectCard>
                    </div>
                </FormField>
            </section>

            <!-- مرحله ۲: نوع بار -->
            <section v-show="step === 1" class="space-y-3">
                <FormField label="نوع بار" :error="form.errors.product_id">
                    <div class="space-y-2">
                        <SelectCard
                            v-for="product in products"
                            :key="product.id"
                            :selected="form.product_id === product.id"
                            @click="form.product_id = product.id"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="font-medium text-slate-800">{{ product.name }}</p>
                                    <p v-if="product.description" class="mt-0.5 text-xs text-slate-500">
                                        {{ product.description }}
                                    </p>
                                </div>
                                <p v-if="product.load_tons" class="shrink-0 text-sm text-slate-600">
                                    <span class="num font-semibold">{{ Number(product.load_tons) }}</span> تن
                                </p>
                            </div>
                        </SelectCard>
                    </div>
                </FormField>
            </section>

            <!-- مرحله ۳: تاریخ و ساعت -->
            <section v-show="step === 2" class="space-y-5">
                <FormField label="تاریخ مراجعه">
                    <div class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2">
                        <button
                            v-for="day in openDays"
                            :key="day.date"
                            type="button"
                            :class="[
                                'w-24 shrink-0 rounded-xl border p-3 text-center transition',
                                form.date === day.date
                                    ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                                    : 'border-slate-200 bg-white hover:border-brand-300',
                            ]"
                            @click="pickDay(day.date)"
                        >
                            <p class="text-xs text-slate-500">{{ day.is_today ? 'امروز' : day.jalali_label.split(' ')[0] }}</p>
                            <p class="num mt-1 text-sm font-semibold text-slate-800">{{ day.jalali }}</p>
                            <p class="mt-1 text-[11px] text-slate-400">
                                <span class="num">{{ day.remaining }}</span> جای خالی
                            </p>
                        </button>
                    </div>

                    <AlertBox v-if="!openDays.length" tone="warning">
                        در حال حاضر روز خالی برای نوبت‌دهی وجود ندارد.
                    </AlertBox>
                </FormField>

                <FormField v-if="form.date" label="ساعت مراجعه" :error="form.errors.slot_id">
                    <div v-if="loadingSlots" class="grid grid-cols-3 gap-2">
                        <div v-for="n in 6" :key="n" class="h-16 animate-pulse rounded-xl bg-slate-100" />
                    </div>

                    <div v-else-if="slots.length" class="grid grid-cols-3 gap-2">
                        <button
                            v-for="slot in slots"
                            :key="slot.id"
                            type="button"
                            :disabled="!slot.selectable"
                            :class="[
                                'rounded-xl border p-2.5 text-center transition',
                                !slot.selectable
                                    ? 'cursor-not-allowed border-slate-200 bg-slate-50 text-slate-300'
                                    : form.slot_id === slot.id
                                      ? 'border-brand-500 bg-brand-50/60 ring-1 ring-brand-500'
                                      : 'border-slate-200 bg-white hover:border-brand-300',
                            ]"
                            @click="form.slot_id = slot.id"
                        >
                            <p class="num text-sm font-semibold">{{ slot.start_time }}</p>
                            <p class="mt-0.5 text-[11px]" :class="slot.selectable ? 'text-slate-500' : 'text-slate-300'">
                                <span v-if="slot.remaining > 0">
                                    <span class="num">{{ slot.remaining }}</span> جا
                                </span>
                                <span v-else>تکمیل</span>
                            </p>
                        </button>
                    </div>

                    <AlertBox v-else tone="warning">این روز ساعت خالی ندارد.</AlertBox>
                </FormField>
            </section>

            <!-- مرحله ۴: تأیید -->
            <section v-show="step === 3" class="card divide-y divide-slate-100 p-5">
                <dl class="space-y-3 pb-4">
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">راننده</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ form.driver_name }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">پلاک</dt>
                        <dd class="num text-sm font-medium text-slate-800" dir="ltr">
                            {{ form.plate_two }} {{ form.plate_letter }} {{ form.plate_three }} — {{ form.plate_iran }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">نوع خودرو</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ selectedTruckType?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">نوع بار</dt>
                        <dd class="text-sm font-medium text-slate-800">{{ selectedProduct?.name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">تاریخ</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ selectedDay?.jalali ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">ساعت</dt>
                        <dd class="num text-sm font-medium text-slate-800">{{ selectedSlot?.start_time ?? '—' }}</dd>
                    </div>
                    <div v-if="selectedTruckType" class="flex justify-between gap-3">
                        <dt class="text-sm text-slate-500">مدت بارگیری (تخمینی)</dt>
                        <dd class="text-sm font-medium text-slate-800">
                            {{ duration(selectedTruckType.loading_minutes) }}
                        </dd>
                    </div>
                </dl>

                <p class="pt-4 text-xs text-slate-500">
                    با ثبت نوبت، شماره نوبت و کد QR برای شما صادر می‌شود. هنگام ورود به کارخانه کد QR را نشان دهید.
                </p>
            </section>

            <div class="flex gap-3">
                <AppButton variant="secondary" size="lg" @click="back">
                    {{ step === 0 ? 'انصراف' : 'مرحله قبل' }}
                </AppButton>

                <AppButton v-if="step < STEPS.length - 1" size="lg" :disabled="!canContinue" @click="next">
                    ادامه
                </AppButton>

                <AppButton v-else size="lg" :loading="form.processing" @click="submit">
                    ثبت نهایی نوبت
                </AppButton>
            </div>
        </div>
    </DriverLayout>
</template>
