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

// route().has() لازم است: مسیرهای فازهای بعدی ممکن است هنوز ثبت نشده باشند
const nav = computed(() =>
    [
        { label: 'صف امروز', name: 'staff.queue.index', pattern: 'staff.queue.*', permission: 'queue.view' },
        { label: 'نگهبانی', name: 'staff.gate.index', pattern: 'staff.gate.*', permission: 'queue.checkin' },
        { label: 'داشبورد', name: 'staff.dashboard', pattern: 'staff.dashboard', permission: 'dashboard.view' },
        { label: 'گزارش‌ها', name: 'staff.reports', pattern: 'staff.reports', permission: 'reports.view' },
        { label: 'محصولات', name: 'staff.products.index', pattern: 'staff.products.*', permission: 'products.manage' },
        { label: 'انواع کامیون', name: 'staff.truck-types.index', pattern: 'staff.truck-types.*', permission: 'products.manage' },
        { label: 'تنظیمات', name: 'staff.settings.edit', pattern: 'staff.settings.edit', permission: 'settings.manage' },
        { label: 'پیامک', name: 'staff.settings.sms', pattern: 'staff.settings.sms*', permission: 'settings.manage' },
    ]
        .filter((item) => can(item.permission) && route().has(item.name))
        .map((item) => ({
            label: item.label,
            href: route(item.name),
            active: route().current(item.pattern),
        })),
);

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
                        v-for="item in nav"
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
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    :class="[
                        'block rounded-lg px-3 py-2 text-sm',
                        item.active ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600',
                    ]"
                >
                    {{ item.label }}
                </Link>
            </nav>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6">
            <slot />
        </main>
    </div>
</template>
