<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import FormField from '@/components/FormField.vue';
import SelectCard from '@/components/SelectCard.vue';
import TextInput from '@/components/TextInput.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface AudienceOption {
    value: string;
    label: string;
    description: string;
}

interface SentRow {
    title: string;
    body: string;
    sender: string | null;
    recipients: number;
    delivered: number;
    read: number;
    sent_at: string;
}

const props = defineProps<{
    audiences: AudienceOption[];
    reach: Record<string, number>;
    sent: SentRow[];
    apps: { installed: number; today: number; last: string | null };
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const form = useForm({
    audience: 'today',
    mobile: '',
    title: '',
    body: '',
    path: '',
});

const needsMobile = computed(() => form.audience === 'one');

// چند نفر این پیام را می‌گیرند — قبل از زدن دکمه، نه بعدش
const recipients = computed(() =>
    needsMobile.value ? (form.mobile ? 1 : 0) : (props.reach[form.audience] ?? 0),
);

const remaining = computed(() => 1000 - form.body.length);

function submit() {
    form.post(route('staff.notifications.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('title', 'body', 'path', 'mobile'),
    });
}

function fa(n: number): string {
    return n.toLocaleString('fa-IR');
}
</script>

<template>
    <StaffLayout title="اعلان به راننده‌ها">
        <div class="mx-auto max-w-3xl space-y-6">
            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.warning" tone="warning">{{ flash.warning }}</AlertBox>

            <!--
                بدون این جعبه، «چرا اعلان نرسید؟» جوابی ندارد: معلوم نیست
                سرور چیزی نساخته یا هیچ گوشی‌ای سراغش نیامده.
            -->
            <AlertBox :tone="apps.installed === 0 ? 'warning' : 'info'">
                <template v-if="apps.installed === 0">
                    <strong>هیچ راننده‌ای هنوز برنامه را باز نکرده.</strong>
                    اعلان فقط روی برنامه‌ی اندروید دیده می‌شود — تا راننده نصبش نکند و
                    یک بار واردش نشود، اعلانی به دستش نمی‌رسد. پیامک جای خودش هست.
                </template>
                <template v-else>
                    <strong>{{ fa(apps.installed) }} راننده</strong> برنامه را دارند،
                    <strong>{{ fa(apps.today) }}</strong> نفرشان در ۲۴ ساعت گذشته آنلاین
                    بوده‌اند. اعلان برای همین‌ها فوری می‌رسد؛ بقیه وقتی برنامه را باز
                    کنند آن را می‌بینند.
                </template>
            </AlertBox>

            <form class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="submit">
                <div class="space-y-3">
                    <span class="block text-sm font-medium text-slate-700">گیرندگان</span>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <SelectCard
                            v-for="option in audiences"
                            :key="option.value"
                            :selected="form.audience === option.value"
                            @click="form.audience = option.value"
                        >
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="font-medium text-slate-900">{{ option.label }}</span>
                                <span v-if="option.value !== 'one'" class="text-xs tabular-nums text-slate-500">
                                    {{ fa(reach[option.value] ?? 0) }} نفر
                                </span>
                            </span>
                            <span class="mt-1 block text-xs leading-relaxed text-slate-500">
                                {{ option.description }}
                            </span>
                        </SelectCard>
                    </div>

                    <p v-if="form.errors.audience" class="text-sm text-rose-600">{{ form.errors.audience }}</p>
                </div>

                <FormField v-if="needsMobile" label="شماره موبایل راننده" :error="form.errors.mobile" for="mobile">
                    <TextInput id="mobile" v-model="form.mobile" inputmode="numeric" dir="ltr" placeholder="09121234567" />
                </FormField>

                <FormField label="عنوان" :error="form.errors.title" for="title">
                    <TextInput id="title" v-model="form.title" :maxlength="120" placeholder="مثلا: تعطیلی فردا" />
                </FormField>

                <FormField label="متن" :error="form.errors.body" for="body">
                    <textarea
                        id="body"
                        v-model="form.body"
                        rows="4"
                        maxlength="1000"
                        placeholder="متن اعلانی که روی گوشی راننده دیده می‌شود"
                        class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm leading-relaxed focus:border-brand-500 focus:ring-1 focus:ring-brand-500"
                    ></textarea>
                    <p class="text-start text-xs text-slate-400">{{ fa(remaining) }} نویسه باقی مانده</p>
                </FormField>

                <FormField
                    label="مسیر (اختیاری)"
                    :error="form.errors.path"
                    hint="با کلیک روی اعلان، این صفحه باز می‌شود. مثلا /queue — آدرس کامل پذیرفته نمی‌شود."
                    for="path"
                >
                    <TextInput id="path" v-model="form.path" dir="ltr" placeholder="/queue" />
                </FormField>

                <div class="flex items-center justify-between gap-3 border-t border-slate-100 pt-4">
                    <p class="text-sm text-slate-600">
                        <template v-if="recipients > 0">
                            برای <strong class="tabular-nums">{{ fa(recipients) }}</strong> راننده ارسال می‌شود
                        </template>
                        <template v-else-if="needsMobile">شماره را وارد کنید</template>
                        <template v-else>در این گروه راننده‌ای نیست</template>
                    </p>

                    <AppButton type="submit" :disabled="form.processing || recipients === 0">
                        {{ form.processing ? 'در حال ثبت…' : 'ارسال اعلان' }}
                    </AppButton>
                </div>
            </form>

            <section v-if="sent.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-slate-900">اعلان‌های اخیر</h2>

                <ul class="divide-y divide-slate-100">
                    <li v-for="(row, i) in sent" :key="i" class="py-3 first:pt-0 last:pb-0">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="font-medium text-slate-900">{{ row.title }}</p>
                            <p class="shrink-0 text-xs text-slate-400">{{ row.sent_at }}</p>
                        </div>

                        <p class="mt-1 line-clamp-2 text-sm text-slate-600">{{ row.body }}</p>

                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                            <span>{{ fa(row.recipients) }} گیرنده</span>
                            <span>{{ fa(row.delivered) }} رسیده</span>
                            <span>{{ fa(row.read) }} خوانده‌شده</span>
                            <span v-if="row.sender">فرستنده: {{ row.sender }}</span>
                        </p>
                    </li>
                </ul>
            </section>
        </div>
    </StaffLayout>
</template>
