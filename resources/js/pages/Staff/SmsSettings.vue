<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ProbeRow {
    url: string;
    host: string;
    code: number;
    ok: boolean;
    body: string;
}

const props = defineProps<{
    settings: Record<string, string | boolean>;
    providers: { key: string; label: string }[];
    recent: {
        id: number;
        to: string;
        template: string | null;
        status: string;
        provider: string | null;
        error: string | null;
        attempts: number;
        at: string | null;
    }[];
}>();

const page = usePage<PageProps & { flash: { probe?: { results: ProbeRow[]; suggest: string | null } | null } }>();
const flash = computed(() => page.props.flash);
const probe = computed(() => flash.value.probe ?? null);

const form = useForm({
    sms_enabled: String(props.settings.sms_enabled) === '1',
    sms_provider: String(props.settings.sms_provider ?? 'afe'),
    sms_sender: String(props.settings.sms_sender ?? ''),
    sms_username: String(props.settings.sms_username ?? ''),
    sms_password: '',
    sms_api_key: '',
    sms_afe_domain: String(props.settings.sms_afe_domain ?? ''),
    sms_custom_url: String(props.settings.sms_custom_url ?? ''),
    sms_custom_method: String(props.settings.sms_custom_method ?? 'GET'),
    sms_optout: String(props.settings.sms_optout ?? ''),
    sms_manager_recipients: String(props.settings.sms_manager_recipients ?? ''),
    sms_weight_alert_recipients: String(props.settings.sms_weight_alert_recipients ?? ''),
});

const testMobile = ref('');
const busy = ref<'test' | 'probe' | null>(null);

const passwordIsSet = computed(() => props.settings.sms_password_is_set === true);
const apiKeyIsSet = computed(() => props.settings.sms_api_key_is_set === true);

// هر پنل فقط فیلدهای خودش را می‌خواهد؛ بقیه فقط شلوغی است
const needs = computed(() => {
    const p = form.sms_provider;

    return {
        userPass: p === 'afe' || p === 'melipayamak' || p === 'custom',
        apiKey: p === 'kavenegar' || p === 'smsir' || p === 'custom',
        sender: p !== 'console' && p !== 'custom',
        afeDomain: p === 'afe',
        customUrl: p === 'custom',
    };
});

const statusTone: Record<string, string> = {
    SENT: 'text-emerald-600',
    QUEUED: 'text-slate-500',
    FAILED: 'text-rose-600',
    DISABLED: 'text-amber-600',
};

const statusLabel: Record<string, string> = {
    SENT: 'ارسال شد',
    QUEUED: 'در صف',
    FAILED: 'ناموفق',
    DISABLED: 'غیرفعال',
};

function submit() {
    form.put(route('staff.settings.sms.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('sms_password', 'sms_api_key'),
    });
}

