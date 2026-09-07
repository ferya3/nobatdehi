<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ forced: boolean }>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const strongEnough = computed(
    () => form.password.length >= 10 && /[a-zA-Z]/.test(form.password) && /\d/.test(form.password),
);

function submit() {
    form.put(route('staff.password.update'), {
        preserveScroll: true,
        onFinish: () => form.reset('current_password', 'password', 'password_confirmation'),
    });
}
</script>

<template>
    <StaffLayout title="تغییر رمز عبور">
        <div class="mx-auto max-w-md space-y-5 p-4">
            <div>
                <h1 class="text-lg font-semibold text-slate-800">تغییر رمز عبور</h1>
                <p v-if="forced" class="mt-1 text-sm text-slate-500">
                    این حساب هنوز رمز پیش‌فرض دارد. تا عوضش نکنید بقیه‌ی پنل باز نمی‌شود.
                </p>
            </div>

            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>
            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>

            <form class="card space-y-5 p-5" @submit.prevent="submit">
                <FormField label="رمز فعلی" for="current_password" :error="form.errors.current_password">
                    <TextInput
                        id="current_password"
                        v-model="form.current_password"
                        type="password"
                        dir="ltr"
                        :invalid="!!form.errors.current_password"
                    />
                </FormField>

                <FormField
                    label="رمز جدید"
                    for="password"
                    :error="form.errors.password"
                    hint="حداقل ۱۰ کاراکتر، شامل حرف و رقم"
                >
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        dir="ltr"
                        :invalid="!!form.errors.password"
                    />
                </FormField>

                <FormField label="تکرار رمز جدید" for="password_confirmation">
                    <TextInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        dir="ltr"
                    />
                </FormField>

                <AppButton
                    type="submit"
                    size="lg"
                    :loading="form.processing"
                    :disabled="!strongEnough || form.password !== form.password_confirmation"
                >
                    ثبت رمز جدید
                </AppButton>
            </form>
        </div>
    </StaffLayout>
</template>
