<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarRange, Save } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Accounting', href: '/settings/accounting' },
        ],
    },
});

const props = defineProps<{
    settings: { period_start_day: number };
}>();

const startDay = ref<number>(props.settings.period_start_day);
const saving = ref(false);

const ordinal = (n: number) => {
    const s = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;

    return n + (s[(v - 20) % 10] || s[v] || s[0]);
};

const fmt = (d: Date) =>
    d.toLocaleDateString('en-PH', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });

const preview = computed(() => {
    const now = new Date();
    const day = startDay.value;
    const monthOffset = now.getDate() >= day ? 0 : -1;

    return [0, 1].map((i) => {
        const start = new Date(
            now.getFullYear(),
            now.getMonth() + monthOffset + i,
            day,
        );
        const end = new Date(
            start.getFullYear(),
            start.getMonth() + 1,
            day - 1,
        );

        return {
            label: i === 0 ? 'Current period' : 'Next period',
            range: `${fmt(start)} – ${fmt(end)}`,
        };
    });
});

const endDayLabel = computed(() =>
    startDay.value === 1
        ? 'the last day of the month'
        : `the ${ordinal(startDay.value - 1)} of the next month`,
);

const save = () => {
    saving.value = true;
    router.post(
        '/settings/accounting',
        { period_start_day: startDay.value },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Accounting settings saved.'),
            onError: (errors) => toast.error(Object.values(errors).join(' ')),
            onFinish: () => {
                saving.value = false;
            },
        },
    );
};
</script>

<template>
    <Head title="Accounting Settings" />

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">Accounting Settings</h2>
            <p class="text-sm text-muted-foreground">
                Set when each accounting period opens and closes.
            </p>
        </div>

        <div class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
            <div class="flex items-center gap-2">
                <CalendarRange class="h-4 w-4 text-primary" />
                <h3 class="text-sm font-semibold">Accounting period</h3>
            </div>
            <p class="-mt-2 text-xs text-muted-foreground">
                The financial page's period table, its "This period" preset and
                the bills due box all follow this cycle.
            </p>

            <div class="max-w-xs">
                <label
                    for="period-start-day"
                    class="mb-1.5 block text-xs font-medium text-muted-foreground"
                >
                    Period starts on day
                </label>
                <select
                    id="period-start-day"
                    v-model.number="startDay"
                    class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:ring-2 focus:ring-primary focus:outline-none"
                >
                    <option v-for="d in 28" :key="d" :value="d">
                        {{ ordinal(d) }}{{ d === 1 ? ' (calendar month)' : '' }}
                    </option>
                </select>
                <p class="mt-1.5 text-xs text-muted-foreground">
                    Each period runs from the {{ ordinal(startDay) }} to
                    {{ endDayLabel }}. Days 29–31 aren't offered because not
                    every month has them.
                </p>
            </div>

            <dl class="grid max-w-lg gap-2 sm:grid-cols-2">
                <div
                    v-for="p in preview"
                    :key="p.label"
                    class="rounded-lg border bg-muted/30 px-3 py-2"
                >
                    <dt
                        class="text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ p.label }}
                    </dt>
                    <dd class="text-sm font-semibold tabular-nums">
                        {{ p.range }}
                    </dd>
                </div>
            </dl>

            <div class="flex justify-end pt-1">
                <button
                    @click="save"
                    :disabled="saving"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-50"
                >
                    <Save class="h-3.5 w-3.5" />
                    {{ saving ? 'Saving…' : 'Save Settings' }}
                </button>
            </div>
        </div>
    </div>
</template>
