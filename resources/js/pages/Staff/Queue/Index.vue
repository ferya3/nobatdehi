<script setup lang="ts">
import AlertBox from '@/components/AlertBox.vue';
import PlateBadge from '@/components/PlateBadge.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import StaffLayout from '@/layouts/StaffLayout.vue';
import { duration } from '@/lib/format';
import type { Appointment, PageProps } from '@/types';
import { useLiveChannel } from '@/lib/useLiveChannel';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

interface QueueAction {
    value: string;
    label: string;
    tone: string;
    is_rollback: boolean;
    needs_reason: boolean;
    needs_loading_point: boolean;
}

type QueueRow = Appointment & {
    wait_minutes: number | null;
    loading_minutes: number | null;
    actions: QueueAction[];
};

const props = defineProps<{
    factoryId: number;
    date: string;
    jalaliDate: string;
    isToday: boolean;
    counters: Record<string, number>;
    appointments: QueueRow[];
    loadingPoints: { id: number; name: string }[];
    statuses: { value: string; label: string; tone: string }[];
}>();

const page = usePage<PageProps>();
const flash = computed(() => page.props.flash);

const canManageQueue = computed(() => page.props.auth.user?.permissions.includes('queue.manage') ?? false);

// تغییر اولویت: جای نوبت را داخل همان ساعت جابه‌جا می‌کند، نه بین ساعت‌ها
const priorityFor = ref<QueueRow | null>(null);
const priorityValue = ref(0);
const priorityReason = ref('');

function openPriority(row: QueueRow) {
    priorityFor.value = row;
    priorityValue.value = row.priority ?? 0;
    priorityReason.value = '';
}

function savePriority() {
    const row = priorityFor.value;
    if (!row || priorityReason.value.trim().length < 5) return;

    busy.value = row.ulid;

    router.post(
        route('staff.queue.priority', row.ulid),
        { priority: priorityValue.value, priority_reason: priorityReason.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => {
                busy.value = null;
                priorityFor.value = null;
            },
        },
    );
}

const filter = ref<string>('active');
const search = ref('');
const busy = ref<string | null>(null);

// اکشنی که نیاز به دلیل یا انتخاب لاین دارد، اول یک پنل کوچک باز می‌کند
const pending = ref<{ row: QueueRow; action: QueueAction } | null>(null);
const reason = ref('');
const loadingPointId = ref<number | null>(null);

const FILTERS = [
    { key: 'active', label: 'در جریان' },
    { key: 'waiting', label: 'در انتظار' },
    { key: 'onsite', label: 'در محوطه' },
    { key: 'done', label: 'پایان‌یافته' },
    { key: 'all', label: 'همه' },
];

const rows = computed(() => {
    const term = search.value.trim();

    return props.appointments.filter((row) => {
        const matchesFilter =
            filter.value === 'all' ||
            (filter.value === 'active' && row.is_active) ||
            (filter.value === 'waiting' && ['BOOKED', 'WAITING', 'CALLED'].includes(row.status)) ||
            (filter.value === 'onsite' && ['CHECKED_IN', 'LOADING', 'LOADED'].includes(row.status)) ||
            (filter.value === 'done' && !row.is_active);

        if (!matchesFilter) return false;
        if (!term) return true;

        return (
            String(row.number).includes(term) ||
            (row.truck?.plate.key ?? '').includes(term) ||
            (row.driver?.name ?? '').includes(term) ||
            (row.driver?.mobile ?? '').includes(term)
        );
    });
});

function run(row: QueueRow, action: QueueAction) {
    if (action.needs_reason || action.needs_loading_point) {
        pending.value = { row, action };
        reason.value = '';
        loadingPointId.value = props.loadingPoints[0]?.id ?? null;
        return;
    }

    submit(row, action, {});
}

