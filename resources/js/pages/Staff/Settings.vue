<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface WorkingHour {
    weekday: number;
    name: string;
    is_open: boolean;
    opens_at: string;
    closes_at: string;
    capacity_per_slot: number;
}

const props = defineProps<{
    factory: Record<string, number | string>;
    workingHours: WorkingHour[];
    weekdays: string[];
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const form = useForm({
    slot_minutes: Number(props.factory.slot_minutes),
    daily_capacity: Number(props.factory.daily_capacity),
    loading_lines: Number(props.factory.loading_lines),
    avg_loading_minutes: Number(props.factory.avg_loading_minutes),
    booking_horizon_days: Number(props.factory.booking_horizon_days),
    booking_lead_minutes: Number(props.factory.booking_lead_minutes),
    max_active_per_mobile: Number(props.factory.max_active_per_mobile),
    max_active_per_plate: Number(props.factory.max_active_per_plate),
    working_hours: props.workingHours.map((day) => ({ ...day })),
});

type NumericField =
    | 'slot_minutes'
    | 'daily_capacity'
    | 'loading_lines'
    | 'avg_loading_minutes'
    | 'booking_horizon_days'
    | 'booking_lead_minutes'
    | 'max_active_per_mobile'
    | 'max_active_per_plate';

const NUMBERS: { key: NumericField; label: string; hint?: string }[] = [
    { key: 'slot_minutes', label: 'طول هر نوبت (دقیقه)' },
    { key: 'daily_capacity', label: 'حداکثر نوبت روزانه' },
    { key: 'loading_lines', label: 'تعداد لاین بارگیری' },
    { key: 'avg_loading_minutes', label: 'زمان متوسط بارگیری (دقیقه)', hint: 'وقتی داده‌ی واقعی نباشد برای تخمین استفاده می‌شود' },
    { key: 'booking_horizon_days', label: 'افق نوبت‌دهی (روز)', hint: 'راننده تا چند روز آینده می‌تواند نوبت بگیرد' },
    { key: 'booking_lead_minutes', label: 'حداقل فاصله تا نوبت (دقیقه)' },
    { key: 'max_active_per_mobile', label: 'حداکثر نوبت فعال هر موبایل' },
    { key: 'max_active_per_plate', label: 'حداکثر نوبت فعال هر پلاک' },
];

const totalWeeklyCapacity = computed(() =>
    form.working_hours.reduce((sum, day) => {
        if (!day.is_open || form.slot_minutes < 1) return sum;

        const [oh, om] = day.opens_at.split(':').map(Number);
        const [ch, cm] = day.closes_at.split(':').map(Number);
        const minutes = ch * 60 + cm - (oh * 60 + om);

        if (minutes <= 0) return sum;

        const slots = Math.floor(minutes / form.slot_minutes);

        return sum + Math.min(slots * day.capacity_per_slot, form.daily_capacity);
    }, 0),
);

function submit() {
    form.put(route('staff.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <StaffLayout title="تنظیمات نوبت‌دهی">
        <form class="mx-auto max-w-4xl space-y-5" @submit.prevent="submit">
            <div>
                <h1 class="text-lg font-bold text-slate-900">تنظیمات نوبت‌دهی</h1>
                <p class="mt-0.5 text-sm text-slate-500">
                    تغییر این مقادیر بلافاصله روی اسلات‌های آینده اعمال می‌شود.
                </p>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>

            <section class="card p-5">
                <h2 class="mb-4 text-sm font-semibold text-slate-700">ظرفیت و قوانین</h2>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField
                        v-for="field in NUMBERS"
                        :key="field.key"
                        :label="field.label"
                        :for="field.key"
                        :error="form.errors[field.key]"
                        :hint="field.hint"
                    >
                        <input
                            :id="field.key"
                            v-model.number="form[field.key]"
                            type="number"
                            min="0"
                            class="num block w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-base shadow-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100"
                        />
                    </FormField>
                </div>
            </section>

            <section class="card overflow-hidden">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                    <h2 class="text-sm font-semibold text-slate-700">ساعات کاری هفته</h2>
                    <p class="text-xs text-slate-500">
                        ظرفیت تقریبی هفته:
                        <span class="num font-semibold text-slate-700">{{ totalWeeklyCapacity }}</span>
                        کامیون
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">روز</th>
                                <th class="px-5 py-2 text-start font-medium">باز</th>
                                <th class="px-5 py-2 text-start font-medium">شروع</th>
                                <th class="px-5 py-2 text-start font-medium">پایان</th>
                                <th class="px-5 py-2 text-start font-medium">ظرفیت هر اسلات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(day, index) in form.working_hours" :key="day.weekday">
                                <td class="px-5 py-2.5 font-medium text-slate-800">{{ day.name }}</td>
                                <td class="px-5 py-2.5">
                                    <input
                                        v-model="day.is_open"
                                        type="checkbox"
                                        :aria-label="`روز ${day.name} باز است`"
                                        class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                    />
                                </td>
                                <td class="px-5 py-2.5">
                                    <input
                                        v-model="day.opens_at"
                                        type="time"
                                        :disabled="!day.is_open"
                                        dir="ltr"
                                        class="num rounded-lg border border-slate-300 px-2 py-1.5 disabled:bg-slate-50 disabled:text-slate-400"
                                    />
                                </td>
                                <td class="px-5 py-2.5">
                                    <input
                                        v-model="day.closes_at"
                                        type="time"
                                        :disabled="!day.is_open"
                                        dir="ltr"
                                        class="num rounded-lg border border-slate-300 px-2 py-1.5 disabled:bg-slate-50 disabled:text-slate-400"
                                    />
                                    <p
                                        v-if="form.errors[`working_hours.${index}.closes_at` as keyof typeof form.errors]"
                                        class="mt-1 text-xs text-rose-600"
                                    >
                                        {{ form.errors[`working_hours.${index}.closes_at` as keyof typeof form.errors] }}
                                    </p>
                                </td>
                                <td class="px-5 py-2.5">
                                    <input
                                        v-model.number="day.capacity_per_slot"
                                        type="number"
                                        min="0"
                                        :disabled="!day.is_open"
                                        class="num w-20 rounded-lg border border-slate-300 px-2 py-1.5 disabled:bg-slate-50 disabled:text-slate-400"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <AlertBox tone="info">
                کم‌کردن ظرفیت، نوبت‌های ثبت‌شده را لغو نمی‌کند. ظرفیت هیچ ساعتی زیر تعداد
                نوبت‌های رزروشده‌اش نمی‌آید.
            </AlertBox>

            <div class="flex justify-start">
                <AppButton type="submit" :loading="form.processing">ذخیره تنظیمات</AppButton>
            </div>
        </form>
    </StaffLayout>
</template>
