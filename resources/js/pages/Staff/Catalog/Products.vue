<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Product {
    id: number;
    name: string;
    code: string;
    description: string | null;
    load_tons: string | null;
    loading_minutes: number | null;
    sort_order: number;
    is_active: boolean;
    appointments_count: number;
}

const props = defineProps<{
    products: Product[];
    defaultLoadingMinutes: number;
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

// یک فرم برای هم افزودن و هم ویرایش: خطاها و وضعیت ارسال یک‌جا می‌مانند
const editingId = ref<number | null>(null);
const open = ref(false);

const form = useForm({
    name: '',
    code: '',
    description: '',
    load_tons: '',
    loading_minutes: '',
    sort_order: '0',
    is_active: true,
});

const title = computed(() => (editingId.value === null ? 'افزودن محصول' : 'ویرایش محصول'));

function startCreate() {
    form.reset();
    form.clearErrors();
    form.sort_order = String((props.products.at(-1)?.sort_order ?? 0) + 1);
    editingId.value = null;
    open.value = true;
}

function startEdit(product: Product) {
    form.clearErrors();
    form.name = product.name;
    form.code = product.code;
    form.description = product.description ?? '';
    form.load_tons = product.load_tons ?? '';
    form.loading_minutes = product.loading_minutes === null ? '' : String(product.loading_minutes);
    form.sort_order = String(product.sort_order);
    form.is_active = product.is_active;
    editingId.value = product.id;
    open.value = true;
}

function close() {
    open.value = false;
    editingId.value = null;
    form.reset();
    form.clearErrors();
}

// رشته‌ی خالی یعنی «مقدار ندارد»، نه صفر — سرور nullable انتظار دارد
const payload = () => ({
    ...form.data(),
    description: form.description.trim() === '' ? null : form.description.trim(),
    load_tons: form.load_tons.trim() === '' ? null : form.load_tons.trim(),
    loading_minutes: form.loading_minutes.trim() === '' ? null : Number(form.loading_minutes),
    sort_order: Number(form.sort_order || 0),
});

function submit() {
    const options = { preserveScroll: true, onSuccess: close };

    if (editingId.value === null) {
        form.transform(payload).post(route('staff.products.store'), options);
    } else {
        form.transform(payload).put(route('staff.products.update', editingId.value), options);
    }
}

const removing = ref<number | null>(null);

function remove(product: Product) {
    if (!confirm(`محصول «${product.name}» حذف شود؟`)) return;

    removing.value = product.id;

    router.delete(route('staff.products.destroy', product.id), {
        preserveScroll: true,
        onFinish: () => (removing.value = null),
    });
}
</script>

<template>
    <StaffLayout title="محصولات">
        <div class="mx-auto max-w-5xl space-y-5 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-semibold text-slate-800">محصولات</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        همین فهرست در مرحله‌ی «نوع بار» به راننده نشان داده می‌شود.
                    </p>
                </div>
                <AppButton @click="startCreate">افزودن محصول</AppButton>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <form v-if="open" class="card space-y-5 p-5" @submit.prevent="submit">
                <h2 class="text-sm font-semibold text-slate-700">{{ title }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField label="نام محصول" for="name" :error="form.errors.name">
                        <TextInput id="name" v-model="form.name" placeholder="مثلاً سیمان تیپ ۲" :invalid="!!form.errors.name" />
                    </FormField>

                    <FormField label="کد محصول" for="code" :error="form.errors.code" hint="فقط حرف انگلیسی و رقم">
                        <TextInput id="code" v-model="form.code" dir="ltr" placeholder="CEM2" :invalid="!!form.errors.code" />
                    </FormField>

                    <FormField label="تناژ هر بارگیری" for="load_tons" :error="form.errors.load_tons" hint="اختیاری">
                        <TextInput id="load_tons" v-model="form.load_tons" inputmode="numeric" dir="ltr" placeholder="30" :invalid="!!form.errors.load_tons" />
                    </FormField>

                    <FormField
                        label="مدت بارگیری (دقیقه)"
                        for="loading_minutes"
                        :error="form.errors.loading_minutes"
                        :hint="`خالی بماند یعنی ${defaultLoadingMinutes} دقیقه‌ی پیش‌فرض کارخانه`"
                    >
                        <TextInput id="loading_minutes" v-model="form.loading_minutes" inputmode="numeric" dir="ltr" placeholder="25" :invalid="!!form.errors.loading_minutes" />
                    </FormField>

                    <FormField label="ترتیب نمایش" for="sort_order" :error="form.errors.sort_order">
                        <TextInput id="sort_order" v-model="form.sort_order" inputmode="numeric" dir="ltr" :invalid="!!form.errors.sort_order" />
                    </FormField>

                    <FormField label="توضیح کوتاه" for="description" :error="form.errors.description" hint="اختیاری — زیر نام محصول به راننده نشان داده می‌شود">
                        <TextInput id="description" v-model="form.description" placeholder="کیسه‌ای ۵۰ کیلویی" :invalid="!!form.errors.description" />
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
                                <th class="px-4 py-3 font-medium">محصول</th>
                                <th class="px-4 py-3 font-medium">کد</th>
                                <th class="px-4 py-3 font-medium">تناژ</th>
                                <th class="px-4 py-3 font-medium">مدت بارگیری</th>
                                <th class="px-4 py-3 font-medium">وضعیت</th>
                                <th class="px-4 py-3 font-medium">نوبت‌ها</th>
                                <th class="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="product in products" :key="product.id" class="hover:bg-slate-50/60">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-800">{{ product.name }}</p>
                                    <p v-if="product.description" class="text-xs text-slate-400">{{ product.description }}</p>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-slate-500" dir="ltr">{{ product.code }}</td>
                                <td class="num px-4 py-3 text-slate-700">{{ product.load_tons ?? '—' }}</td>
                                <td class="num px-4 py-3 text-slate-700">
                                    <span v-if="product.loading_minutes">{{ product.loading_minutes }} دقیقه</span>
                                    <span v-else class="text-slate-400">پیش‌فرض</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="[
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            product.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                                        ]"
                                    >
                                        {{ product.is_active ? 'فعال' : 'غیرفعال' }}
                                    </span>
                                </td>
                                <td class="num px-4 py-3 text-slate-500">{{ product.appointments_count }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-1.5">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-brand-200 bg-brand-50 px-2.5 py-1 text-xs font-medium text-brand-700 transition hover:bg-brand-100"
                                            @click="startEdit(product)"
                                        >
                                            ویرایش
                                        </button>
                                        <button
                                            type="button"
                                            :disabled="product.appointments_count > 0 || removing === product.id"
                                            :title="product.appointments_count > 0 ? 'در نوبت‌های ثبت‌شده به کار رفته است' : ''"
                                            class="rounded-lg border border-rose-200 px-2.5 py-1 text-xs font-medium text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-40"
                                            @click="remove(product)"
                                        >
                                            حذف
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!products.length">
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-400">
                                    هنوز محصولی ثبت نشده است.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </StaffLayout>
</template>
