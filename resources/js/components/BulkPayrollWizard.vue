<script setup lang="ts">
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    CalendarDays,
    Check,
    RotateCcw,
    Search,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import api from '@/utils/api';

interface Employee {
    id: number;
    name: string;
    position: string | null;
    employment_type: string;
    salary_type: 'monthly' | 'daily' | 'hourly';
    base_rate: number;
    is_active: boolean;
}
type Frequency = 'daily' | 'weekly' | 'monthly' | 'custom';

const props = defineProps<{
    employees: Employee[];
    payrollRecords: {
        employee_id: number;
        period_start: string;
        period_end: string;
    }[];
}>();
const emit = defineEmits<{ close: []; released: [records: unknown[]] }>();

// ── Dates (Manila calendar) ────────────────────────────────────────────────────
const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const shiftDays = (iso: string, days: number) => {
    const date = new Date(`${iso}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);

    return date.toISOString().slice(0, 10);
};
const lastOfMonth = (yearMonth: string) => {
    const [year, month] = yearMonth.split('-').map(Number);

    return new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
};
const daySpan = (from: string, to: string) =>
    Math.round(
        (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86400000,
    ) + 1;
const longDate = new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    timeZone: 'UTC',
});
const fmtDate = (iso: string) => longDate.format(new Date(`${iso}T12:00:00Z`));
const money = (amount: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(amount ?? 0);
const round2 = (value: number) => Math.round(value * 100) / 100;
const plural = (count: number, word: string) =>
    `${count} ${word}${count === 1 ? '' : 's'}`;

// ── Steps ──────────────────────────────────────────────────────────────────────
const steps = ['Pay frequency', 'Employees', 'Review & release'];
const step = ref(1);

// Step 1: frequency and period
const frequency = ref<Frequency | null>(null);
const frequencies: { key: Frequency; label: string; description: string }[] = [
    { key: 'daily', label: 'Daily', description: 'Pay for a single day.' },
    { key: 'weekly', label: 'Weekly', description: 'Pay for a 7-day week.' },
    {
        key: 'monthly',
        label: 'Monthly',
        description: 'Pay for a full calendar month.',
    },
    {
        key: 'custom',
        label: 'Custom',
        description: 'Choose any start and end date.',
    },
];
const dailyDate = ref(today);
const weekStart = ref(shiftDays(today, -6));
const month = ref(today.slice(0, 7));
const customStart = ref(`${today.slice(0, 7)}-01`);
const customEnd = ref(today);

const period = computed<{ start: string; end: string } | null>(() => {
    switch (frequency.value) {
        case 'daily':
            return dailyDate.value
                ? { start: dailyDate.value, end: dailyDate.value }
                : null;
        case 'weekly':
            return weekStart.value
                ? { start: weekStart.value, end: shiftDays(weekStart.value, 6) }
                : null;
        case 'monthly':
            return month.value
                ? { start: `${month.value}-01`, end: lastOfMonth(month.value) }
                : null;
        case 'custom':
            return customStart.value &&
                customEnd.value &&
                customEnd.value >= customStart.value
                ? { start: customStart.value, end: customEnd.value }
                : null;
        default:
            return null;
    }
});
const periodDays = computed(() =>
    period.value ? daySpan(period.value.start, period.value.end) : 0,
);
const customInvalid = computed(
    () =>
        frequency.value === 'custom' &&
        !!customStart.value &&
        !!customEnd.value &&
        customEnd.value < customStart.value,
);

// Step 2: employees
const search = ref('');
const selectedIds = ref<number[]>([]);
const activeEmployees = computed(() =>
    props.employees
        .filter((e) => e.is_active)
        .sort((a, b) => a.name.localeCompare(b.name)),
);
const visibleEmployees = computed(() => {
    const query = search.value.trim().toLowerCase();

    return query
        ? activeEmployees.value.filter(
              (e) =>
                  e.name.toLowerCase().includes(query) ||
                  e.position?.toLowerCase().includes(query),
          )
        : activeEmployees.value;
});
const isSelected = (id: number) => selectedIds.value.includes(id);

function toggle(id: number) {
    selectedIds.value = isSelected(id)
        ? selectedIds.value.filter((selected) => selected !== id)
        : [...selectedIds.value, id];
}

function selectVisible() {
    selectedIds.value = [
        ...new Set([
            ...selectedIds.value,
            ...visibleEmployees.value.map((e) => e.id),
        ]),
    ];
}

const salaryUnit: Record<string, string> = {
    monthly: '/mo',
    daily: '/day',
    hourly: '/hr',
};

// Step 3: generated salaries
// Salary from the employee's rate: daily × days, hourly × 8 hrs × days,
// monthly as the full salary, a week's share, or ÷ 22 working days per day.
function calculate(emp: Employee) {
    const days = periodDays.value;
    const rate = emp.base_rate;

    if (emp.salary_type === 'daily') {
        return {
            amount: rate * days,
            basis: `${money(rate)}/day × ${plural(days, 'day')}`,
        };
    }

    if (emp.salary_type === 'hourly') {
        return {
            amount: rate * 8 * days,
            basis: `${money(rate)}/hr × 8 hrs × ${plural(days, 'day')}`,
        };
    }

    if (frequency.value === 'monthly') {
        return { amount: rate, basis: `${money(rate)} monthly salary` };
    }

    if (frequency.value === 'weekly') {
        return {
            amount: (rate * 12) / 52,
            basis: `${money(rate)}/mo × 12 ÷ 52 weeks`,
        };
    }

    return {
        amount: (rate / 22) * days,
        basis: `${money(rate)}/mo ÷ 22 × ${plural(days, 'day')}`,
    };
}

const lines = computed(() =>
    activeEmployees.value
        .filter((e) => isSelected(e.id))
        .map((employee) => {
            const { amount, basis } = calculate(employee);

            return { employee, suggested: round2(amount), basis };
        }),
);
const amounts = ref<Record<number, number | ''>>({});
const reviewKey = ref('');
const setupKey = computed(() =>
    [
        frequency.value,
        period.value?.start,
        period.value?.end,
        [...selectedIds.value].sort((a, b) => a - b).join(','),
    ].join('|'),
);

// Regenerate amounts only when the setup changed, so edits survive Back/Next
function prepareReview() {
    if (reviewKey.value === setupKey.value) {
        return;
    }

    amounts.value = Object.fromEntries(
        lines.value.map((line) => [line.employee.id, line.suggested]),
    );
    reviewKey.value = setupKey.value;
}

const amountOf = (id: number) => Number(amounts.value[id]);
const lineInvalid = (id: number) =>
    !Number.isFinite(amountOf(id)) || amountOf(id) <= 0;
const anyInvalid = computed(() =>
    lines.value.some((line) => lineInvalid(line.employee.id)),
);
const total = computed(() =>
    round2(
        lines.value.reduce(
            (sum, line) =>
                sum +
                (lineInvalid(line.employee.id)
                    ? 0
                    : amountOf(line.employee.id)),
            0,
        ),
    ),
);
const hasOverlap = (id: number) =>
    !!period.value &&
    props.payrollRecords.some(
        (r) =>
            r.employee_id === id &&
            r.period_start <= period.value!.end &&
            r.period_end >= period.value!.start,
    );

// ── Navigation ─────────────────────────────────────────────────────────────────
const maxReachable = computed(() =>
    !period.value ? 1 : selectedIds.value.length ? 3 : 2,
);

function goTo(target: number) {
    if (target > maxReachable.value) {
        return;
    }

    if (target === 3) {
        prepareReview();
    }

    step.value = target;
}

function cancel() {
    if (
        (frequency.value || selectedIds.value.length) &&
        !confirm('Discard this bulk payroll?')
    ) {
        return;
    }

    emit('close');
}

// ── Release ────────────────────────────────────────────────────────────────────
const releasing = ref(false);

async function release() {
    if (
        !period.value ||
        !frequency.value ||
        !lines.value.length ||
        anyInvalid.value ||
        releasing.value
    ) {
        return;
    }

    const count = lines.value.length;

    if (
        !confirm(
            `Release ${money(total.value)} to ${plural(count, 'employee')}?\nEach payment is recorded as paid payroll and added to Financial as a payroll expense.`,
        )
    ) {
        return;
    }

    releasing.value = true;

    try {
        const { data } = await api.post('/api/v1/hris/payroll/bulk-release', {
            frequency: frequency.value,
            period_start: period.value.start,
            period_end: period.value.end,
            items: lines.value.map((line) => ({
                employee_id: line.employee.id,
                amount: round2(amountOf(line.employee.id)),
                days_worked: periodDays.value,
            })),
        });
        toast.success(`Payroll released to ${plural(count, 'employee')}.`);
        emit('released', data.data);
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to release payroll.');
    } finally {
        releasing.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div class="wizard-backdrop" @keydown.esc="cancel">
            <div
                class="wizard"
                role="dialog"
                aria-modal="true"
                aria-labelledby="bulk-title"
            >
                <header class="wizard-head">
                    <div>
                        <p class="eyebrow">PAY PERIODS</p>
                        <h2 id="bulk-title">Bulk payroll</h2>
                    </div>
                    <button
                        class="icon-button"
                        aria-label="Close bulk payroll"
                        @click="cancel"
                    >
                        <X :size="18" />
                    </button>
                </header>

                <ol class="stepper" aria-label="Progress">
                    <li
                        v-for="(label, index) in steps"
                        :key="label"
                        :class="{
                            active: step === index + 1,
                            done: step > index + 1,
                        }"
                    >
                        <button
                            type="button"
                            :disabled="index + 1 > maxReachable"
                            :aria-current="
                                step === index + 1 ? 'step' : undefined
                            "
                            @click="goTo(index + 1)"
                        >
                            <span class="step-dot"
                                ><Check
                                    v-if="step > index + 1"
                                    :size="14"
                                    aria-hidden="true"
                                /><template v-else>{{
                                    index + 1
                                }}</template></span
                            ><span class="step-text"
                                ><small>Step {{ index + 1 }}</small
                                >{{ label }}</span
                            >
                        </button>
                    </li>
                </ol>

                <div class="wizard-body">
                    <!-- Step 1: frequency -->
                    <section v-if="step === 1" aria-labelledby="step1-title">
                        <h3 id="step1-title">How often is this salary paid?</h3>
                        <p class="step-copy">
                            The frequency sets the pay period and how each
                            salary is worked out.
                        </p>
                        <div
                            class="option-grid"
                            role="radiogroup"
                            aria-label="Pay frequency"
                        >
                            <button
                                v-for="option in frequencies"
                                :key="option.key"
                                type="button"
                                role="radio"
                                class="option"
                                :class="{ selected: frequency === option.key }"
                                :aria-checked="frequency === option.key"
                                @click="frequency = option.key"
                            >
                                <span class="option-check" aria-hidden="true"
                                    ><Check
                                        v-if="frequency === option.key"
                                        :size="13"
                                /></span>
                                <strong>{{ option.label }}</strong>
                                <span>{{ option.description }}</span>
                            </button>
                        </div>

                        <div v-if="frequency" class="period-box">
                            <div class="period-fields">
                                <label
                                    v-if="frequency === 'daily'"
                                    class="field"
                                    >Pay date<input
                                        v-model="dailyDate"
                                        type="date"
                                        required
                                /></label>
                                <label
                                    v-else-if="frequency === 'weekly'"
                                    class="field"
                                    >Week starting<input
                                        v-model="weekStart"
                                        type="date"
                                        required
                                /></label>
                                <label
                                    v-else-if="frequency === 'monthly'"
                                    class="field"
                                    >Month<input
                                        v-model="month"
                                        type="month"
                                        required
                                /></label>
                                <template v-else>
                                    <label class="field"
                                        >Start date<input
                                            v-model="customStart"
                                            type="date"
                                            required
                                    /></label>
                                    <label class="field"
                                        >End date<input
                                            v-model="customEnd"
                                            type="date"
                                            required
                                            :min="customStart || undefined"
                                    /></label>
                                </template>
                            </div>
                            <p v-if="customInvalid" class="field-error">
                                The end date must be on or after the start date.
                            </p>
                            <p v-else-if="period" class="period-summary">
                                <CalendarDays :size="15" aria-hidden="true" />
                                {{ fmtDate(period.start) }}
                                <template v-if="period.end !== period.start">
                                    – {{ fmtDate(period.end) }}</template
                                >
                                · {{ plural(periodDays, 'day') }}
                            </p>
                        </div>
                    </section>

                    <!-- Step 2: employees -->
                    <section
                        v-else-if="step === 2"
                        aria-labelledby="step2-title"
                    >
                        <h3 id="step2-title">Who are you paying?</h3>
                        <p class="step-copy">
                            Select the employees to include. Only active
                            employees are listed.
                        </p>
                        <div class="select-toolbar">
                            <label class="search"
                                ><Search :size="14" aria-hidden="true" /><span
                                    class="sr-only"
                                    >Search employees</span
                                ><input
                                    v-model="search"
                                    type="search"
                                    placeholder="Search by name or position…"
                            /></label>
                            <div class="select-actions">
                                <span class="selected-count"
                                    >{{ selectedIds.length }} selected</span
                                ><button
                                    type="button"
                                    class="link-button"
                                    :disabled="!visibleEmployees.length"
                                    @click="selectVisible"
                                >
                                    Select all</button
                                ><button
                                    type="button"
                                    class="link-button"
                                    :disabled="!selectedIds.length"
                                    @click="selectedIds = []"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
                        <div v-if="!activeEmployees.length" class="empty-state">
                            <Users :size="26" aria-hidden="true" />
                            <h4>No active employees.</h4>
                            <p>Add or reactivate employees first.</p>
                        </div>
                        <div
                            v-else-if="!visibleEmployees.length"
                            class="empty-state"
                        >
                            <Search :size="24" aria-hidden="true" />
                            <h4>No employees match.</h4>
                        </div>
                        <div v-else class="employee-grid">
                            <button
                                v-for="emp in visibleEmployees"
                                :key="emp.id"
                                type="button"
                                class="employee-card"
                                :class="{ selected: isSelected(emp.id) }"
                                :aria-pressed="isSelected(emp.id)"
                                @click="toggle(emp.id)"
                            >
                                <span class="select-box" aria-hidden="true"
                                    ><Check
                                        v-if="isSelected(emp.id)"
                                        :size="13"
                                /></span>
                                <strong>{{ emp.name }}</strong>
                                <span>{{ emp.position || 'No position' }}</span>
                                <span class="rate"
                                    >{{ money(emp.base_rate)
                                    }}{{ salaryUnit[emp.salary_type] }}</span
                                >
                            </button>
                        </div>
                    </section>

                    <!-- Step 3: review -->
                    <section v-else aria-labelledby="step3-title">
                        <h3 id="step3-title">Review and release</h3>
                        <p v-if="period" class="step-copy">
                            {{
                                frequencies.find((f) => f.key === frequency)
                                    ?.label
                            }}
                            payroll for {{ fmtDate(period.start)
                            }}<template v-if="period.end !== period.start">
                                – {{ fmtDate(period.end) }}</template
                            >. Amounts are generated from each employee's rate;
                            adjust any before releasing.
                        </p>
                        <div
                            class="review-table"
                            role="table"
                            aria-label="Payroll to release"
                        >
                            <div class="review-row review-head" role="row">
                                <span role="columnheader">Employee name</span
                                ><span role="columnheader" class="amount-col"
                                    >Amount</span
                                >
                            </div>
                            <div
                                v-for="line in lines"
                                :key="line.employee.id"
                                class="review-row"
                                role="row"
                            >
                                <div role="cell" class="review-name">
                                    <strong>{{ line.employee.name }}</strong>
                                    <small>{{ line.basis }}</small>
                                    <small
                                        v-if="hasOverlap(line.employee.id)"
                                        class="overlap"
                                        ><AlertTriangle
                                            :size="12"
                                            aria-hidden="true"
                                        />Already has payroll overlapping this
                                        period</small
                                    >
                                </div>
                                <div role="cell" class="amount-col">
                                    <label
                                        class="amount-input"
                                        :class="{
                                            invalid: lineInvalid(
                                                line.employee.id,
                                            ),
                                        }"
                                        ><span aria-hidden="true">₱</span
                                        ><input
                                            v-model.number="
                                                amounts[line.employee.id]
                                            "
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            inputmode="decimal"
                                            :aria-label="`Amount for ${line.employee.name}`"
                                    /></label>
                                    <button
                                        v-if="
                                            amountOf(line.employee.id) !==
                                            line.suggested
                                        "
                                        type="button"
                                        class="reset-button"
                                        :aria-label="`Reset ${line.employee.name} to ${money(line.suggested)}`"
                                        :title="`Reset to ${money(line.suggested)}`"
                                        @click="
                                            amounts[line.employee.id] =
                                                line.suggested
                                        "
                                    >
                                        <RotateCcw :size="13" />
                                    </button>
                                </div>
                            </div>
                            <div class="review-row review-total" role="row">
                                <span role="cell"
                                    >Total ·
                                    {{ plural(lines.length, 'employee') }}</span
                                ><strong role="cell" class="amount-col">{{
                                    money(total)
                                }}</strong>
                            </div>
                        </div>
                        <p v-if="anyInvalid" class="field-error">
                            Every amount must be more than ₱0.00.
                        </p>
                        <p class="release-note">
                            Releasing marks each payroll as paid and records it
                            in Financial as a payroll expense.
                        </p>
                    </section>
                </div>

                <footer class="wizard-foot">
                    <template v-if="step < 3">
                        <button
                            type="button"
                            class="ghost-button"
                            @click="step === 1 ? cancel() : goTo(step - 1)"
                        >
                            <ArrowLeft
                                v-if="step > 1"
                                :size="15"
                                aria-hidden="true"
                            />{{ step === 1 ? 'Cancel' : 'Back' }}</button
                        ><button
                            type="button"
                            class="solid-button"
                            :disabled="step + 1 > maxReachable"
                            @click="goTo(step + 1)"
                        >
                            Next<ArrowRight :size="15" aria-hidden="true" />
                        </button>
                    </template>
                    <template v-else>
                        <button
                            type="button"
                            class="ghost-button back-button"
                            :disabled="releasing"
                            @click="goTo(2)"
                        >
                            <ArrowLeft :size="15" aria-hidden="true" />Back
                        </button>
                        <span class="foot-spacer" />
                        <button
                            type="button"
                            class="ghost-button"
                            :disabled="releasing"
                            @click="cancel"
                        >
                            Cancel</button
                        ><button
                            type="button"
                            class="solid-button"
                            :disabled="releasing || anyInvalid || !lines.length"
                            @click="release"
                        >
                            {{ releasing ? 'Releasing…' : 'Release' }}
                        </button>
                    </template>
                </footer>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.wizard-backdrop {
    position: fixed;
    inset: 0;
    z-index: 50;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: #15120eb3;
    backdrop-filter: blur(2px);
}
.wizard {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 760px;
    max-height: calc(100dvh - 32px);
    background: #fffcf6;
    color: #24231e;
    border-radius: 8px;
    box-shadow: 0 20px 60px #0005;
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
    overflow: hidden;
}
.wizard-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 20px 24px 14px;
}
.eyebrow {
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
    margin-bottom: 6px;
}
.wizard-head h2 {
    font-size: 22px;
    font-weight: 850;
    letter-spacing: -0.6px;
}
.icon-button {
    display: grid;
    place-items: center;
    width: 34px;
    height: 34px;
    border-radius: 4px;
    color: #68665f;
}
.icon-button:hover {
    background: #efeadf;
    color: #24231e;
}

/* ── Stepper ── */
.stepper {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    padding: 0 24px 18px;
    border-bottom: 1px solid #ece5da;
    list-style: none;
    margin: 0;
}
.stepper li {
    position: relative;
}
.stepper li + li::before {
    content: '';
    position: absolute;
    top: 15px;
    left: calc(-50% + 22px);
    right: calc(50% + 22px);
    height: 2px;
    background: #ded7cb;
}
.stepper li.active::before,
.stepper li.done::before {
    background: #c3441c;
}
.stepper button {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 7px;
    width: 100%;
    text-align: center;
    color: #777268;
}
.stepper button:disabled {
    cursor: default;
}
.step-dot {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    border: 2px solid #ded7cb;
    background: #fffcf6;
    font-size: 12px;
    font-weight: 800;
    position: relative;
    z-index: 1;
}
.step-text {
    display: flex;
    flex-direction: column;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.35;
}
.step-text small {
    font-size: 8px;
    letter-spacing: 1px;
    text-transform: uppercase;
    font-weight: 700;
    color: #9a9384;
}
.stepper li.active .step-dot {
    background: #c3441c;
    border-color: #c3441c;
    color: #fff;
    box-shadow: 0 0 0 4px #f2e5d8;
}
.stepper li.active .step-text {
    color: #24231e;
}
.stepper li.done .step-dot {
    background: #24231e;
    border-color: #24231e;
    color: #f6f2e9;
}
.stepper li.done .step-text {
    color: #24231e;
}

/* ── Body ── */
.wizard-body {
    flex: 1;
    overflow-y: auto;
    padding: 22px 24px;
}
.wizard-body h3 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.4px;
}
.step-copy {
    font-size: 12px;
    line-height: 1.7;
    color: #68665f;
    margin: 6px 0 18px;
}
.option-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.option {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    text-align: left;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: #fff;
    padding: 16px 14px 14px;
    font-size: 11px;
    line-height: 1.5;
    color: #68665f;
    transition:
        border-color 0.15s,
        background 0.15s;
}
.option strong {
    font-size: 15px;
    color: #24231e;
}
.option:hover {
    border-color: #c9a58d;
}
.option.selected {
    border: 2px solid #c3441c;
    background: #f9ede4;
    padding: 15px 13px 13px;
}
.option-check,
.select-box {
    display: grid;
    place-items: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 1.5px solid #cfc7b8;
    background: #fff;
    color: #fff;
    margin-bottom: 4px;
}
.option.selected .option-check,
.employee-card.selected .select-box {
    background: #c3441c;
    border-color: #c3441c;
}
.period-box {
    margin-top: 18px;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: #f6f2e9;
    padding: 16px;
}
.period-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 220px));
    gap: 12px;
}
.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: #68665f;
}
.field input {
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    padding: 10px 11px;
    font-size: 13px;
    font-weight: 400;
    letter-spacing: 0;
    text-transform: none;
    color: #24231e;
    font-family: inherit;
}
.period-summary {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 13px;
    font-size: 12px;
    font-weight: 700;
    color: #24231e;
}
.period-summary svg {
    color: #ad3b19;
}
.field-error {
    margin-top: 12px;
    font-size: 11px;
    font-weight: 700;
    color: #a03015;
}

/* Step 2 */
.select-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}
.search {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    padding: 0 11px;
    color: #777268;
    flex: 1;
    min-width: min(100%, 240px);
}
.search input {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    padding: 10px 0;
    font-size: 13px;
    color: #24231e;
    outline: none;
}
.search:focus-within {
    outline: 2px solid #ad3b19;
    outline-offset: 2px;
}
.select-actions {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 11px;
}
.selected-count {
    font-weight: 700;
    color: #ad3b19;
    background: #f2e5d8;
    padding: 4px 9px;
    border-radius: 20px;
}
.link-button {
    font-weight: 700;
    color: #ad3b19;
}
.link-button:disabled {
    color: #b3ab9c;
    cursor: default;
}
.employee-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 10px;
}
.employee-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    text-align: left;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: #fff;
    padding: 14px;
    font-size: 11px;
    color: #68665f;
    transition:
        border-color 0.15s,
        background 0.15s,
        box-shadow 0.15s;
}
.employee-card:hover {
    border-color: #c9a58d;
}
.employee-card strong {
    font-size: 13px;
    color: #24231e;
}
.employee-card .select-box {
    position: absolute;
    top: 12px;
    right: 12px;
    margin: 0;
}
.employee-card .rate {
    margin-top: 6px;
    font-weight: 700;
    color: #4d4a42;
    font-variant-numeric: tabular-nums;
}
.employee-card.selected {
    border: 2px solid #c3441c;
    background: #f9ede4;
    padding: 13px;
    box-shadow: 0 4px 14px #c3441c1f;
}
.employee-card.selected .select-box {
    top: 11px;
    right: 11px;
}

/* Step 3 */
.review-table {
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: #fff;
    overflow: hidden;
}
.review-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 200px;
    align-items: center;
    gap: 14px;
    padding: 13px 16px;
    border-top: 1px solid #ece5da;
}
.review-head {
    border-top: 0;
    background: #f6f2e9;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
    padding-top: 11px;
    padding-bottom: 11px;
}
.review-name {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
    font-size: 13px;
}
.review-name small {
    font-size: 10px;
    color: #777268;
}
.review-name .overlap {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #7b5815;
    font-weight: 700;
}
.amount-col {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    text-align: right;
}
.amount-input {
    display: flex;
    align-items: center;
    gap: 4px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    padding: 0 10px;
    width: 160px;
    font-size: 13px;
    color: #68665f;
}
.amount-input:focus-within {
    outline: 2px solid #ad3b19;
    outline-offset: 1px;
}
.amount-input.invalid {
    border-color: #d88a74;
    background: #fdf2ee;
}
.amount-input input {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    padding: 9px 0;
    text-align: right;
    font-size: 14px;
    font-weight: 700;
    color: #24231e;
    font-variant-numeric: tabular-nums;
    outline: none;
}
.reset-button {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    border-radius: 4px;
    color: #ad3b19;
}
.reset-button:hover {
    background: #f2e5d8;
}
.review-total {
    background: #f2e5d8;
    font-size: 13px;
    font-weight: 700;
}
.review-total strong {
    font-size: 18px;
    color: #ad3b19;
    font-variant-numeric: tabular-nums;
}
.release-note {
    margin-top: 12px;
    font-size: 11px;
    line-height: 1.6;
    color: #68665f;
}
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 8px;
    padding: 28px 12px;
    color: #777268;
    font-size: 12px;
}
.empty-state > svg {
    color: #ad3b19;
}
.empty-state h4 {
    color: #24231e;
    font-size: 14px;
    font-weight: 700;
}

/* ── Footer ── */
.wizard-foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 15px 24px;
    border-top: 1px solid #ece5da;
    background: #f6f2e9;
}
.foot-spacer {
    flex: 1;
}
.solid-button,
.ghost-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 800;
    padding: 11px 18px;
    white-space: nowrap;
}
.solid-button {
    background: #c3441c;
    color: #fff;
    min-width: 110px;
}
.solid-button:hover:not(:disabled) {
    background: #a73513;
}
.ghost-button {
    border: 1px solid #d4cdbf;
    background: #fffcf6;
    color: #24231e;
}
.ghost-button:hover:not(:disabled) {
    background: #f2ede3;
}
.solid-button:disabled,
.ghost-button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.wizard :focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 3px;
}
.wizard button {
    cursor: pointer;
}
.wizard button:disabled {
    cursor: not-allowed;
}

@media (max-width: 720px) {
    .option-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 560px) {
    .wizard-backdrop {
        padding: 0;
        align-items: stretch;
    }
    .wizard {
        max-width: none;
        max-height: none;
        border-radius: 0;
    }
    .wizard-head,
    .wizard-body,
    .wizard-foot {
        padding-left: 16px;
        padding-right: 16px;
    }
    .stepper {
        padding: 0 8px 16px;
    }
    .step-text small {
        display: none;
    }
    .period-fields {
        grid-template-columns: 1fr;
    }
    .review-row {
        grid-template-columns: minmax(0, 1fr) auto;
        padding: 12px;
    }
    .amount-input {
        width: 128px;
    }
    .back-button {
        padding-left: 12px;
        padding-right: 12px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .option,
    .employee-card {
        transition: none;
    }
}
</style>
