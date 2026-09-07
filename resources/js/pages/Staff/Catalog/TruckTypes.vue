<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface TruckType {
    id: number;
    name: string;
    code: string;
    capacity_tons: string | null;
    loading_minutes: number | null;
    grace_minutes: number | null;
    sort_order: number;
    is_active: boolean;
    trucks_count: number;
}

const props = defineProps<{
    truckTypes: TruckType[];
    defaults: { loading_minutes: number; grace_minutes: number };
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const editingId = ref<number | null>(null);
const open = ref(false);

const form = useForm({
    name: '',
    code: '',
    capacity_tons: '',
    loading_minutes: '',
    grace_minutes: '',
    sort_order: '0',
    is_active: true,
});

const title = computed(() => (editingId.value === null ? 'افزودن نوع کامیون' : 'ویرایش نوع کامیون'));

function startCreate() {
    form.reset();
    form.clearErrors();
    form.sort_order = String((props.truckTypes.at(-1)?.sort_order ?? 0) + 1);
    editingId.value = null;
    open.value = true;
}

function startEdit(type: TruckType) {
    form.clearErrors();
    form.name = type.name;
    form.code = type.code;
    form.capacity_tons = type.capacity_tons ?? '';
    form.loading_minutes = type.loading_minutes === null ? '' : String(type.loading_minutes);
    form.grace_minutes = type.grace_minutes === null ? '' : String(type.grace_minutes);
    form.sort_order = String(type.sort_order);
    form.is_active = type.is_active;
    editingId.value = type.id;
    open.value = true;
}

function close() {
    open.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

// خالی یعنی «تعریف نشده» و سامانه سراغ پیش‌فرض کارخانه می‌رود
const payload = () => ({
    ...form.data(),
    capacity_tons: form.capacity_tons.trim() === '' ? null : form.capacity_tons.trim(),
    loading_minutes: form.loading_minutes.trim() === '' ? null : Number(form.loading_minutes),
    grace_minutes: form.grace_minutes.trim() === '' ? null : Number(form.grace_minutes),
    sort_order: Number(form.sort_order || 0),
});

function submit() {
    const options = { preserveScroll: true, onSuccess: close };

    if (editingId.value === null) {
        form.transform(payload).post(route('staff.truck-types.store'), options);
    } else {
        form.transform(payload).put(route('staff.truck-types.update', editingId.value), options);
    }
}

const removing = ref<number | null>(null);

function remove(type: TruckType) {
    if (!confirm(`نوع کامیون «${type.name}» حذف شود؟`)) return;

    removing.value = type.id;

    router.delete(route('staff.truck-types.destroy', type.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}
</script>

<template>
    <StaffLayout title="انواع کامیون">
        <div class="mx-auto max-w-5xl space-y-5 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-slate-800">انواع کامیون</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        مدت بارگیری هر نوع، تخمین صف را می‌سازد؛ مهلت حضور، ظرفیت نوبت‌هایی که راننده‌شان نیامده را آزاد می‌کند.
                    </p>
                </div>
                <AppButton @click="startCreate">افزودن نوع کامیون</AppButton>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <AlertBox tone="info">
                خالی گذاشتن هر یک از دو ستون زمانی یعنی «از پیش‌فرض کارخانه استفاده کن»:
                بارگیری <span class="num font-semibold">{{ defaults.loading_minutes }}</span> دقیقه و
                مهلت حضور <span class="num font-semibold">{{ defaults.grace_minutes }}</span> دقیقه.
                این پیش‌فرض‌ها در صفحه‌ی تنظیمات نوبت‌دهی تغییر می‌کنند.
            </AlertBox>

            <form v-if="open" class="card space-y-5 p-5" @submit.prevent="submit">
                <h2 class="text-sm font-semibold text-slate-700">{{ title }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="نام" for="name" :error="form.errors.name">
                        <TextInput id="name" v-model="form.name" placeholder="مثلاً تریلی" :invalid="!!form.errors.name" />
                    </FormField>

                    <FormField label="کد" for="code" :error="form.errors.code" hint="فقط حرف انگلیسی و رقم">
                        <TextInput id="code" v-model="form.code" dir="ltr" placeholder="TRAILER" :invalid="!!form.errors.code" />
                    </FormField>

                    <FormField label="ظرفیت (تن)" for="capacity_tons" :error="form.errors.capacity_tons" hint="اختیاری">
                        <TextInput id="capacity_tons" v-model="form.capacity_tons" inputmode="numeric" dir="ltr" placeholder="24" :invalid="!!form.errors.capacity_tons" />
                    </FormField>

                    <FormField label="ترتیب نمایش" for="sort_order" :error="form.errors.sort_order">
                        <TextInput id="sort_order" v-model="form.sort_order" inputmode="numeric" dir="ltr" :invalid="!!form.errors.sort_order" />
                    </FormField>

                    <FormField
                        label="مدت بارگیری (دقیقه)"
                        for="loading_minutes"
                        :error="form.errors.loading_minutes"
                        hint="مبنای «تخمین زمان انتظار» که راننده می‌بیند"
                    >
                        <TextInput id="loading_minutes" v-model="form.loading_minutes" inputmode="numeric" dir="ltr" :placeholder="String(defaults.loading_minutes)" :invalid="!!form.errors.loading_minutes" />
                    </FormField>

                    <FormField
                        label="مهلت حضور بعد از ساعت نوبت (دقیقه)"
                        for="grace_minutes"
                        :error="form.errors.grace_minutes"
                        hint="بعد از این مدت، سامانه خودکار «عدم حضور» ثبت و ظرفیت را آزاد می‌کند"
                    >
                        <TextInput id="grace_minutes" v-model="form.grace_minutes" inputmode="numeric" dir="ltr" :placeholder="String(defaults.grace_minutes)" :invalid="!!form.errors.grace_minutes" />
                    </FormField>
                </div>

                <label class="flex items-center gap-2.5">
                    <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                    <span class="text-sm font-medium text-slate-800">فعال باشد و به راننده نشان داده شود</span>
                </label>

                <div class="flex gap-3">
                    <AppButton type="submit" :loading="form.processing">ذخیره</AppButton>
                    <AppButton variant="ghost" @click="close">انصراف</AppButton>
                </div>
            </form>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-3 font-medium">نوع</th>
                                <th class="px-4 py-3 font-medium">کد</th>
                                <th class="px-4 py-3 font-medium">ظرفیت</th>
                                <th class="px-4 py-3 font-medium">مدت بارگیری</th>
                                <th class="px-4 py-3 font-medium">مهلت حضور</th>
                                <th class="px-4 py-3 font-medium">وضعیت</th>
                                <th class="px-4 py-3 font-medium">کامیون‌ها</th>
                                <th class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="type in truckTypes" :key="type.id" class="hover:bg-slate-50/60">
                                <td class="px-4 py-3 font-medium text-slate-800">{{ type.name }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">{{ type.code }}</td>
                                <td class="num px-4 py-3 text-slate-700">{{ type.capacity_tons ?? '—' }}</td>
                                <td class="num px-4 py-3 text-slate-700">
                                    <span v-if="type.loading_minutes">{{ type.loading_minutes }} دقیقه</span>
                                    <span v-else class="text-slate-400">پیش‌فرض</span>
                                </td>
                                <td class="num px-4 py-3 text-slate-700">
                                    <span v-if="type.grace_minutes">{{ type.grace_minutes }} دقیقه</span>
                                    <span v-else class="text-slate-400">پیش‌فرض</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            type.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                                        ]"
                                    >
                                        {{ type.is_active ? 'فعال' : 'غیرفعال' }}
                                    </span>
                                </td>
                                <td class="num px-4 py-3 text-slate-500">{{ type.trucks_count }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1.5">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-100"
                                            @click="startEdit(type)"
                                        >
                                            ویرایش
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="type.trucks_count > 0 || removing === type.id"
                                            :title="type.trucks_count > 0 ? 'به کامیون‌های ثبت‌شده وصل است' : ''"
                                            class="rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="remove(type)"
                                        >
                                            حذف
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!truckTypes.length">
                                <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-400">
                                    هنوز نوع کامیونی ثبت نشده است.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </StaffLayout>
</template>
