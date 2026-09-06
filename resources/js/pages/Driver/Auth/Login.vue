<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import DriverLayout from '@/layouts/DriverLayout.vue';
import { digitsOnly } from '@/lib/format';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const form = useForm({ mobile: '' });

// راننده روی موبایل با کیبورد فارسی تایپ می‌کند؛ همان‌جا لاتین می‌کنیم
watch(
    () => form.mobile,
    (value) => {
        const cleaned = digitsOnly(value).slice(0, 11);
        if (cleaned !== value) form.mobile = cleaned;
    },
);

const isComplete = computed(() => /^09\d{9}$/.test(form.mobile));

function submit() {
    form.post(route('driver.otp.request'), { preserveScroll: true });
}
</script>

<template>
    <DriverLayout title="ورود">
        <div class="space-y-6">
            <div class="text-center">
                <h1 class="text-xl font-bold text-slate-900">نوبت بارگیری</h1>
                <p class="mt-2 text-sm text-slate-500">
                    شماره موبایل خود را وارد کنید تا کد ورود برایتان ارسال شود.
                </p>
            </div>

            <form class="card space-y-5 p-5" @submit.prevent="submit">
                <FormField
                    label="شماره موبایل"
                    for="mobile"
                    :error="form.errors.mobile"
                    hint="۱۱ رقم، با ۰۹ شروع می‌شود"
                >
                    <TextInput
                        id="mobile"
                        v-model="form.mobile"
                        type="tel"
                        inputmode="numeric"
                        dir="ltr"
                        
                        :maxlength="11"
                        :invalid="!!form.errors.mobile"
                        autofocus
                        class="num w-full text-center text-lg tracking-widest"
                    />
                </FormField>

                <AppButton
                    type="submit"
                    size="lg"
                    :loading="form.processing"
                    :disabled="!isComplete"
                >
                    دریافت کد
                </AppButton>
            </form>

            <AlertBox tone="info">
                نوبت‌گیری فقط با شماره موبایل انجام می‌شود؛ نیازی به نام کاربری و رمز عبور نیست.
            </AlertBox>
        </div>
    </DriverLayout>
</template>
