<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import AppButton from '@/components/AppButton.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import type { PageProps } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Template {
    id: number;
    key: string;
    title: string;
    body: string;
    is_active: boolean;
    variables: { name: string; label: string }[];
    default_body: string | null;
}

const props = defineProps<{ templates: Template[] }>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

/** فرمِ هر قالب جداست: ذخیره‌ی یکی نباید ویرایشِ نیمه‌کاره‌ی دیگری را ببرد */
const forms = ref(
    Object.fromEntries(
        props.templates.map((t) => [t.id, useForm({ body: t.body, is_active: t.is_active })]),
    ),
);

const open = ref<number | null>(props.templates[0]?.id ?? null);

/**
 * شمارشِ پیامک، همان‌طور که پنل پیامکی حساب می‌کند.
 *
 * فارسی یعنی رمزگذاری UTF-16، پس هر پیامک ۷۰ نویسه است نه ۱۶۰. مدیری که
 * این را نداند، یک متنِ سه‌قسمتی می‌نویسد و آخر ماه صورتحسابش سه برابر است.
 */
function parts(body: string): number {
    const length = body.length;

    if (length === 0) return 0;
    if (length <= 70) return 1;

    return Math.ceil(length / 67);
}

function save(template: Template) {
    forms.value[template.id].put(route('staff.settings.sms-templates.update', template.id), {
        preserveScroll: true,
    });
}

function reset(template: Template) {
    router.post(
        route('staff.settings.sms-templates.reset', template.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                if (template.default_body !== null) {
                    forms.value[template.id].body = template.default_body;
                }
            },
        },
    );
}

/** گذاشتن یک متغیر در انتهای متن — تایپِ دستی‌اش غلط‌گیری می‌خواهد */
function insert(template: Template, name: string) {
    const form = forms.value[template.id];

    form.body = `${form.body}{${name}}`;
}
</script>

<template>
    <StaffLayout title="متن پیامک‌ها">
        <div class="mx-auto max-w-3xl space-y-5">
            <div>
                <h1 class="text-lg font-bold text-slate-900">متن پیامک‌ها</h1>
                <p class="mt-1 text-sm text-slate-500">
                    متن هر پیامک را با ادبیات کارخانه‌ی خودتان بنویسید. چیزهایی که داخل
                    <span class="num">{ }</span> هستند موقع ارسال با مقدار واقعی جایگزین می‌شوند.
                </p>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <section v-for="template in templates" :key="template.id" class="card overflow-hidden">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 px-5 py-3.5 text-right transition hover:bg-slate-50"
                    @click="open = open === template.id ? null : template.id"
                >
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-slate-800">{{ template.title }}</span>
                        <span class="num mt-0.5 block truncate text-xs text-slate-400">{{ template.key }}</span>
                    </span>

                    <span class="flex shrink-0 items-center gap-2">
                        <span
                            v-if="!forms[template.id].is_active"
                            class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                        >
                            خاموش
                        </span>
                        <span class="text-slate-400">{{ open === template.id ? '−' : '+' }}</span>
                    </span>
                </button>

                <div v-if="open === template.id" class="space-y-4 border-t border-slate-100 px-5 py-4">
                    <div>
                        <textarea
                            :id="`body-${template.id}`"
                            v-model="forms[template.id].body"
                            rows="5"
                            class="block w-full rounded-xl border px-3 py-2.5 text-sm leading-7 transition focus:outline-none focus:ring-2"
                            :class="
                                forms[template.id].errors.body
                                    ? 'border-rose-300 focus:ring-rose-200'
                                    : 'border-slate-300 focus:ring-brand-200'
                            "
                        />

                        <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                            <p v-if="forms[template.id].errors.body" class="text-rose-600">
                                {{ forms[template.id].errors.body }}
                            </p>
                            <p v-else class="text-slate-400">
                                <span class="num">{{ forms[template.id].body.length }}</span> نویسه —
                                <span class="num">{{ parts(forms[template.id].body) }}</span> پیامک
                            </p>
                        </div>
                    </div>

                    <div v-if="template.variables.length">
                        <p class="text-xs font-medium text-slate-600">متغیرهای این پیامک — برای افزودن کلیک کنید:</p>

                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="variable in template.variables"
                                :key="variable.name"
                                type="button"
                                :title="variable.label"
                                class="num rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700 transition hover:border-brand-300 hover:bg-brand-50"
                                @click="insert(template, variable.name)"
                            >
                                {{ '{' + variable.name + '}' }}
                            </button>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            v-model="forms[template.id].is_active"
                            type="checkbox"
                            class="size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-200"
                        />
                        این پیامک ارسال شود
                    </label>

                    <div class="flex flex-wrap gap-2">
                        <AppButton :loading="forms[template.id].processing" @click="save(template)">ذخیره</AppButton>

                        <AppButton
                            v-if="template.default_body"
                            variant="secondary"
                            @click="reset(template)"
                        >
                            بازگشت به متن پیش‌فرض
                        </AppButton>
                    </div>
                </div>
            </section>
        </div>
    </StaffLayout>
</template>
