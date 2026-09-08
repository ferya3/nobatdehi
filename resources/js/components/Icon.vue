<script setup lang="ts">
/**
 * آیکون‌ها به‌صورت SVG درون‌خطی و نه از یک کتابخانه.
 *
 * دو دلیل: هیچ فایل اضافه‌ای دانلود نمی‌شود (روی اینترنت راننده مهم است)،
 * و CSP سختگیرانه‌ی این پروژه اجازه‌ی بارگیری از دامنه‌ی دیگر نمی‌دهد.
 *
 * همه با stroke کشیده شده‌اند تا با ضخامت و اندازه‌ی متن هماهنگ بمانند.
 */
defineProps<{ name: string; class?: string }>();

const PATHS: Record<string, string> = {
    truck: 'M3 7h11v9H3zM14 10h4l3 3v3h-7z M7 18.5a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4M17 18.5a1.7 1.7 0 1 0 0-3.4 1.7 1.7 0 0 0 0 3.4',
    clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 7v5l3.5 2',
    check: 'M20 6 9 17l-5-5',
    alert: 'M12 9v4M12 17h.01M10.3 3.9 2.4 17.2A2 2 0 0 0 4.1 20.2h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z',
    gauge: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 12l4-4',
    box: 'M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
    chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    users: 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.75 3.75 0 1 0 0-7.5 3.75 3.75 0 0 0 0 7.5M20 20v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 4.2a3.75 3.75 0 0 1 0 7.1',
    ban: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M5.6 5.6l12.8 12.8',
    scale: 'M12 3v18M7 21h10M5 8h14M5 8 2.5 14h5zM19 8l-2.5 6h5z',
    layers: 'M12 3 3 7.5l9 4.5 9-4.5zM3 12.5 12 17l9-4.5M3 17 12 21.5 21 17',
    activity: 'M22 12h-4l-3 8-6-16-3 8H2',
};
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="$props.class"
        aria-hidden="true"
    >
        <path :d="PATHS[name] ?? PATHS.chart" />
    </svg>
</template>