function sendTest() {
    busy.value = 'test';
    router.post(
        route('staff.settings.sms.test'),
        { mobile: testMobile.value },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function runProbe() {
    busy.value = 'probe';
    router.post(
        route('staff.settings.sms.probe'),
        { mobile: testMobile.value },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function useSuggestedDomain() {
    if (probe.value?.suggest) {
        form.sms_afe_domain = probe.value.suggest;
    }
}
</script>

<template>
    <StaffLayout title="تنظیمات پیامک">
        <div class="mx-auto max-w-4xl space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-lg font-bold text-slate-900">تنظیمات پنل پیامک</h1>
                    <p class="mt-0.5 text-sm text-slate-500">
                        این تنظیمات در دیتابیس ذخیره می‌شود و بلافاصله اثر می‌کند.
                    </p>
                </div>

                <Link :href="route('staff.settings.edit')" class="text-sm text-slate-500 transition hover:text-slate-700">
                    تنظیمات نوبت‌دهی ←
                </Link>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <form class="card space-y-5 p-5" @submit.prevent="submit">
                <label class="flex items-center gap-2.5">
                    <input
                        v-model="form.sms_enabled"
                        type="checkbox"
                        class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                    />
                    <span class="text-sm font-medium text-slate-800">ارسال پیامک فعال باشد</span>
                </label>

                <FormField label="سرویس‌دهنده" for="sms_provider" :error="form.errors.sms_provider">
                    <select
                        id="sms_provider"
                        v-model="form.sms_provider"
                        class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base shadow-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100"
                    >
                        <option v-for="p in providers" :key="p.key" :value="p.key">{{ p.label }}</option>
                    </select>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        v-if="needs.userPass"
                        label="نام کاربری پنل"
                        for="sms_username"
                        :error="form.errors.sms_username"
                    >
                        <TextInput id="sms_username" v-model="form.sms_username" dir="ltr" />
                    </FormField>

                    <FormField
                        v-if="needs.userPass"
                        label="رمز پنل"
                        for="sms_password"
                        :error="form.errors.sms_password"
                        :hint="passwordIsSet ? 'ثبت شده — برای تغییر، مقدار جدید وارد کنید' : 'هنوز ثبت نشده'"
                    >
                        <TextInput id="sms_password" v-model="form.sms_password" type="password" dir="ltr" />
                    </FormField>

                    <FormField
                        v-if="needs.apiKey"
                        label="کلید API"
                        for="sms_api_key"
                        :error="form.errors.sms_api_key"
                        :hint="apiKeyIsSet ? 'ثبت شده — برای تغییر، مقدار جدید وارد کنید' : 'هنوز ثبت نشده'"
                    >
                        <TextInput id="sms_api_key" v-model="form.sms_api_key" type="password" dir="ltr" />
                    </FormField>

                    <FormField
                        v-if="needs.sender"
                        label="شماره اختصاصی (خط ارسال)"
                        for="sms_sender"
                        :error="form.errors.sms_sender"
                    >
                        <TextInput id="sms_sender" v-model="form.sms_sender" dir="ltr" class="num" />
                    </FormField>

                    <FormField
                        v-if="needs.afeDomain"
                        label="دامنه پنل (اختیاری)"
                        for="sms_afe_domain"
                        :error="form.errors.sms_afe_domain"
                        hint="اگر نمایندگی است، مثلاً panel.example.ir — خالی یعنی afe.ir و wide.ir"
                    >
                        <TextInput id="sms_afe_domain" v-model="form.sms_afe_domain" dir="ltr" />
                    </FormField>
                </div>

                <template v-if="needs.customUrl">
                    <FormField
                        label="لینک ارسال پنل سفارشی"
                        for="sms_custom_url"
                        :error="form.errors.sms_custom_url"
                        hint="متغیرها: {to} {text} {from} {username} {password} {apikey}"
                    >
                        <TextInput
                            id="sms_custom_url"
                            v-model="form.sms_custom_url"
                            dir="ltr"
                            placeholder="https://panel.example.com/send?to={to}&text={text}"
                        />
                    </FormField>

                    <FormField label="روش ارسال" for="sms_custom_method">
                        <select
                            id="sms_custom_method"
                            v-model="form.sms_custom_method"
                            class="block w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-base shadow-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100"
                        >
                            <option value="GET">GET</option>
                            <option value="POST">POST</option>
                        </select>
                    </FormField>
                </template>

                <div class="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
                    <FormField
                        label="خط لغو"
                        for="sms_optout"
                        :error="form.errors.sms_optout"
                        hint="به انتهای هر پیامک اضافه می‌شود. مثلاً: لغو ۱۱"
                    >
                        <TextInput id="sms_optout" v-model="form.sms_optout" :maxlength="40" />
                    </FormField>

                    <FormField
                        label="شماره مدیران"
                        for="sms_manager_recipients"
                        :error="form.errors.sms_manager_recipients"
                        hint="با کاما جدا کنید. پیام «نوبت جدید» به این شماره‌ها می‌رود."
                    >
                        <TextInput
                            id="sms_manager_recipients"
                            v-model="form.sms_manager_recipients"
                            dir="ltr"
                            class="num"
                            placeholder="09121112233,09121112244"
                        />
                    </FormField>

                    <FormField
                        label="شماره مدیرعامل — اخطار وزن"
                        for="sms_weight_alert_recipients"
                        :error="form.errors.sms_weight_alert_recipients"
                        hint="اضافه‌بار و مغایرت تناژ به این شماره‌ها خبر داده می‌شود. خالی بگذارید تا به همان «شماره مدیران» برود."
                    >
                        <TextInput
                            id="sms_weight_alert_recipients"
                            v-model="form.sms_weight_alert_recipients"
                            dir="ltr"
                            class="num"
                            placeholder="09121112255"
                        />
                    </FormField>
                </div>

                <AppButton type="submit" :loading="form.processing">ذخیره تنظیمات</AppButton>
            </form>

            <section class="card space-y-4 p-5">
                <div>
                    <h2 class="text-sm font-semibold text-slate-700">آزمایش ارسال</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        پیامک آزمایشی همین‌جا و بدون صف ارسال می‌شود تا پاسخ واقعی پنل را ببینید.
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-52 flex-1">
                        <FormField label="شماره گیرنده" for="test_mobile">
                            <TextInput
                                id="test_mobile"
                                v-model="testMobile"
                                type="tel"
                                inputmode="numeric"
                                dir="ltr"
                                class="num"
                                :maxlength="11"
                            />
                        </FormField>
                    </div>

                    <AppButton :loading="busy === 'test'" :disabled="testMobile.length < 11" @click="sendTest">
                        ارسال آزمایشی
                    </AppButton>

                    <AppButton
                        v-if="form.sms_provider === 'afe'"
                        variant="secondary"
                        :loading="busy === 'probe'"
                        :disabled="testMobile.length < 11"
                        @click="runProbe"
                    >
                        عیب‌یابی هوشمند
                    </AppButton>
                </div>

                <div v-if="probe" class="space-y-3 border-t border-slate-100 pt-4">
                    <AlertBox v-if="probe.suggest" tone="success">
                        آدرس درست پیدا شد:
                        <span class="font-mono font-semibold" dir="ltr">{{ probe.suggest }}</span>
                        <button
                            type="button"
                            class="ms-2 underline"
                            @click="useSuggestedDomain"
                        >
                            در فرم بگذار
                        </button>
                    </AlertBox>

                    <AlertBox v-else tone="warning">
                        هیچ‌کدام از آدرس‌ها ارسال موفق نداشتند. پاسخ‌ها را ببینید.
                    </AlertBox>

                    <ul class="space-y-2">
                        <li
                            v-for="row in probe.results"
                            :key="row.url"
                            :class="[
                                'rounded-xl border p-3 text-xs',
                                row.ok ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50',
                            ]"
                        >
                            <p class="font-mono font-medium text-slate-800" dir="ltr">{{ row.host }}</p>
                            <p class="num mt-1 text-slate-500">کد پاسخ: {{ row.code }}</p>
                            <p class="mt-1 break-all text-slate-600" dir="ltr">{{ row.body || '—' }}</p>
                        </li>
                    </ul>
                </div>
            </section>

            <section class="card overflow-hidden">
                <h2 class="border-b border-slate-100 px-5 py-3 text-sm font-semibold text-slate-700">
                    آخرین پیامک‌ها
                </h2>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-5 py-2 text-start font-medium">گیرنده</th>
                                <th class="px-5 py-2 text-start font-medium">نوع</th>
                                <th class="px-5 py-2 text-start font-medium">وضعیت</th>
                                <th class="px-5 py-2 text-start font-medium">زمان</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in recent" :key="row.id">
                                <td class="num px-5 py-2.5 text-slate-800">{{ row.to }}</td>
                                <td class="px-5 py-2.5 text-xs text-slate-500">{{ row.template ?? '—' }}</td>
                                <td class="px-5 py-2.5">
                                    <span :class="['font-medium', statusTone[row.status] ?? 'text-slate-600']">
                                        {{ statusLabel[row.status] ?? row.status }}
                                    </span>
                                    <p v-if="row.error" class="mt-0.5 text-xs text-rose-500">{{ row.error }}</p>
                                </td>
                                <td class="num px-5 py-2.5 text-xs text-slate-500">{{ row.at }}</td>
                            </tr>
                            <tr v-if="!recent.length">
                                <td colspan="4" class="px-5 py-10 text-center text-sm text-slate-500">
                                    هنوز پیامکی ارسال نشده است.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
