<script setup lang="ts">
import PlateBadge from '@/components/PlateBadge.vue';
import type { PlateParts } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

interface Permit {
    number: string;
    issued_at: string | null;
    waybill_number: number | string;
    factory: { name: string | null; phone: string | null; address: string | null };
    appointment: { number: number; date: string; product: string | null; loading_point: string | null };
    truck: { plate: PlateParts | null; type: string | null; capacity_kg: number | null };
    driver: { name: string | null; mobile: string | null; national_code: string | null };
    weights: {
        tare_kg: string | null;
        gross_kg: string | null;
        net_kg: string | null;
        tare_at: string | null;
        gross_at: string | null;
    };
    overload: { kg: string | null; decision: string | null; reason: string | null } | null;
}

defineProps<{ permit: Permit }>();

const kg = (value: string | number | null) =>
    value === null ? '—' : Number(value).toLocaleString('en-US');

const print = () => window.print();
</script>

<template>
    <Head :title="`برگه خروج ${permit.number}`" />

    <!--
        برگه‌ی خروج، ساخته‌شده برای کاغذ.
        روی صفحه یک A5 وسطِ زمینه‌ی خاکستری است؛ موقع چاپ، زمینه و دکمه‌ها
        می‌روند و فقط همان کاغذ می‌ماند.
    -->
    <div class="min-h-dvh bg-slate-100 py-6 print:bg-white print:py-0">
        <div class="mx-auto max-w-[148mm] space-y-4 px-4 print:max-w-none print:px-0">
            <div class="flex items-center justify-between gap-3 print:hidden">
                <Link
                    :href="route('staff.console')"
                    class="text-sm text-slate-500 transition hover:text-slate-700"
                >
                    ← بازگشت
                </Link>

                <button
                    type="button"
                    class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                    @click="print"
                >
                    چاپ برگه
                </button>
            </div>

            <article
                class="rounded-2xl border border-slate-300 bg-white p-6 shadow-sm print:rounded-none print:border-0 print:p-0 print:shadow-none"
            >
                <!-- سربرگ -->
                <header class="flex items-start justify-between gap-4 border-b-2 border-slate-800 pb-3">
                    <div class="min-w-0">
                        <h1 class="text-base font-bold text-slate-900">{{ permit.factory.name ?? 'کارخانه' }}</h1>
                        <p v-if="permit.factory.address" class="mt-0.5 text-[11px] leading-5 text-slate-600">
                            {{ permit.factory.address }}
                        </p>
                        <p v-if="permit.factory.phone" class="num text-[11px] text-slate-600">
                            تلفن: {{ permit.factory.phone }}
                        </p>
                    </div>

                    <div class="shrink-0 text-left">
                        <p class="text-xs text-slate-500">برگه خروج</p>
                        <p class="num text-lg font-bold text-slate-900" dir="ltr">{{ permit.number }}</p>
                        <p v-if="permit.issued_at" class="num text-[11px] text-slate-500" dir="ltr">
                            {{ permit.issued_at }}
                        </p>
                    </div>
                </header>

                <!-- کامیون و راننده -->
                <section class="grid grid-cols-2 gap-x-6 gap-y-2.5 py-4 text-sm">
                    <div class="col-span-2 flex items-center gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">پلاک</span>
                        <PlateBadge v-if="permit.truck.plate" :plate="permit.truck.plate" />
                        <span v-else class="text-slate-400">—</span>
                    </div>

                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">راننده</span>
                        <span class="font-medium text-slate-900">{{ permit.driver.name ?? '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">همراه</span>
                        <span class="num text-slate-800" dir="ltr">{{ permit.driver.mobile ?? '—' }}</span>
                    </div>

                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">کد ملی</span>
                        <span class="num text-slate-800" dir="ltr">{{ permit.driver.national_code ?? '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">نوع خودرو</span>
                        <span class="text-slate-800">{{ permit.truck.type ?? '—' }}</span>
                    </div>

                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">نوع بار</span>
                        <span class="font-medium text-slate-900">{{ permit.appointment.product ?? '—' }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">لاین</span>
                        <span class="text-slate-800">{{ permit.appointment.loading_point ?? '—' }}</span>
                    </div>

                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">شماره نوبت</span>
                        <span class="num text-slate-800">{{ permit.appointment.number }}</span>
                    </div>
                    <div class="flex gap-3">
                        <span class="w-20 shrink-0 text-xs text-slate-500">تاریخ نوبت</span>
                        <span class="text-slate-800">{{ permit.appointment.date }}</span>
                    </div>
                </section>

                <!-- توزین: همان چیزی که باسکول گفته -->
                <section class="overflow-hidden rounded-lg border border-slate-300">
                    <table class="w-full text-right text-sm">
                        <tbody class="divide-y divide-slate-200">
                            <tr>
                                <th class="w-32 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600">وزن خالی</th>
                                <td class="num px-3 py-2 font-medium text-slate-900" dir="ltr">
                                    {{ kg(permit.weights.tare_kg) }}
                                </td>
                                <td class="num w-40 px-3 py-2 text-[11px] text-slate-500" dir="ltr">
                                    {{ permit.weights.tare_at ?? '' }}
                                </td>
                            </tr>
                            <tr>
                                <th class="bg-slate-50 px-3 py-2 text-xs font-medium text-slate-600">وزن پر</th>
                                <td class="num px-3 py-2 font-medium text-slate-900" dir="ltr">
                                    {{ kg(permit.weights.gross_kg) }}
                                </td>
                                <td class="num px-3 py-2 text-[11px] text-slate-500" dir="ltr">
                                    {{ permit.weights.gross_at ?? '' }}
                                </td>
                            </tr>
                            <tr class="bg-slate-900 text-white">
                                <th class="px-3 py-2.5 text-xs font-medium">وزن خالص</th>
                                <td class="num px-3 py-2.5 text-lg font-bold" dir="ltr">
                                    {{ kg(permit.weights.net_kg) }}
                                </td>
                                <td class="px-3 py-2.5 text-[11px] opacity-75">کیلوگرم</td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <!-- اضافه‌باری که پذیرفته شده، روی خودِ کاغذ می‌ماند -->
                <p
                    v-if="permit.overload"
                    class="mt-3 rounded-lg border border-slate-400 px-3 py-2 text-[11px] leading-5 text-slate-700"
                >
                    <span class="font-semibold">اضافه‌بار</span>
                    <span v-if="permit.overload.kg" class="num" dir="ltr"> {{ kg(permit.overload.kg) }} </span>
                    کیلوگرم — با تأیید مدیریت.
                    <span v-if="permit.overload.reason" class="block text-slate-600">
                        {{ permit.overload.reason }}
                    </span>
                </p>

                <!-- امضاها -->
                <footer class="mt-6 grid grid-cols-3 gap-4 text-center text-[11px] text-slate-600">
                    <div>
                        <p>باسکول‌بان</p>
                        <div class="mt-8 border-t border-dashed border-slate-400 pt-1">امضا</div>
                    </div>
                    <div>
                        <p>راننده</p>
                        <div class="mt-8 border-t border-dashed border-slate-400 pt-1">امضا</div>
                    </div>
                    <div>
                        <p>نگهبانی</p>
                        <div class="mt-8 border-t border-dashed border-slate-400 pt-1">امضا</div>
                    </div>
                </footer>

                <p class="mt-4 text-center text-[10px] text-slate-400">
                    این برگه با شماره
                    <span class="num" dir="ltr">{{ permit.number }}</span>
                    در سامانه ثبت شده است.
                </p>
            </article>
        </div>
    </div>
</template>

<style>
@media print {
    @page {
        size: A5;
        margin: 12mm;
    }
}
</style>
