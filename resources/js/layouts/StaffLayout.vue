<script setup lang="ts">
import type { PageProps } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{ title?: string }>();

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);
const factory = computed(() => page.props.factory);
const menuOpen = ref(false);

const can = (permission: string) => user.value?.permissions.includes(permission) ?? false;

/**
 * منوی اصلی — به ترتیبی که کار در کارخانه پیش می‌رود.
 *
 * داشبورد، بعد سه ایستگاهِ فیزیکی به همان ترتیبی که کامیون از آن‌ها رد
 * می‌شود، و در آخر گزارش‌ها. تنظیمات از منوی اصلی بیرون رفته چون کاری است
 * که هفته‌ای یک بار انجام می‌شود، نه هر ساعت.
 *
 * route().has() لازم است: مسیرهای فازهای بعدی ممکن است هنوز ثبت نشده باشند.
 */
const PRIMARY = [
    { label: 'داشبورد', name: 'staff.dashboard', pattern: 'staff.dashboard', permission: 'dashboard.view' },
    { label: 'نگهبانی', name: 'staff.gate.index', pattern: 'staff.gate.*', permission: 'queue.checkin' },
    { label: 'باسکول', name: 'staff.weighbridge.index', pattern: 'staff.weighbridge.*', permission: 'weighing.record' },
    { label: 'بارگیری', name: 'staff.loading.index', pattern: 'staff.loading.*', permission: 'queue.start-loading' },
    { label: 'گزارش‌ها', name: 'staff.reports', pattern: 'staff.reports', permission: 'reports.view' },
    { label: 'اعلان‌ها', name: 'staff.notifications.index', pattern: 'staff.notifications.*', permission: 'notifications.send' },
];

/** هرچه یک بار تنظیم می‌شود و بعد دست نمی‌خورد */
const SETTINGS = [
    { label: 'نوبت‌دهی و ساعات کاری', name: 'staff.settings.edit', pattern: 'staff.settings.edit', permission: 'settings.manage' },
    { label: 'محصولات', name: 'staff.products.index', pattern: 'staff.products.*', permission: 'products.manage' },
    { label: 'انواع کامیون', name: 'staff.truck-types.index', pattern: 'staff.truck-types.*', permission: 'products.manage' },
    { label: 'دستگاه‌های گیت', name: 'staff.settings.devices', pattern: 'staff.settings.devices*', permission: 'settings.manage' },
    { label: 'پیامک', name: 'staff.settings.sms', pattern: 'staff.settings.sms*', permission: 'settings.manage' },
];

type NavItem = { label: string; href: string; active: boolean };

function build(items: typeof PRIMARY): NavItem[] {
    return items
        .filter((item) => can(item.permission) && route().has(item.name))
        .map((item) => ({
            label: item.label,
            href: route(item.name),
            active: route().current(item.pattern),
        }));
}

const primary = computed(() => {
    const items = build(PRIMARY);

    // صف امروز داخل داشبورد نشان داده می‌شود. ولی نگهبانی و باسکول و انبار
    // داشبورد ندارند و بدون این، راهی به صف نمی‌ماند.
    if (!can('dashboard.view') && can('queue.view') && route().has('staff.queue.index')) {
        items.unshift({
            label: 'صف امروز',
            href: route('staff.queue.index'),
            active: route().current('staff.queue.*'),
        });
    }

    return items;
});

const settings = computed(() => build(SETTINGS));
const settingsActive = computed(() => settings.value.some((item) => item.active));

const settingsOpen = ref(false);

function closeMenus() {
    settingsOpen.value = false;
    menuOpen.value = false;
}

function logout() {
    router.post(route('staff.logout'));
}
</script>

<template>
    <Head :title="title" />

    <div class="min-h-dvh bg-slate-50">
        <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-4 px-4">
                <div class="flex items-center gap-2.5">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-brand-600 text-white" aria-hidden="true">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 7h11v9H3z" stroke-linejoin="round" />
                            <path d="M14 10h4l3 3v3h-7z" stroke-linejoin="round" />
                            <circle cx="7" cy="18" r="1.8" />
                            <circle cx="17" cy="18" r="1.8" />
                        </svg>
                    </span>
                    <span class="hidden text-sm font-semibold text-slate-800 sm:block">
                        {{ factory?.name ?? 'کارخانه' }}
                    </span>
                </div>

                <nav class="hidden flex-1 items-center gap-1 md:flex">
                    <Link
                        v-for="item in primary"
                        :key="item.href"
                        :href="item.href"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm transition',
                            item.active
                                ? 'bg-brand-50 font-medium text-brand-700'
                                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                        ]"
                    >
                        {{ item.label }}
                    </Link>

                    <div v-if="settings.length" class="relative">
                        <button
                            type="button"
                            :class="[
                                'flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm transition',
                                settingsActive
                                    ? 'bg-brand-50 font-medium text-brand-700'
                                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                            ]"
                            :aria-expanded="settingsOpen"
                            @click="settingsOpen = !settingsOpen"
                        >
                            تنظیمات
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>

                        <!-- کلیک بیرون از منو باید ببنددش، وگرنه باز می‌ماند و روی صفحه می‌نشیند -->
                        <div v-if="settingsOpen" class="fixed inset-0 z-10" @click="settingsOpen = false" />

                        <div
                            v-if="settingsOpen"
                            class="absolute start-0 z-20 mt-1 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-lg"
                        >
                            <Link
                                v-for="item in settings"
                                :key="item.href"
                                :href="item.href"
                                :class="[
                                    'block px-4 py-2 text-sm transition',
                                    item.active
                                        ? 'bg-brand-50 font-medium text-brand-700'
                                        : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
                                ]"
                                @click="closeMenus"
                            >
                                {{ item.label }}
                            </Link>
                        </div>
                    </div>
                </nav>

                <div class="ms-auto flex items-center gap-3">
                    <div class="hidden text-end sm:block">
                        <p class="text-sm font-medium text-slate-800">{{ user?.name }}</p>
                        <p class="text-xs text-slate-500">{{ user?.roles.join('، ') }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-sm text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        @click="logout"
                    >
                        خروج
                    </button>
                    <button
                        type="button"
                        class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 md:hidden"
                        :aria-expanded="menuOpen"
                        aria-label="منو"
                        @click="menuOpen = !menuOpen"
                    >
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>
            </div>

            <nav v-if="menuOpen" class="border-t border-slate-200 px-4 py-2 md:hidden">
                <Link
                    v-for="item in primary"
                    :key="item.href"
                    :href="item.href"
                    :class="[
                        'block rounded-lg px-3 py-2 text-sm',
                        item.active ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600',
                    ]"
                    @click="closeMenus"
                >
                    {{ item.label }}
                </Link>

                <div v-if="settings.length" class="mt-2 border-t border-slate-100 pt-2">
                    <p class="px-3 py-1 text-xs font-medium text-slate-400">تنظیمات</p>
                    <Link
                        v-for="item in settings"
                        :key="item.href"
                        :href="item.href"
                        :class="[
                            'block rounded-lg px-3 py-2 text-sm',
                            item.active ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600',
                        ]"
                        @click="closeMenus"
                    >
                        {{ item.label }}
                    </Link>
                </div>
            </nav>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
