<script setup lang="ts">
import type { PageProps } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{ title?: string }>();

const page = usePage<PageProps>();
const factory = computed(() => page.props.factory);
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-dvh flex-col bg-slate-50">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-md items-center gap-3 px-5 py-4">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-white"
                    aria-hidden="true"
                >
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M3 7h11v9H3z" stroke-linejoin="round" />
                        <path d="M14 10h4l3 3v3h-7z" stroke-linejoin="round" />
                        <circle cx="7" cy="18" r="1.8" />
                        <circle cx="17" cy="18" r="1.8" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-800">
                        {{ factory?.name ?? 'نوبت بارگیری' }}
                    </p>
                    <p class="text-xs text-slate-500">سامانه نوبت‌دهی بارگیری</p>
                </div>

                <div class="ms-auto">
                    <slot name="header-action" />
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-md flex-1 px-5 py-6">
            <slot />
        </main>

        <footer class="mx-auto w-full max-w-md px-5 pb-6 text-center text-xs text-slate-400">
            <slot name="footer" />
        </footer>
    </div>
</template>
