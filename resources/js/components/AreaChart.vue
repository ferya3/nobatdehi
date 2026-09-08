<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * روند یک سری در طول زمان.
 *
 * برای «چند کامیون در هر روز» شکل درست، خطِ روند است و نه ستون: چشم باید
 * جهت را ببیند، نه ارتفاع هفت میله را با هم بسنجد. یک سری است، پس راهنمای
 * رنگ لازم ندارد — عنوانِ نمودار خودش می‌گوید چیست.
 */
const props = withDefaults(
    defineProps<{
        data: { label: string; value: number }[];
        height?: number;
        unit?: string;
    }>(),
    { height: 190, unit: '' },
);

const PAD = { top: 14, bottom: 26, start: 30, end: 10 };
const W = 640;

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)));
const plotH = computed(() => props.height - PAD.top - PAD.bottom);
const plotW = W - PAD.start - PAD.end;

const points = computed(() =>
    props.data.map((row, i) => ({
        ...row,
        // با یک نقطه، تقسیم بر صفر می‌شد؛ آن نقطه وسط می‌نشیند
        x: props.data.length === 1 ? PAD.start + plotW / 2 : PAD.start + (i / (props.data.length - 1)) * plotW,
        y: PAD.top + plotH.value - (row.value / max.value) * plotH.value,
    })),
);

const line = computed(() => points.value.map((p, i) => `${i === 0 ? 'M' : 'L'}${p.x},${p.y}`).join(' '));

const area = computed(() => {
    if (!points.value.length) return '';

    const base = PAD.top + plotH.value;
    const first = points.value[0];
    const last = points.value[points.value.length - 1];

    return `M${first.x},${base} ${line.value.replace('M', 'L')} L${last.x},${base} Z`;
});

/** سه خط راهنما — بیشتر از این، شبکه به داده غالب می‌شود */
const guides = computed(() =>
    [max.value, Math.round(max.value / 2), 0]
        .filter((v, i, all) => all.indexOf(v) === i)
        .map((value) => ({ value, y: PAD.top + plotH.value - (value / max.value) * plotH.value })),
);

const hover = ref<number | null>(null);
const active = computed(() => (hover.value === null ? null : points.value[hover.value]));

function nearest(event: MouseEvent) {
    const svg = event.currentTarget as SVGSVGElement;
    const box = svg.getBoundingClientRect();
    const x = ((event.clientX - box.left) / box.width) * W;

    let best = 0;

    points.value.forEach((p, i) => {
        if (Math.abs(p.x - x) < Math.abs(points.value[best].x - x)) best = i;
    });

    hover.value = best;
}
</script>

<template>
    <div v-if="data.length" class="relative">
        <svg
            :viewBox="`0 0 ${W} ${height}`"
            class="w-full"
            :style="{ height: `${height}px` }"
            role="img"
            @mousemove="nearest"
            @mouseleave="hover = null"
        >
            <defs>
                <linearGradient id="area-fill" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stop-color="var(--color-brand-500)" stop-opacity="0.28" />
                    <stop offset="100%" stop-color="var(--color-brand-500)" stop-opacity="0.02" />
                </linearGradient>
            </defs>

            <!-- شبکه عمداً کم‌رنگ است: زمینه است، نه داده -->
            <g>
                <line
                    v-for="g in guides"
                    :key="g.value"
                    :x1="PAD.start"
                    :x2="W - PAD.end"
                    :y1="g.y"
                    :y2="g.y"
                    stroke="currentColor"
                    class="text-slate-200"
                    stroke-width="1"
                    stroke-dasharray="3 4"
                />
                <text
                    v-for="g in guides"
                    :key="`t-${g.value}`"
                    :x="W - PAD.end + 4"
                    :y="g.y + 4"
                    class="fill-slate-400 text-[11px]"
                >
                    {{ g.value }}
                </text>
            </g>

            <path :d="area" fill="url(#area-fill)" />
            <path :d="line" fill="none" stroke="var(--color-brand-600)" stroke-width="2" stroke-linejoin="round" />

            <!--
                نقطه روی هر روز.
                بدون این، بازه‌ای که فقط یک روز داده دارد هیچ چیز قابل دیدنی
                نمی‌کشد — مسیر به یک خطِ بی‌عرض تبدیل می‌شود.
            -->
            <circle
                v-for="p in points"
                :key="`d-${p.label}`"
                :cx="p.x"
                :cy="p.y"
                r="3"
                fill="white"
                stroke="var(--color-brand-600)"
                stroke-width="2"
            />

            <!-- نشانگر روی نقطه‌ی نزدیک، نه روی همه: عدد روی هر نقطه، نمودار را جدول می‌کند -->
            <line
                v-if="active"
                :x1="active.x"
                :x2="active.x"
                :y1="PAD.top"
                :y2="PAD.top + plotH"
                stroke="currentColor"
                class="text-slate-300"
                stroke-width="1"
            />
            <circle
                v-if="active"
                :cx="active.x"
                :cy="active.y"
                r="5"
                fill="var(--color-brand-600)"
                stroke="white"
                stroke-width="2"
            />

            <text
                v-for="(p, i) in points"
                :key="p.label"
                :x="p.x"
                :y="height - 8"
                text-anchor="middle"
                :class="['text-[11px]', i === hover ? 'fill-slate-700' : 'fill-slate-400']"
            >
                {{ p.label }}
            </text>
        </svg>

        <div
            v-if="active"
            class="pointer-events-none absolute top-1 rounded-lg bg-slate-800 px-2.5 py-1 text-xs text-white shadow-lg"
            :style="{ insetInlineStart: `${(active.x / W) * 100}%`, transform: 'translateX(50%)' }"
        >
            {{ active.label }} — <span class="num font-semibold">{{ active.value }}</span> {{ unit }}
        </div>
    </div>

    <p v-else class="py-10 text-center text-sm text-slate-400">داده‌ای برای نمایش نیست.</p>
</template>
