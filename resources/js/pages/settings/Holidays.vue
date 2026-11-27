<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarDays, Check, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Settings', href: '/settings/profile' },
            { title: 'Holidays', href: '/settings/holidays' },
        ],
    },
});

type HolidayType = 'regular' | 'special' | 'special_working';
interface Holiday {
    id: number;
    date: string;
    name: string;
    type: HolidayType;
    confirmed: boolean;
}

const props = defineProps<{ holidays: Holiday[] }>();

const typeLabel: Record<HolidayType, string> = {
    regular: 'Regular holiday',
    special: 'Special non-working day',
    special_working: 'Special working day',
};

const years = computed(() => {
    const set = new Set(props.holidays.map((h) => h.date.slice(0, 4)));
    set.add(String(new Date().getFullYear()));

    return [...set].sort();
});
const year = ref(String(new Date().getFullYear()));
const shown = computed(() =>
    props.holidays.filter((h) => h.date.startsWith(year.value)),
);
const unconfirmed = computed(
    () => shown.value.filter((h) => !h.confirmed).length,
);

const weekday = (date: string) =>
    new Date(date + 'T00:00:00').toLocaleDateString('en-PH', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    });

const form = ref({ date: '', name: '', type: 'regular' as HolidayType });
const busy = ref(false);

const send = (
    method: 'post' | 'patch' | 'delete',
    url: string,
    data: Record<string, string | number | boolean>,
    done: string,
) => {
    busy.value = true;
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        onSuccess: () => toast.success(done),
        onError: (errors) => toast.error(Object.values(errors).join(' ')),
        onFinish: () => {
            busy.value = false;
        },
    });
};

const add = () => {
    if (!form.value.date || !form.value.name.trim()) {
        return;
    }

    send('post', '/settings/holidays', form.value, 'Holiday added.');
    year.value = form.value.date.slice(0, 4);
    form.value = { date: '', name: '', type: 'regular' };
};
const save = (
    holiday: Holiday,
    changes: Partial<Holiday>,
    done = 'Holiday updated.',
) =>
    send(
        'patch',
        `/settings/holidays/${holiday.id}`,
        { ...holiday, ...changes },
        done,
    );
const remove = (holiday: Holiday) => {
    if (confirm(`Remove ${holiday.name} (${holiday.date})?`)) {
        send(
            'delete',
            `/settings/holidays/${holiday.id}`,
            {},
            'Holiday removed.',
        );
    }
};
</script>

<template>
    <Head title="Holidays" />

    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold">Holidays</h2>
            <p class="text-sm text-muted-foreground">
                Philippine holidays used by the sales forecast. Long weekends
                and paydays that fall on them are treated as busier days,
                learned from your past sales.
            </p>
        </div>

        <div class="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <CalendarDays class="h-4 w-4 text-primary" />
                    <h3 class="text-sm font-semibold">Calendar</h3>
                </div>
                <div class="flex gap-1" role="group" aria-label="Year">
                    <button
                        v-for="y in years"
                        :key="y"
                        type="button"
                        :aria-pressed="year === y"
                        class="min-h-9 rounded-lg border px-3 text-sm aria-pressed:bg-primary aria-pressed:text-primary-foreground"
                        @click="year = y"
                    >
                        {{ y }}
                    </button>
                </div>
            </div>

            <p
                v-if="unconfirmed"
                class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                {{ unconfirmed }} date{{ unconfirmed === 1 ? '' : 's' }} still
                to confirm against the {{ year }}
                proclamation. They are already used by the forecast; mark each
                one confirmed once checked, or fix the date.
            </p>

            <ul v-if="shown.length" class="divide-y rounded-lg border">
                <li
                    v-for="holiday in shown"
                    :key="holiday.id"
                    class="flex flex-wrap items-center gap-3 p-3"
                >
                    <div class="min-w-36">
                        <p class="text-sm font-semibold">{{ holiday.name }}</p>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{ weekday(holiday.date) }}
                        </p>
                    </div>
                    <input
                        type="date"
                        :value="holiday.date"
                        :aria-label="`Date for ${holiday.name}`"
                        class="rounded-lg border bg-background px-2 py-1.5 text-sm"
                        :disabled="busy"
                        @change="
                            save(holiday, {
                                date: ($event.target as HTMLInputElement).value,
                            })
                        "
                    />
                    <select
                        :value="holiday.type"
                        :aria-label="`Type for ${holiday.name}`"
                        class="rounded-lg border bg-background px-2 py-1.5 text-sm"
                        :disabled="busy"
                        @change="
                            save(holiday, {
                                type: ($event.target as HTMLSelectElement)
                                    .value as HolidayType,
                            })
                        "
                    >
                        <option
                            v-for="(label, value) in typeLabel"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                    <button
                        v-if="!holiday.confirmed"
                        type="button"
                        class="flex items-center gap-1 rounded-lg border border-amber-400 px-2.5 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-50 dark:text-amber-200"
                        :disabled="busy"
                        @click="
                            save(
                                holiday,
                                { confirmed: true },
                                'Marked confirmed.',
                            )
                        "
                    >
                        <Check class="h-3.5 w-3.5" /> Confirm date
                    </button>
                    <span v-else class="text-xs text-emerald-700"
                        >Confirmed</span
                    >
                    <button
                        type="button"
                        class="ml-auto rounded-lg p-2 text-muted-foreground hover:bg-muted hover:text-destructive"
                        :aria-label="`Remove ${holiday.name}`"
                        :disabled="busy"
                        @click="remove(holiday)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </button>
                </li>
            </ul>
            <p
                v-else
                class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
            >
                No holidays saved for {{ year }} yet. Add them from the official
                proclamation below.
            </p>
        </div>

        <form
            class="space-y-4 rounded-xl border bg-card p-5 shadow-sm"
            @submit.prevent="add"
        >
            <h3 class="text-sm font-semibold">Add a holiday</h3>
            <div class="grid gap-3 sm:grid-cols-3">
                <label
                    class="grid gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    Date
                    <input
                        v-model="form.date"
                        type="date"
                        required
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    />
                </label>
                <label
                    class="grid gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    Name
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        maxlength="80"
                        placeholder="e.g. Eid'l Fitr"
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    />
                </label>
                <label
                    class="grid gap-1.5 text-xs font-medium text-muted-foreground"
                >
                    Type
                    <select
                        v-model="form.type"
                        class="rounded-lg border bg-background px-3 py-2 text-sm text-foreground"
                    >
                        <option
                            v-for="(label, value) in typeLabel"
                            :key="value"
                            :value="value"
                        >
                            {{ label }}
                        </option>
                    </select>
                </label>
            </div>
            <p class="text-xs text-muted-foreground">
                Special working days are recorded for reference but are not days
                off, so they never make a long weekend. After changing holidays,
                re-run the June replay in Reports → Predictions to see the
                effect.
            </p>
            <div class="flex justify-end">
                <button
                    type="submit"
                    :disabled="busy || !form.date || !form.name.trim()"
                    class="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                    <Plus class="h-3.5 w-3.5" /> Add holiday
                </button>
            </div>
        </form>
    </div>
</template>
