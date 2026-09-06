<script setup lang="ts">
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post(route('staff.login.store'), { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="ورود کارکنان" />

    <div class="grid min-h-dvh place-items-center bg-slate-100 px-5 py-10">
        <div class="w-full max-w-sm space-y-6">
            <div class="text-center">
                <span
                    class="mx-auto mb-4 flex size-12 items-center justify-center rounded-2xl bg-brand-600 text-white"
                    aria-hidden="true"
                >
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M3 7h11v9H3z" stroke-linejoin="round" />
                        <path d="M14 10h4l3 3v3h-7z" stroke-linejoin="round" />
                        <circle cx="7" cy="18" r="1.8" />
                        <circle cx="17" cy="18" r="1.8" />
                    </svg>
                </span>
                <h1 class="text-lg font-bold text-slate-900">ورود کارکنان</h1>
                <p class="mt-1 text-sm text-slate-500">سامانه مدیریت نوبت بارگیری</p>
            </div>

            <form class="card space-y-5 p-6" @submit.prevent="submit">
                <FormField label="ایمیل" for="email" :error="form.errors.email">
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        dir="ltr"
                        :invalid="!!form.errors.email"
                        autofocus
                    />
                </FormField>

                <FormField label="رمز عبور" for="password" :error="form.errors.password">
                    <TextInput
                        id="password"
                        v-model="form.password"
                        type="password"
                        dir="ltr"
                        :invalid="!!form.errors.password"
                    />
                </FormField>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    مرا به خاطر بسپار
                </label>

                <AppButton type="submit" size="lg" :loading="form.processing">ورود</AppButton>
            </form>
        </div>
    </div>
</template>