function confirmPending() {
    if (!pending.value) return;

    const { row, action } = pending.value;

    submit(row, action, {
        reason: action.needs_reason ? reason.value : undefined,
        loading_point_id: action.needs_loading_point ? loadingPointId.value : undefined,
    });

    pending.value = null;
}

function submit(row: QueueRow, action: QueueAction, data: Record<string, unknown>) {
    busy.value = row.ulid;

    router.post(
        route('staff.queue.transition', row.ulid),
        { to: action.value, ...data },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => (busy.value = null),
        },
    );
}

function shiftDate(days: number) {
    const next = new Date(props.date);
    next.setDate(next.getDate() + days);
    router.get(route('staff.queue.index'), { date: next.toISOString().slice(0, 10) }, { preserveScroll: true });
}

/**
 * صف زنده.
 *
 * WebSocket مسیر اصلی است؛ polling فقط پشتیبان است و وقتی اتصال زنده برقرار
 * باشد فاصله‌اش بسیار طولانی می‌شود. اپراتور نباید هر ده ثانیه صفحه را
 * refresh کند و دیتابیس هم نباید بی‌دلیل کشیده شود.
 */
const { connected } = useLiveChannel(props.isToday ? `factory.${props.factoryId}.queue` : null, {
    'queue.changed': () => refresh(),
});

let poller: ReturnType<typeof setInterval> | null = null;

function refresh() {
    if (document.hidden || pending.value) return;

    router.reload({ only: ['appointments', 'counters'] });
}

/**
 * برگشتن به تب، خودش یک درخواست تازه است.
 *
 * refresh() وقتی صفحه پنهان است کاری نمی‌کند — درست هم همین است. ولی بدون
 * این، اپراتوری که به پنجره‌ی دیگری رفته و برگشته، تا تیکِ بعدیِ ۲۰ ثانیه‌ای
 * یک صف کهنه می‌بیند و فکر می‌کند نوبتی ثبت نشده.
 */
function onVisible() {
    if (!document.hidden) refresh();
}

onMounted(() => {
    if (!props.isToday) return;

    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);

    poller = setInterval(refresh, 20_000);
});

onUnmounted(() => {
    document.removeEventListener('visibilitychange', onVisible);
    window.removeEventListener('focus', onVisible);

    if (poller) clearInterval(poller);
});

// وقتی اتصال زنده برقرار شد، دیگر لازم نیست هر ۲۰ ثانیه سؤال کنیم
watch(connected, (isLive) => {
    if (poller) clearInterval(poller);

    poller = setInterval(refresh, isLive ? 120_000 : 20_000);
});
</script>

