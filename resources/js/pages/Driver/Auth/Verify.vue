<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import OtpInput from '@/components/OtpInput.vue';
import DriverLayout from '@/layouts/DriverLayout.vue';
import { countdown, prettyMobile } from '@/lib/format';
import { useCountdown } from '@/lib/useCountdown';
import type { OtpFlash } from '@/types';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ otp: OtpFlash }>();

const form = useForm({ mobile: props.otp.mobile, code: '' });
const resending = ref(false);

const { remaining: resendIn, start: startResend } = useCountdown(props.otp.resend_in);
const { remaining: expiresIn } = useCountdown(props.otp.expires_in);

const canResend = computed(() => resendIn.value <= 0 && !resending.value);
const expired = computed(() => expiresIn.value <= 0);

function submit() {
    form.post(route('driver.otp.verify'), {
        preserveScroll: true,
        onError: () => (form.code = ''),
    });
}

function resend() {
    if (!canResend.value) return;

    resending.value = true;

    router.post(
        route('driver.otp.resend'),
        { mobile: props.otp.mobile },
        {
            preserveScroll: true,
            onFinish: () => {
                resending.value = false;
                startResend(props.otp.resend_in);
            },
        },
    );
}
</script>

<template>
    <DriverLayout title="تأیید شماره">
        <div class="space-y-6">
            <div class="text-center">
                <h1 class="text-xl font-bold text-slate-900">کد ارسال‌شده را وارد کنید</h1>
                <p class="mt-2 text-sm text-slate-500">
                    کد به شماره
                    <span class="num font-medium text-slate-700">{{ prettyMobile(otp.mobile) }}</span>
                    پیامک شد.
                </p>
            </div>

            <form class="card space-y-5 p-5" @submit.prevent="submit">
                <FormField :error="form.errors.code">
                    <OtpInput
                        v-model="form.code"
                        :length="5"
                        :invalid="!!form.errors.code"
                        @complete="submit"
                    />
                </FormField>

                <p v-if="!expired" class="text-center text-sm text-slate-500">
                    اعتبار کد: <span class="num">{{ countdown(expiresIn) }}</span>
                </p>
                <p v-else class="text-center text-sm text-amber-600">
                    کد منقضی شده است. لطفاً کد جدید بگیرید.
                </p>

                <AppButton type="submit" size="lg" :loading="form.processing" :disabled="form.code.length < 5">
                    ورود
                </AppButton>

                <div class="flex items-center justify-between border-t border-slate-100 pt-4 text-sm">
                    <button
                        type="button"
                        class="font-medium text-brand-700 transition hover:text-brand-800 disabled:text-slate-400"
                        :disabled="!canResend"
                        @click="resend"
                    >
                        <span v-if="canResend">ارسال مجدد کد</span>
                        <span v-else>ارسال مجدد <span class="num">{{ countdown(resendIn) }}</span></span>
                    </button>

                    <a :href="route('driver.login')" class="text-slate-500 transition hover:text-slate-700">
                        تغییر شماره
                    </a>
                </div>
            </form>

            <AlertBox v-if="otp.dev_code" tone="warning">
                حالت توسعه — کد: <span class="num font-mono font-bold">{{ otp.dev_code }}</span>
            </AlertBox>
        </div>
    </DriverLayout>
</template>
