<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import PlateInput from '@/components/PlateInput.vue';
import SelectCard from '@/components/SelectCard.vue';
import StepIndicator from '@/components/StepIndicator.vue';
import TextInput from '@/components/TextInput.vue';
import DriverLayout from '@/layouts/DriverLayout.vue';
import { duration } from '@/lib/format';
import { uuid } from '@/lib/uuid';
import type { PlateParts, TruckTypeOption } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    driverName: string | null;
    driverNationalCode: string | null;
    lastTruck: { plate: PlateParts; truck_type_id: number | null } | null;
    truckTypes: TruckTypeOption[];
    products: { id: number; name: string; load_tons: string | null; description: string | null }[];
    plateLetters: string[];
}>();

// مرحله‌ی «تاریخ و ساعت» حذف شد: راننده ساعت انتخاب نمی‌کند، می‌بیند.
const STEPS = ['کامیون', 'نوع بار', 'تأیید'];
const step = ref(0);

const form = useForm({
    driver_name: props.driverName ?? '',
    national_code: props.driverNationalCode ?? '',
    plate_two: props.lastTruck?.plate.two ?? '',
    plate_letter: props.lastTruck?.plate.letter ?? '',
    plate_three: props.lastTruck?.plate.three ?? '',
    plate_iran: props.lastTruck?.plate.iran ?? '',
    truck_type_id: props.lastTruck?.truck_type_id ?? (null as number | null),
    product_id: null as number | null,
    // هر بار که فرم باز می‌شود یک کلید تازه: کلیک دوم و سوم نوبت جدید نمی‌سازد
    idempotency_key: uuid(),
});

const selectedProduct = computed(() => props.products.find((p) => p.id === form.product_id) ?? null);
const selectedTruckType = computed(() => props.truckTypes.find((t) => t.id === form.truck_type_id) ?? null);

/** نوبتی که همین حالا به این خودرو می‌رسد */
const opening = computed(() => selectedTruckType.value?.opening ?? null);

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
    return true;
});

// خطای سرور روی هر فیلد، کاربر را به همان مرحله برمی‌گرداند
watch(
    () => form.errors,
    (errors) => {
        const keys = Object.keys(errors);
        if (!keys.length) return;

        if (
            keys.some(
                (k) =>
                    k.startsWith('plate') ||
                    k === 'driver_name' ||
                    k === 'national_code' ||
                    k === 'truck_type_id',
            )
        )
            step.value = 0;
        else if (keys.includes('product_id')) step.value = 1;
    },
);

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

                <!-- نوع خودرو تنها چیزی است که ساعت نوبت را جابه‌جا می‌کند،
                     چون مدت بارگیری از همین می‌آید. پس نوبتِ هر گزینه روی
                     خودِ کارت نوشته می‌شود. -->
                <FormField label="نوع خودرو" :error="form.errors.truck_type_id">
                    <div class="space-y-2">
                        <SelectCard
                            v-for="type in truckTypes"
                            :key="type.id"
                            :selected="form.truck_type_id === type.id"
                            @click="form.truck_type_id = type.id"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium text-slate-800">{{ type.name }}</p>
                                    <p v-if="type.capacity_tons" class="mt-0.5 text-xs text-slate-500">
                                        تا <span class="num">{{ Number(type.capacity_tons) }}</span> تن ·
                                        بارگیری ≈ {{ duration(type.loading_minutes) }}
                                    </p>
                                </div>

                                <div v-if="type.opening" class="shrink-0 text-left">
                                    <p class="num text-sm font-bold text-brand-700" dir="ltr">
                                        {{ type.opening.starts_at }}
                                    </p>
                                    <p class="text-[11px] text-slate-500">
                                        {{ type.opening.is_today ? 'امروز' : type.opening.day_label.split(' ')[0] }}
                                    </p>
                                </div>
                                <p v-else class="shrink-0 text-[11px] text-amber-700">جای خالی نیست</p>
                            </div>
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

            <!-- مرحله ۳: تأیید -->
            <section v-show="step === 2" class="space-y-4">
                <!-- نوبتی که سامانه اعلام می‌کند -->
                <div v-if="opening" class="card overflow-hidden">
                    <div class="bg-brand-50 px-5 py-4 text-center">
                        <p class="text-xs text-brand-800">زمان نوبت شما</p>
                        <p class="num mt-1 text-3xl font-bold text-brand-900" dir="ltr">
                            {{ opening.starts_at }}
                        </p>
                        <p class="mt-1 text-sm font-medium text-brand-800">
                            {{ opening.is_today ? 'امروز' : opening.day_label }} — {{ opening.jalali }}
                        </p>
                    </div>

                    <p class="border-t border-brand-100 px-5 py-3 text-center text-xs text-slate-500">
                        تخمین پایان بارگیری: <span class="num font-medium text-slate-700">{{ opening.ends_at }}</span>
                        · مدت {{ duration(opening.loading_minutes) }}
                    </p>
                </div>

                <AlertBox v-else tone="warning">
                    برای این نوع خودرو تا انتهای افق نوبت‌دهی جای خالی نیست.
                    نوع خودروی دیگری انتخاب کنید یا بعداً دوباره تلاش کنید.
                </AlertBox>

                <section class="card divide-y divide-slate-100 p-5">
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
                    </dl>

                    <p class="pt-4 text-xs text-slate-500">
                        ترتیب نوبت‌ها را کارخانه تعیین می‌کند. ساعت بالا تا لحظه‌ی ثبت تخمینی است؛
                        اگر در همین فاصله کسی دیگر نوبت گرفته باشد، ساعت قطعیِ شما در صفحه‌ی
                        نوبت و پیامک اعلام می‌شود.
                    </p>
                </section>
            </section>

            <div class="flex gap-3">
                <AppButton variant="secondary" size="lg" @click="back">
                    {{ step === 0 ? 'انصراف' : 'مرحله قبل' }}
                </AppButton>

                <AppButton v-if="step < STEPS.length - 1" size="lg" :disabled="!canContinue" @click="next">
                    ادامه
                </AppButton>

                <AppButton v-else size="lg" :loading="form.processing" :disabled="!opening" @click="submit">
                    ثبت نهایی نوبت
                </AppButton>
            </div>
        </div>
    </DriverLayout>
</template>