<template>
    <StaffLayout title="صف کامیون‌ها">
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="flex items-center gap-2 text-lg font-bold text-slate-900">
                        مدیریت صف کامیون‌ها
                        <!-- اتصال زنده‌ی خراب باید دیده شود، نه اینکه بی‌صدا به صفِ کهنه ختم شود -->
                        <span
                            v-if="isToday"
                            :title="connected ? 'به‌روزرسانی زنده برقرار است' : 'اتصال زنده برقرار نیست؛ هر ۲۰ ثانیه تازه می‌شود'"
                            :class="[
                                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium',
                                connected ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800',
                            ]"
                        >
                            <span :class="['size-2 rounded-full', connected ? 'bg-emerald-500' : 'bg-amber-500']" aria-hidden="true" />
                            {{ connected ? 'زنده' : 'هر ۲۰ ثانیه' }}
                        </span>
                    </h1>
                    <p class="mt-0.5 flex items-center gap-2 text-sm text-slate-500">
                        <span>{{ jalaliDate }}</span>
                        <span v-if="isToday" class="text-brand-600">· امروز</span>
                        <span
                            v-if="isToday && connected"
                            class="inline-flex items-center gap-1 text-xs text-emerald-600"
                            title="به‌روزرسانی زنده برقرار است"
                        >
                            <span class="size-1.5 rounded-full bg-emerald-500" />
                            زنده
                        </span>
                    </p>
                </div>

                <div class="flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1">
                    <button
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100"
                        @click="shiftDate(-1)"
                    >
                        روز قبل
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100 disabled:text-slate-300"
                        :disabled="isToday"
                        @click="router.get(route('staff.queue.index'))"
                    >
                        امروز
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 transition hover:bg-slate-100"
                        @click="shiftDate(1)"
                    >
                        روز بعد
                    </button>
                </div>
            </div>

            <AlertBox v-if="flash.success" tone="success">{{ flash.success }}</AlertBox>
            <AlertBox v-if="flash.error" tone="error">{{ flash.error }}</AlertBox>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard label="کل نوبت" :value="counters.total" />
                <StatCard label="در انتظار" :value="counters.waiting" tone="waiting" />
                <StatCard label="در محوطه" :value="counters.on_site" tone="checkedin" />
                <StatCard label="در حال بارگیری" :value="counters.loading" tone="loading" />
                <StatCard label="تکمیل‌شده" :value="counters.completed" tone="completed" />
                <StatCard label="لغو / عدم حضور" :value="counters.cancelled + counters.no_show" tone="failed" />
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <div class="flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1">
                    <button
                        v-for="item in FILTERS"
                        :key="item.key"
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-sm transition',
                            filter === item.key
                                ? 'bg-brand-600 font-medium text-white'
                                : 'text-slate-600 hover:bg-slate-100',
                        ]"
                        @click="filter = item.key"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <input
                    v-model="search"
                    type="search"
                    placeholder="جستجوی شماره نوبت، پلاک، راننده…"
                    class="min-w-56 flex-1 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm shadow-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100"
                />
            </div>

            <!-- تغییر اولویت -->
            <div v-if="priorityFor" class="card space-y-4 border-amber-300 p-5">
                <div>
                    <p class="text-sm font-medium text-slate-800">
                        اولویت نوبت <span class="num">{{ priorityFor.number }}</span>
                    </p>
                    <p class="mt-1 text-xs text-slate-500">
                        اولویت فقط داخل همان ساعت اثر دارد؛ نوبت ساعت بعد را جلوی نوبت ساعت قبل نمی‌اندازد.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <input
                        v-model.number="priorityValue"
                        type="range"
                        min="0"
                        max="100"
                        step="10"
                        class="flex-1 accent-amber-600"
                        aria-label="اولویت"
                    />
                    <span class="num w-10 text-center text-lg font-bold text-amber-800">{{ priorityValue }}</span>
                </div>

                <input
                    v-model="priorityReason"
                    type="text"
                    placeholder="دلیل تغییر اولویت (اجباری)"
                    class="block w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm focus:border-amber-500 focus:outline-none focus:ring-4 focus:ring-amber-100"
                />

                <div class="flex gap-2">
                    <button
                        type="button"
                        :disabled="priorityReason.trim().length < 5 || busy === priorityFor.ulid"
                        class="rounded-xl bg-amber-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-amber-700 disabled:opacity-50"
                        @click="savePriority"
                    >
                        ثبت اولویت
                    </button>
                    <button
                        type="button"
                        class="rounded-xl px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-100"
                        @click="priorityFor = null"
                    >
                        انصراف
                    </button>
                </div>
            </div>

            <div v-if="pending" class="card space-y-4 border-brand-300 p-5">
                <p class="text-sm font-medium text-slate-800">
                    {{ pending.action.label }} — نوبت
                    <span class="num">{{ pending.row.number }}</span>
                </p>

                <div v-if="pending.action.needs_loading_point">
                    <label class="mb-1.5 block text-sm text-slate-600">لاین بارگیری</label>
                    <select
                        v-model="loadingPointId"
                        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100"
                    >
                        <option v-for="point in loadingPoints" :key="point.id" :value="point.id">
                            {{ point.name }}
                        </option>
                    </select>
                </div>

                <div v-if="pending.action.needs_reason">
                    <label class="mb-1.5 block text-sm text-slate-600">دلیل (ثبت می‌شود)</label>
                    <input
                        v-model="reason"
                        type="text"
                        maxlength="255"
                        class="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none focus:ring-4 focus:ring-brand-100"
                    />
                </div>

                <div class="flex gap-2">
                    <button
                        type="button"
                        class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-700"
                        @click="confirmPending"
                    >
                        ثبت
                    </button>
                    <button
                        type="button"
                        class="rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        @click="pending = null"
                    >
                        انصراف
                    </button>
                </div>
            </div>

            <div class="card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[52rem] text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-start font-medium">نوبت</th>
                                <th class="px-4 py-3 text-start font-medium">پلاک</th>
                                <th class="px-4 py-3 text-start font-medium">راننده</th>
                                <th class="px-4 py-3 text-start font-medium">بار</th>
                                <th class="px-4 py-3 text-start font-medium">ساعت</th>
                                <th class="px-4 py-3 text-start font-medium">وضعیت</th>
                                <th class="px-4 py-3 text-start font-medium">عملیات</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in rows"
                                :key="row.ulid"
                                :class="['transition', busy === row.ulid ? 'opacity-50' : 'hover:bg-slate-50/70']"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('staff.queue.show', row.ulid)"
                                        class="num text-base font-bold text-slate-900 hover:text-brand-700"
                                    >
                                        {{ row.number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <PlateBadge v-if="row.truck" :plate="row.truck.plate" size="sm" />
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-slate-800">{{ row.driver?.name ?? '—' }}</p>
                                    <p class="num text-xs text-slate-400">{{ row.driver?.mobile }}</p>
                                </td>
                                <td class="px-4 py-3 text-slate-700">{{ row.product?.name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <p class="num text-slate-800">{{ row.time }}</p>
                                    <p v-if="row.wait_minutes !== null" class="text-xs text-slate-400">
                                        انتظار {{ duration(row.wait_minutes) }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <StatusBadge :tone="row.status_tone" :label="row.status_label" size="sm" />
                                    <p
                                        v-if="row.priority"
                                        :title="row.priority_reason ?? ''"
                                        class="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900"
                                    >
                                        اولویت <span class="num">{{ row.priority }}</span>
                                    </p>
                                    <p v-if="row.cancelled_by_label" class="mt-1 text-xs font-medium text-rose-600">
                                        {{ row.cancelled_by_label }}
                                    </p>
                                    <p v-if="row.loading_point" class="mt-1 text-xs text-slate-400">
                                        {{ row.loading_point }}
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5">
                                        <button
                                            v-if="canManageQueue && row.is_active"
                                            type="button"
                                            :disabled="busy === row.ulid"
                                            class="rounded-lg border border-amber-200 px-2.5 py-1 text-xs font-medium text-amber-800 transition hover:bg-amber-50 disabled:opacity-50"
                                            @click="openPriority(row)"
                                        >
                                            اولویت
                                        </button>

                                        <button
                                            v-for="action in row.actions"
                                            :key="action.value"
                                            type="button"
                                            :disabled="busy === row.ulid"
                                            :class="[
                                                'rounded-lg px-2.5 py-1 text-xs font-medium transition disabled:opacity-50',
                                                action.is_rollback
                                                    ? 'border border-dashed border-slate-300 text-slate-500 hover:bg-slate-100'
                                                    : action.tone === 'failed'
                                                      ? 'border border-rose-200 text-rose-700 hover:bg-rose-50'
                                                      : 'border border-brand-200 bg-brand-50 text-brand-700 hover:bg-brand-100',
                                            ]"
                                            @click="run(row, action)"
                                        >
                                            {{ action.label }}
                                        </button>

                                        <span v-if="!row.actions.length" class="text-xs text-slate-400">—</span>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!rows.length">
                                <td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">
                                    نوبتی با این فیلتر وجود ندارد.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </StaffLayout>
</template>
