<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    BarChart3,
    CheckCircle,
    Flame,
    Pencil,
    Plus,
    Search,
    Trash2,
    UserPlus,
    Users,
    Wallet,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import PayrollReport from '@/components/PayrollReport.vue';
import api from '@/utils/api';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'HRIS', href: '/hris' },
        ],
    },
});

interface Employee {
    id: number;
    name: string;
    position: string | null;
    employment_type: 'full_time' | 'part_time' | 'contractual';
    salary_type: 'monthly' | 'daily' | 'hourly';
    base_rate: number;
    is_active: boolean;
    hired_at: string | null;
    notes: string | null;
}

interface PayrollRecord {
    id: number;
    employee_id: number;
    employee_name: string;
    period_start: string;
    period_end: string;
    days_worked: number;
    gross_pay: number;
    deductions: number;
    net_pay: number;
    status: 'pending' | 'approved' | 'paid';
    notes: string | null;
    paid_at: string | null;
}

type Tab = 'employees' | 'payroll' | 'reports';

const props = defineProps<{
    employees: Employee[];
    payrollRecords: PayrollRecord[];
}>();

// ── State ──────────────────────────────────────────────────────────────────────
const employees = ref<Employee[]>([...props.employees]);
const payrollRecords = ref<PayrollRecord[]>([...props.payrollRecords]);
const tab = ref<Tab>('employees');
const loading = ref(false);
const tabs = [
    { key: 'employees' as const, label: 'Employees', icon: Users },
    { key: 'payroll' as const, label: 'Payroll', icon: Wallet },
    { key: 'reports' as const, label: 'Payroll report', icon: BarChart3 },
];

// Manila calendar date, so defaults don't slip to yesterday before 8am
const manilaToday = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
}).format(new Date());
const dateLabel = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    weekday: 'long',
    month: 'short',
    day: 'numeric',
}).format(new Date());

// ── Employee form ──────────────────────────────────────────────────────────────
const showEmpModal = ref(false);
const editingEmp = ref<Employee | null>(null);
const emptyEmpForm = () => ({
    name: '',
    position: '',
    employment_type: 'full_time' as Employee['employment_type'],
    salary_type: 'monthly' as Employee['salary_type'],
    base_rate: 0,
    is_active: true,
    hired_at: '',
    notes: '',
});
const empForm = ref(emptyEmpForm());

function openAddEmp() {
    editingEmp.value = null;
    empForm.value = emptyEmpForm();
    showEmpModal.value = true;
}

function openEditEmp(emp: Employee) {
    editingEmp.value = emp;
    empForm.value = {
        name: emp.name,
        position: emp.position ?? '',
        employment_type: emp.employment_type,
        salary_type: emp.salary_type,
        base_rate: emp.base_rate,
        is_active: emp.is_active,
        hired_at: emp.hired_at ?? '',
        notes: emp.notes ?? '',
    };
    showEmpModal.value = true;
}

async function saveEmployee() {
    loading.value = true;

    try {
        const payload = {
            ...empForm.value,
            base_rate: Number(empForm.value.base_rate),
            hired_at: empForm.value.hired_at || null,
            notes: empForm.value.notes || null,
            position: empForm.value.position || null,
        };

        if (editingEmp.value) {
            const id = editingEmp.value.id;
            const res = await api.put(`/api/v1/hris/employees/${id}`, payload);
            const idx = employees.value.findIndex((e) => e.id === id);

            if (idx !== -1) {
                employees.value[idx] = res.data.data;
            }

            toast.success('Employee updated.');
        } else {
            const res = await api.post('/api/v1/hris/employees', payload);
            employees.value.push(res.data.data);
            toast.success('Employee added.');
        }

        showEmpModal.value = false;
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to save employee.');
    } finally {
        loading.value = false;
    }
}

async function deleteEmployee(emp: Employee) {
    if (!confirm(`Delete ${emp.name}? This cannot be undone.`)) {
        return;
    }

    try {
        await api.delete(`/api/v1/hris/employees/${emp.id}`);
        employees.value = employees.value.filter((e) => e.id !== emp.id);
        toast.success('Employee deleted.');
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to delete employee.');
    }
}

// ── Payroll form ───────────────────────────────────────────────────────────────
const showPayrollModal = ref(false);
const payrollForm = ref({
    employee_id: 0,
    period_start: '',
    period_end: '',
    days_worked: 0,
    gross_pay: 0,
    deductions: 0,
    notes: '',
});

const selectedEmpForPayroll = computed(
    () =>
        employees.value.find((e) => e.id === payrollForm.value.employee_id) ??
        null,
);

function openAddPayroll() {
    payrollForm.value = {
        employee_id: employees.value.find((e) => e.is_active)?.id ?? 0,
        period_start: manilaToday,
        period_end: manilaToday,
        days_worked: 0,
        gross_pay: 0,
        deductions: 0,
        notes: '',
    };
    showPayrollModal.value = true;
}

// Hourly rates can't be derived from days, so leave gross pay as entered
function autoCalcGross() {
    const emp = selectedEmpForPayroll.value;

    if (!emp || emp.salary_type === 'hourly') {
        return;
    }

    const days = Number(payrollForm.value.days_worked);
    const dailyRate =
        emp.salary_type === 'daily' ? emp.base_rate : emp.base_rate / 22;
    payrollForm.value.gross_pay = Math.round(dailyRate * days * 100) / 100;
}

const netPayPreview = computed(() =>
    Math.max(
        0,
        Number(payrollForm.value.gross_pay) -
            Number(payrollForm.value.deductions),
    ),
);
const deductionsTooHigh = computed(
    () =>
        Number(payrollForm.value.deductions) >
        Number(payrollForm.value.gross_pay),
);
const periodInvalid = computed(
    () =>
        !!payrollForm.value.period_start &&
        !!payrollForm.value.period_end &&
        payrollForm.value.period_end < payrollForm.value.period_start,
);

async function savePayroll() {
    loading.value = true;

    try {
        const payload = {
            ...payrollForm.value,
            employee_id: Number(payrollForm.value.employee_id),
            days_worked: Number(payrollForm.value.days_worked),
            gross_pay: Number(payrollForm.value.gross_pay),
            deductions: Number(payrollForm.value.deductions),
            notes: payrollForm.value.notes || null,
        };
        const res = await api.post('/api/v1/hris/payroll', payload);
        payrollRecords.value.unshift(res.data.data);
        toast.success('Payroll record created.');
        showPayrollModal.value = false;
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to create payroll.');
    } finally {
        loading.value = false;
    }
}

async function markPaid(record: PayrollRecord) {
    if (
        !confirm(
            `Mark payroll for ${record.employee_name} as paid?\nThis records a ${money(record.net_pay)} payroll expense in Financial.`,
        )
    ) {
        return;
    }

    loading.value = true;

    try {
        const res = await api.post(`/api/v1/hris/payroll/${record.id}/pay`);
        const idx = payrollRecords.value.findIndex((r) => r.id === record.id);

        if (idx !== -1) {
            payrollRecords.value[idx] = res.data.data;
        }

        toast.success('Payroll marked as paid and expense recorded.');
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to mark as paid.');
    } finally {
        loading.value = false;
    }
}

async function deletePayroll(record: PayrollRecord) {
    if (!confirm(`Delete the payroll record for ${record.employee_name}?`)) {
        return;
    }

    try {
        await api.delete(`/api/v1/hris/payroll/${record.id}`);
        payrollRecords.value = payrollRecords.value.filter(
            (r) => r.id !== record.id,
        );
        toast.success('Payroll record deleted.');
    } catch (e: any) {
        toast.error(
            e.response?.data?.message ?? 'Cannot delete a paid payroll record.',
        );
    }
}

// ── Filters / computed ─────────────────────────────────────────────────────────
const empFilter = ref<'all' | 'active' | 'inactive'>('active');
const empSearch = ref('');
const empFilters = [
    { key: 'active' as const, label: 'Active' },
    { key: 'inactive' as const, label: 'Inactive' },
    { key: 'all' as const, label: 'All' },
];

const filteredEmployees = computed(() => {
    const query = empSearch.value.trim().toLowerCase();

    return employees.value.filter(
        (e) =>
            (empFilter.value === 'all' ||
                e.is_active === (empFilter.value === 'active')) &&
            (!query ||
                e.name.toLowerCase().includes(query) ||
                e.position?.toLowerCase().includes(query)),
    );
});

const payrollEmpFilter = ref(0);
const payrollStatusFilter = ref<'all' | 'unpaid' | 'paid'>('all');
const payrollStatusFilters = [
    { key: 'all' as const, label: 'All' },
    { key: 'unpaid' as const, label: 'Unpaid' },
    { key: 'paid' as const, label: 'Paid' },
];
const filteredPayroll = computed(() =>
    payrollRecords.value.filter(
        (r) =>
            (payrollEmpFilter.value === 0 ||
                r.employee_id === payrollEmpFilter.value) &&
            (payrollStatusFilter.value === 'all' ||
                (payrollStatusFilter.value === 'paid') ===
                    (r.status === 'paid')),
    ),
);
const payrollTotals = computed(() =>
    filteredPayroll.value.reduce(
        (totals, r) => ({
            gross: totals.gross + r.gross_pay,
            deductions: totals.deductions + r.deductions,
            net: totals.net + r.net_pay,
        }),
        { gross: 0, deductions: 0, net: 0 },
    ),
);

const activeCount = computed(
    () => employees.value.filter((e) => e.is_active).length,
);
const inactiveCount = computed(
    () => employees.value.length - activeCount.value,
);
const countActiveType = (type: Employee['employment_type']) =>
    employees.value.filter((e) => e.is_active && e.employment_type === type)
        .length;
const unpaidRecords = computed(() =>
    payrollRecords.value.filter((r) => r.status !== 'paid'),
);
const pendingPayrollCount = computed(() => unpaidRecords.value.length);
const pendingPayrollTotal = computed(() =>
    unpaidRecords.value.reduce((sum, r) => sum + r.net_pay, 0),
);
// Counted by the date money was released, matching the payroll report
const paidThisMonth = computed(() =>
    payrollRecords.value
        .filter(
            (r) =>
                r.status === 'paid' &&
                r.paid_at?.startsWith(manilaToday.slice(0, 7)),
        )
        .reduce((sum, r) => sum + r.net_pay, 0),
);

// ── Helpers ────────────────────────────────────────────────────────────────────
function money(amount: number) {
    return new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(amount ?? 0);
}

function initials(name: string) {
    return name
        .split(' ')
        .map((n) => n[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function fmtDate(value: string | null) {
    if (!value) {
        return '—';
    }

    // Date-only values are pinned to midday so no timezone shifts the day
    const date = new Date(
        value.length === 10 ? `${value}T12:00:00` : value.replace(' ', 'T'),
    );

    return Number.isNaN(date.getTime())
        ? value
        : new Intl.DateTimeFormat('en-PH', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
          }).format(date);
}

const empTypeLabel: Record<string, string> = {
    full_time: 'Full-time',
    part_time: 'Part-time',
    contractual: 'Contractual',
};
const salaryTypeLabel: Record<string, string> = {
    monthly: '/mo',
    daily: '/day',
    hourly: '/hr',
};
const statusLabel: Record<string, string> = {
    pending: 'Pending',
    approved: 'Approved',
    paid: 'Paid',
};
</script>

<template>
    <Head title="HRIS" />
    <div class="grill-hris">
        <header class="hris-heading">
            <div>
                <p class="eyebrow">
                    <Flame :size="14" aria-hidden="true" /> BYPASS GRILL /
                    PEOPLE &amp; PAYROLL
                </p>
                <h1>The crew behind <em>the grill.</em></h1>
                <p class="intro">
                    Manage your team, settle payroll, and see where payroll
                    money goes.
                </p>
            </div>
            <div class="heading-meta">
                <span>{{ dateLabel }} · Manila</span>
            </div>
        </header>

        <section class="work-bar" aria-label="Payroll actions">
            <div class="work-bar-copy">
                <strong>{{
                    pendingPayrollCount
                        ? `${pendingPayrollCount} payroll record${pendingPayrollCount === 1 ? '' : 's'} to settle`
                        : 'Payroll is all settled.'
                }}</strong
                ><span>{{
                    pendingPayrollCount
                        ? `${money(pendingPayrollTotal)} in net pay is waiting. Marking a record paid adds it to Financial as a payroll expense.`
                        : 'Create a payroll record when a pay period closes, then mark it paid once the money is released.'
                }}</span>
            </div>
            <div class="work-actions">
                <button class="primary-action" @click="openAddPayroll">
                    <Plus :size="17" aria-hidden="true" />New payroll</button
                ><button class="secondary-action" @click="openAddEmp">
                    <UserPlus :size="17" aria-hidden="true" />Add employee
                </button>
            </div>
        </section>

        <section class="metric-grid" aria-label="Key figures">
            <article class="metric metric-featured">
                <p>Paid this month</p>
                <strong>{{ money(paidThisMonth) }}</strong
                ><span>Net payroll released since the 1st</span>
            </article>
            <article class="metric">
                <p>Awaiting payment</p>
                <strong>{{ money(pendingPayrollTotal) }}</strong
                ><span
                    >{{ pendingPayrollCount }} unpaid record{{
                        pendingPayrollCount === 1 ? '' : 's'
                    }}</span
                >
            </article>
            <article class="metric">
                <p>Active staff</p>
                <strong>{{ activeCount }}</strong
                ><span>{{ inactiveCount }} inactive</span>
            </article>
            <article class="metric">
                <p>Full-time staff</p>
                <strong>{{ countActiveType('full_time') }}</strong
                ><span
                    >{{ countActiveType('part_time') }} part-time ·
                    {{ countActiveType('contractual') }} contractual</span
                >
            </article>
        </section>

        <nav class="tabs" role="tablist" aria-label="HRIS sections">
            <button
                v-for="t in tabs"
                :key="t.key"
                role="tab"
                :aria-selected="tab === t.key"
                :class="{ active: tab === t.key }"
                @click="tab = t.key"
            >
                <component :is="t.icon" :size="15" aria-hidden="true" />{{
                    t.label
                }}<span
                    v-if="t.key === 'payroll' && pendingPayrollCount"
                    class="count-badge"
                    >{{ pendingPayrollCount }}</span
                >
            </button>
        </nav>

        <!-- ── Employees ─────────────────────────────────────────────────── -->
        <section v-if="tab === 'employees'" class="panel" role="tabpanel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">TEAM DIRECTORY</p>
                    <h2>
                        Employees
                        <span class="count-badge">{{
                            filteredEmployees.length
                        }}</span>
                    </h2>
                </div>
                <button class="text-action" @click="openAddEmp">
                    <Plus :size="15" aria-hidden="true" />Add employee
                </button>
            </div>
            <div class="toolbar">
                <div class="chips" role="group" aria-label="Filter by status">
                    <button
                        v-for="f in empFilters"
                        :key="f.key"
                        :class="{ active: empFilter === f.key }"
                        :aria-pressed="empFilter === f.key"
                        @click="empFilter = f.key"
                    >
                        {{ f.label }}
                    </button>
                </div>
                <label class="search"
                    ><Search :size="14" aria-hidden="true" /><span
                        class="sr-only"
                        >Search employees</span
                    ><input
                        v-model="empSearch"
                        type="search"
                        placeholder="Search by name or position…"
                /></label>
            </div>

            <div v-if="!filteredEmployees.length" class="empty-state">
                <Users :size="26" aria-hidden="true" />
                <h3>
                    {{
                        employees.length
                            ? 'No employees match.'
                            : 'No employees yet.'
                    }}
                </h3>
                <p>
                    {{
                        employees.length
                            ? 'Try another filter or search.'
                            : 'Add your first employee to start recording payroll.'
                    }}
                </p>
            </div>
            <template v-else>
                <div class="card-list">
                    <article
                        v-for="emp in filteredEmployees"
                        :key="emp.id"
                        class="row-card"
                    >
                        <span class="avatar" aria-hidden="true">{{
                            initials(emp.name)
                        }}</span>
                        <div class="row-card-body">
                            <div class="row-card-title">
                                <strong>{{ emp.name }}</strong
                                ><span
                                    class="status"
                                    :class="
                                        emp.is_active
                                            ? 'status-paid'
                                            : 'status-muted'
                                    "
                                    >{{
                                        emp.is_active ? 'Active' : 'Inactive'
                                    }}</span
                                >
                            </div>
                            <p>
                                {{
                                    emp.position ??
                                    empTypeLabel[emp.employment_type]
                                }}
                                · {{ money(emp.base_rate)
                                }}{{ salaryTypeLabel[emp.salary_type] }}
                            </p>
                            <p v-if="emp.hired_at">
                                Hired {{ fmtDate(emp.hired_at) }}
                            </p>
                        </div>
                        <div class="row-actions">
                            <button
                                class="icon-button"
                                :aria-label="`Edit ${emp.name}`"
                                @click="openEditEmp(emp)"
                            >
                                <Pencil :size="15" /></button
                            ><button
                                class="icon-button danger"
                                :aria-label="`Delete ${emp.name}`"
                                @click="deleteEmployee(emp)"
                            >
                                <Trash2 :size="15" />
                            </button>
                        </div>
                    </article>
                </div>

                <div
                    class="table-scroll desktop-only"
                    tabindex="0"
                    role="region"
                    aria-label="Employees table"
                >
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Employee</th>
                                <th scope="col">Type</th>
                                <th scope="col" class="amount">Base rate</th>
                                <th scope="col">Status</th>
                                <th scope="col">Hired</th>
                                <th scope="col">Notes</th>
                                <th scope="col" class="actions-col">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="emp in filteredEmployees" :key="emp.id">
                                <td>
                                    <div class="person">
                                        <span
                                            class="avatar"
                                            aria-hidden="true"
                                            >{{ initials(emp.name) }}</span
                                        >
                                        <div>
                                            <strong>{{ emp.name }}</strong
                                            ><small>{{
                                                emp.position ?? 'No position'
                                            }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status status-muted">{{
                                        empTypeLabel[emp.employment_type]
                                    }}</span>
                                </td>
                                <td class="amount">
                                    {{ money(emp.base_rate)
                                    }}<small class="rate-unit">{{
                                        salaryTypeLabel[emp.salary_type]
                                    }}</small>
                                </td>
                                <td>
                                    <span
                                        class="status"
                                        :class="
                                            emp.is_active
                                                ? 'status-paid'
                                                : 'status-muted'
                                        "
                                        >{{
                                            emp.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}</span
                                    >
                                </td>
                                <td class="muted">
                                    {{ fmtDate(emp.hired_at) }}
                                </td>
                                <td class="muted notes">
                                    {{ emp.notes ?? '—' }}
                                </td>
                                <td class="actions-col">
                                    <div class="row-actions">
                                        <button
                                            class="icon-button"
                                            :aria-label="`Edit ${emp.name}`"
                                            @click="openEditEmp(emp)"
                                        >
                                            <Pencil :size="15" /></button
                                        ><button
                                            class="icon-button danger"
                                            :aria-label="`Delete ${emp.name}`"
                                            @click="deleteEmployee(emp)"
                                        >
                                            <Trash2 :size="15" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </section>

        <!-- ── Payroll ───────────────────────────────────────────────────── -->
        <section v-if="tab === 'payroll'" class="panel" role="tabpanel">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">PAY PERIODS</p>
                    <h2>
                        Payroll records
                        <span class="count-badge">{{
                            filteredPayroll.length
                        }}</span>
                    </h2>
                </div>
                <button class="text-action" @click="openAddPayroll">
                    <Plus :size="15" aria-hidden="true" />New payroll
                </button>
            </div>
            <div class="toolbar">
                <div class="chips" role="group" aria-label="Filter by status">
                    <button
                        v-for="f in payrollStatusFilters"
                        :key="f.key"
                        :class="{ active: payrollStatusFilter === f.key }"
                        :aria-pressed="payrollStatusFilter === f.key"
                        @click="payrollStatusFilter = f.key"
                    >
                        {{ f.label }}
                    </button>
                </div>
                <label class="select"
                    ><span class="sr-only">Filter by employee</span
                    ><select v-model.number="payrollEmpFilter">
                        <option :value="0">All employees</option>
                        <option
                            v-for="emp in employees"
                            :key="emp.id"
                            :value="emp.id"
                        >
                            {{ emp.name }}
                        </option>
                    </select></label
                >
            </div>

            <div v-if="!filteredPayroll.length" class="empty-state">
                <Wallet :size="26" aria-hidden="true" />
                <h3>No payroll records found.</h3>
                <p>
                    {{
                        payrollRecords.length
                            ? 'Try another status or employee.'
                            : 'Create a payroll record when a pay period closes.'
                    }}
                </p>
            </div>
            <template v-else>
                <div class="card-list">
                    <article
                        v-for="r in filteredPayroll"
                        :key="r.id"
                        class="payroll-card"
                    >
                        <div class="row-card-title">
                            <div>
                                <strong>{{ r.employee_name }}</strong>
                                <p>
                                    {{ fmtDate(r.period_start) }} –
                                    {{ fmtDate(r.period_end) }} ·
                                    {{ r.days_worked }} days
                                </p>
                            </div>
                            <span
                                class="status"
                                :class="`status-${r.status}`"
                                >{{ statusLabel[r.status] ?? r.status }}</span
                            >
                        </div>
                        <dl class="pay-breakdown">
                            <div>
                                <dt>Gross</dt>
                                <dd>{{ money(r.gross_pay) }}</dd>
                            </div>
                            <div>
                                <dt>Deductions</dt>
                                <dd class="deduction">
                                    {{
                                        r.deductions > 0
                                            ? `−${money(r.deductions)}`
                                            : '—'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt>Net pay</dt>
                                <dd class="net">{{ money(r.net_pay) }}</dd>
                            </div>
                        </dl>
                        <div v-if="r.status !== 'paid'" class="card-actions">
                            <button
                                class="pay-button"
                                :disabled="loading"
                                @click="markPaid(r)"
                            >
                                <CheckCircle
                                    :size="15"
                                    aria-hidden="true"
                                />Mark as paid</button
                            ><button
                                class="icon-button danger"
                                :aria-label="`Delete payroll for ${r.employee_name}`"
                                @click="deletePayroll(r)"
                            >
                                <Trash2 :size="15" />
                            </button>
                        </div>
                        <p v-else class="paid-note">
                            Paid {{ fmtDate(r.paid_at) }}
                        </p>
                    </article>
                </div>

                <div
                    class="table-scroll desktop-only"
                    tabindex="0"
                    role="region"
                    aria-label="Payroll records table"
                >
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Employee</th>
                                <th scope="col">Period</th>
                                <th scope="col" class="amount">Days</th>
                                <th scope="col" class="amount">Gross</th>
                                <th scope="col" class="amount">Deductions</th>
                                <th scope="col" class="amount">Net pay</th>
                                <th scope="col">Status</th>
                                <th scope="col" class="actions-col">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="r in filteredPayroll" :key="r.id">
                                <td>
                                    <strong>{{ r.employee_name }}</strong
                                    ><small v-if="r.notes" class="notes">{{
                                        r.notes
                                    }}</small>
                                </td>
                                <td class="muted">
                                    {{ fmtDate(r.period_start) }} –
                                    {{ fmtDate(r.period_end) }}
                                </td>
                                <td class="amount">{{ r.days_worked }}</td>
                                <td class="amount">{{ money(r.gross_pay) }}</td>
                                <td class="amount deduction">
                                    {{
                                        r.deductions > 0
                                            ? `−${money(r.deductions)}`
                                            : '—'
                                    }}
                                </td>
                                <td class="amount net">
                                    {{ money(r.net_pay) }}
                                </td>
                                <td>
                                    <span
                                        class="status"
                                        :class="`status-${r.status}`"
                                        >{{
                                            statusLabel[r.status] ?? r.status
                                        }}</span
                                    ><small v-if="r.paid_at"
                                        >Paid {{ fmtDate(r.paid_at) }}</small
                                    >
                                </td>
                                <td class="actions-col">
                                    <div
                                        v-if="r.status !== 'paid'"
                                        class="row-actions"
                                    >
                                        <button
                                            class="pay-button"
                                            :disabled="loading"
                                            @click="markPaid(r)"
                                        >
                                            <CheckCircle
                                                :size="14"
                                                aria-hidden="true"
                                            />Pay</button
                                        ><button
                                            class="icon-button danger"
                                            :aria-label="`Delete payroll for ${r.employee_name}`"
                                            @click="deletePayroll(r)"
                                        >
                                            <Trash2 :size="15" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th scope="row" colspan="3">
                                    {{ filteredPayroll.length }} record{{
                                        filteredPayroll.length === 1 ? '' : 's'
                                    }}
                                </th>
                                <td class="amount">
                                    {{ money(payrollTotals.gross) }}
                                </td>
                                <td class="amount deduction">
                                    {{
                                        payrollTotals.deductions
                                            ? `−${money(payrollTotals.deductions)}`
                                            : '—'
                                    }}
                                </td>
                                <td class="amount net">
                                    {{ money(payrollTotals.net) }}
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-if="payrollRecords.length >= 100" class="list-note">
                    Showing the latest 100 payroll records. Use the payroll
                    report for older periods.
                </p>
            </template>
        </section>

        <!-- ── Payroll report ────────────────────────────────────────────── -->
        <PayrollReport
            v-if="tab === 'reports'"
            role="tabpanel"
            :employees="employees"
        />

        <footer class="hris-footer">
            <span>BYPASS GRILL · PEOPLE &amp; PAYROLL</span
            ><span>Paid payroll appears in Financial as payroll expenses.</span>
        </footer>
    </div>

    <!-- ── Employee modal ─────────────────────────────────────────────────── -->
    <Teleport to="body">
        <div
            v-if="showEmpModal"
            class="modal-backdrop"
            @click.self="showEmpModal = false"
            @keydown.esc="showEmpModal = false"
        >
            <div
                class="modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="emp-modal-title"
            >
                <div class="modal-head">
                    <div>
                        <p class="eyebrow">TEAM DIRECTORY</p>
                        <h2 id="emp-modal-title">
                            {{ editingEmp ? 'Edit employee' : 'Add employee' }}
                        </h2>
                    </div>
                    <button
                        class="icon-button"
                        aria-label="Close"
                        @click="showEmpModal = false"
                    >
                        <X :size="18" />
                    </button>
                </div>
                <form
                    id="emp-form"
                    class="modal-body"
                    @submit.prevent="saveEmployee"
                >
                    <label class="field"
                        >Full name *<input
                            v-model="empForm.name"
                            type="text"
                            required
                            placeholder="e.g. Juan dela Cruz"
                    /></label>
                    <label class="field"
                        >Position<input
                            v-model="empForm.position"
                            type="text"
                            placeholder="e.g. Cashier, Cook"
                    /></label>
                    <div class="field-row">
                        <label class="field"
                            >Employment type<select
                                v-model="empForm.employment_type"
                            >
                                <option value="full_time">Full-time</option>
                                <option value="part_time">Part-time</option>
                                <option value="contractual">Contractual</option>
                            </select></label
                        >
                        <label class="field"
                            >Salary type<select v-model="empForm.salary_type">
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                                <option value="hourly">Hourly</option>
                            </select></label
                        >
                    </div>
                    <div class="field-row">
                        <label class="field"
                            >Base rate (₱)<input
                                v-model.number="empForm.base_rate"
                                type="number"
                                min="0"
                                step="0.01"
                        /></label>
                        <label class="field"
                            >Hired date<input
                                v-model="empForm.hired_at"
                                type="date"
                        /></label>
                    </div>
                    <label class="check-row"
                        ><input v-model="empForm.is_active" type="checkbox" />
                        <span
                            ><strong>Active employee</strong>Inactive staff stay
                            in reports but can't get new payroll.</span
                        ></label
                    >
                    <label class="field"
                        >Notes<textarea
                            v-model="empForm.notes"
                            rows="2"
                            placeholder="Optional notes…"
                        />
                    </label>
                </form>
                <div class="modal-foot">
                    <button
                        type="button"
                        class="ghost-button"
                        @click="showEmpModal = false"
                    >
                        Cancel</button
                    ><button
                        type="submit"
                        form="emp-form"
                        class="solid-button"
                        :disabled="loading || !empForm.name.trim()"
                    >
                        {{
                            loading
                                ? 'Saving…'
                                : editingEmp
                                  ? 'Save changes'
                                  : 'Add employee'
                        }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ── Payroll modal ──────────────────────────────────────────────────── -->
    <Teleport to="body">
        <div
            v-if="showPayrollModal"
            class="modal-backdrop"
            @click.self="showPayrollModal = false"
            @keydown.esc="showPayrollModal = false"
        >
            <div
                class="modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="payroll-modal-title"
            >
                <div class="modal-head">
                    <div>
                        <p class="eyebrow">PAY PERIODS</p>
                        <h2 id="payroll-modal-title">New payroll record</h2>
                    </div>
                    <button
                        class="icon-button"
                        aria-label="Close"
                        @click="showPayrollModal = false"
                    >
                        <X :size="18" />
                    </button>
                </div>
                <form
                    id="payroll-form"
                    class="modal-body"
                    @submit.prevent="savePayroll"
                >
                    <label class="field"
                        >Employee *<select
                            v-model.number="payrollForm.employee_id"
                            required
                        >
                            <option
                                v-for="emp in employees.filter(
                                    (e) => e.is_active,
                                )"
                                :key="emp.id"
                                :value="emp.id"
                            >
                                {{ emp.name }} —
                                {{
                                    emp.position ??
                                    empTypeLabel[emp.employment_type]
                                }}
                            </option>
                        </select></label
                    >
                    <p v-if="selectedEmpForPayroll" class="field-hint">
                        {{
                            empTypeLabel[selectedEmpForPayroll.employment_type]
                        }}
                        · {{ money(selectedEmpForPayroll.base_rate)
                        }}{{
                            salaryTypeLabel[selectedEmpForPayroll.salary_type]
                        }}
                    </p>
                    <div class="field-row">
                        <label class="field"
                            >Period start *<input
                                v-model="payrollForm.period_start"
                                type="date"
                                required
                        /></label>
                        <label class="field"
                            >Period end *<input
                                v-model="payrollForm.period_end"
                                type="date"
                                required
                                :min="payrollForm.period_start || undefined"
                        /></label>
                    </div>
                    <p v-if="periodInvalid" class="field-error">
                        The period end must be on or after the start.
                    </p>
                    <label class="field"
                        >Days worked
                        <span class="input-with-button"
                            ><input
                                v-model.number="payrollForm.days_worked"
                                type="number"
                                min="0"
                                step="0.5"
                                @input="autoCalcGross"
                            /><button
                                type="button"
                                class="ghost-button"
                                :disabled="
                                    selectedEmpForPayroll?.salary_type ===
                                    'hourly'
                                "
                                @click="autoCalcGross"
                            >
                                Auto-calc
                            </button></span
                        ></label
                    >
                    <p
                        v-if="selectedEmpForPayroll?.salary_type === 'hourly'"
                        class="field-hint"
                    >
                        Hourly pay can't be worked out from days. Enter the
                        gross pay yourself.
                    </p>
                    <div class="field-row">
                        <label class="field"
                            >Gross pay (₱) *<input
                                v-model.number="payrollForm.gross_pay"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                        /></label>
                        <label class="field"
                            >Deductions (₱)<input
                                v-model.number="payrollForm.deductions"
                                type="number"
                                min="0"
                                step="0.01"
                        /></label>
                    </div>
                    <p v-if="deductionsTooHigh" class="field-error">
                        Deductions can't be more than the gross pay.
                    </p>
                    <div class="net-preview">
                        <span>Net pay</span
                        ><strong>{{ money(netPayPreview) }}</strong>
                    </div>
                    <label class="field"
                        >Notes<textarea
                            v-model="payrollForm.notes"
                            rows="2"
                            placeholder="Optional notes…"
                        />
                    </label>
                </form>
                <div class="modal-foot">
                    <button
                        type="button"
                        class="ghost-button"
                        @click="showPayrollModal = false"
                    >
                        Cancel</button
                    ><button
                        type="submit"
                        form="payroll-form"
                        class="solid-button"
                        :disabled="
                            loading ||
                            !payrollForm.employee_id ||
                            !payrollForm.period_start ||
                            !payrollForm.period_end ||
                            periodInvalid ||
                            deductionsTooHigh
                        "
                    >
                        {{ loading ? 'Saving…' : 'Create record' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.grill-hris {
    background: #f6f2e9;
    color: #24231e;
    min-height: 100%;
    padding: 34px clamp(18px, 3vw, 44px);
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
    display: flex;
    flex-direction: column;
    gap: 22px;
}
.hris-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
}
.hris-heading h1 {
    font-size: clamp(32px, 3.5vw, 48px);
    font-weight: 850;
    letter-spacing: -1.8px;
    line-height: 1.1;
    margin: 12px 0;
}
.hris-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
}
.intro {
    font-size: 13px;
    line-height: 1.6;
    color: #68665f;
}
.heading-meta {
    font-size: 11px;
    color: #68665f;
    white-space: nowrap;
    padding-top: 4px;
}
.work-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #24231e;
    color: #f6f2e9;
    border-radius: 6px;
    padding: 23px 25px;
}
.work-bar-copy strong {
    font-size: 16px;
    display: block;
}
.work-bar-copy span {
    font-size: 11px;
    color: #c3bfb3;
    display: block;
    line-height: 1.7;
    margin-top: 5px;
    max-width: 560px;
}
.work-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.primary-action,
.secondary-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 13px 15px;
}
.primary-action {
    background: #c3441c;
    color: #fff;
}
.primary-action:hover {
    background: #a73513;
}
.secondary-action {
    border: 1px solid #ffffff40;
    color: #f6f2e9;
}
.secondary-action:hover {
    background: #ffffff12;
}
.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.metric {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    padding: 21px;
    border-radius: 5px;
    min-width: 0;
}
.metric-featured {
    background: #f2e5d8;
    border-color: #e4c4ab;
}
.metric p {
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
}
.metric strong {
    display: block;
    font-size: clamp(22px, 2.4vw, 32px);
    letter-spacing: -1px;
    line-height: 1.3;
    margin: 12px 0 7px;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.metric-featured strong {
    color: #ad3b19;
}
.metric > span {
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
    display: block;
}
.tabs {
    display: flex;
    gap: 6px;
    border-bottom: 1px solid #ded7cb;
    overflow-x: auto;
}
.tabs button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    font-size: 12px;
    font-weight: 700;
    color: #68665f;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    white-space: nowrap;
}
.tabs button:hover {
    color: #24231e;
}
.tabs button.active {
    color: #ad3b19;
    border-bottom-color: #ad3b19;
}
.panel {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    padding: 23px;
    min-width: 0;
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
}
.panel h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.3;
}
.panel-heading .eyebrow {
    margin-bottom: 7px;
    font-size: 8px;
}
.count-badge {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    letter-spacing: 0;
    font-weight: 700;
    background: #f2e5d8;
    color: #ad3b19;
    padding: 4px 8px;
    border-radius: 20px;
    vertical-align: middle;
}
.tabs .count-badge {
    margin-left: 0;
    background: #c3441c;
    color: #fff;
}
.text-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 700;
    color: #ad3b19;
    white-space: nowrap;
}
.text-action:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}
.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.chips button {
    border: 1px solid #d4cdbf;
    border-radius: 30px;
    padding: 7px 13px;
    font-size: 11px;
    font-weight: 700;
    color: #4d4a42;
    background: #fffcf6;
}
.chips button.active {
    background: #24231e;
    border-color: #24231e;
    color: #f6f2e9;
}
.search,
.select {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    background: #fff;
    padding: 0 11px;
    color: #777268;
    min-width: min(100%, 280px);
}
.search input,
.select select {
    flex: 1;
    min-width: 0;
    border: 0;
    background: transparent;
    padding: 10px 0;
    font-size: 13px;
    color: #24231e;
    outline: none;
}
.search:focus-within,
.select:focus-within {
    outline: 2px solid #ad3b19;
    outline-offset: 2px;
}
.table-scroll {
    overflow-x: auto;
}
table {
    border-collapse: collapse;
    width: 100%;
    white-space: nowrap;
    font-size: 12px;
}
th {
    text-align: left;
    font-size: 9px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
    padding: 0 14px 13px 0;
    font-weight: 700;
}
td {
    padding: 13px 14px 13px 0;
    border-top: 1px solid #ece5da;
    vertical-align: middle;
}
td small {
    display: block;
    color: #777268;
    font-size: 10px;
    margin-top: 4px;
}
tfoot th,
tfoot td {
    border-top: 2px solid #ded7cb;
    padding-top: 14px;
    font-size: 12px;
    font-weight: 800;
    color: #24231e;
    text-transform: none;
    letter-spacing: 0;
}
.amount {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.rate-unit {
    display: inline;
    margin-left: 2px;
}
.net {
    font-weight: 800;
}
.deduction {
    color: #a03015;
}
.muted {
    color: #68665f;
}
.notes {
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.actions-col {
    width: 1%;
    padding-right: 0;
}
.person {
    display: flex;
    align-items: center;
    gap: 11px;
}
.avatar {
    display: grid;
    place-items: center;
    flex-shrink: 0;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #f2e5d8;
    color: #ad3b19;
    font-size: 11px;
    font-weight: 800;
}
.status {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 3px;
    background: #efeadf;
    color: #625b4c;
    white-space: nowrap;
}
.status-pending {
    background: #f7eccf;
    color: #7b5815;
}
.status-approved {
    background: #f8e2d7;
    color: #9d401d;
}
.status-paid {
    background: #e4edde;
    color: #376229;
}
.row-actions,
.card-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
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
.icon-button.danger {
    color: #a03015;
}
.icon-button.danger:hover {
    background: #fbe9e4;
}
.pay-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border: 1px solid #bcd1b1;
    background: #e4edde;
    color: #2f5723;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}
.pay-button:hover:not(:disabled) {
    background: #d6e5cd;
}
.pay-button:disabled {
    opacity: 0.55;
    cursor: wait;
}
.card-list {
    display: none;
}
.row-card {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 14px 0;
    border-top: 1px solid #ece5da;
}
.row-card-body {
    flex: 1;
    min-width: 0;
}
.row-card-title {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 8px;
    font-size: 13px;
}
.row-card-body p,
.row-card-title p {
    font-size: 11px;
    line-height: 1.6;
    color: #68665f;
    margin-top: 3px;
}
.payroll-card {
    padding: 15px 0;
    border-top: 1px solid #ece5da;
}
.pay-breakdown {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    background: #f6f2e9;
    border-radius: 4px;
    padding: 11px 12px;
    margin: 11px 0;
}
.pay-breakdown dt {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
}
.pay-breakdown dd {
    font-size: 13px;
    margin-top: 3px;
    font-variant-numeric: tabular-nums;
}
.card-actions .pay-button {
    flex: 1;
    padding: 11px;
}
.paid-note,
.list-note {
    font-size: 11px;
    color: #68665f;
}
.list-note {
    margin-top: 14px;
}
.empty-state {
    padding: 30px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    color: #777268;
}
.empty-state > svg {
    color: #ad3b19;
}
.empty-state h3 {
    color: #24231e;
    font-size: 15px;
    font-weight: 700;
}
.empty-state p {
    font-size: 12px;
    line-height: 1.7;
    max-width: 350px;
}
.hris-footer {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    padding-top: 18px;
    border-top: 1px solid #ded7cb;
    font-size: 9px;
    color: #777268;
}
.hris-footer > span:first-child {
    letter-spacing: 1px;
    font-weight: 700;
}
.grill-hris :focus-visible,
.modal :focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 3px;
}
.grill-hris button,
.modal button {
    cursor: pointer;
}

/* ── Modals ─────────────────────────────────────────────────────────────── */
.modal-backdrop {
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
.modal {
    display: flex;
    flex-direction: column;
    width: 100%;
    max-width: 520px;
    max-height: calc(100dvh - 32px);
    background: #fffcf6;
    color: #24231e;
    border-radius: 8px;
    box-shadow: 0 20px 60px #0005;
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
    overflow: hidden;
}
.modal-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    padding: 20px 22px 16px;
    border-bottom: 1px solid #ece5da;
}
.modal-head .eyebrow {
    font-size: 8px;
    margin-bottom: 6px;
}
.modal-head h2 {
    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.5px;
}
.modal-body {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px 22px;
    overflow-y: auto;
}
.modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 15px 22px;
    border-top: 1px solid #ece5da;
    background: #f6f2e9;
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
    min-width: 0;
}
.field input,
.field select,
.field textarea {
    width: 100%;
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
.field textarea {
    resize: none;
}
.field-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.field-hint,
.field-error {
    font-size: 11px;
    line-height: 1.6;
    margin-top: -6px;
}
.field-hint {
    color: #68665f;
}
.field-error {
    color: #a03015;
    font-weight: 700;
}
.input-with-button {
    display: flex;
    gap: 8px;
}
.input-with-button input {
    flex: 1;
}
.check-row {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    border: 1px solid #ded7cb;
    border-radius: 5px;
    background: #f6f2e9;
    padding: 12px;
    font-size: 11px;
    color: #68665f;
    line-height: 1.5;
    cursor: pointer;
}
.check-row input {
    margin-top: 2px;
    width: 16px;
    height: 16px;
    accent-color: #c3441c;
}
.check-row strong {
    display: block;
    font-size: 13px;
    color: #24231e;
}
.net-preview {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f2e5d8;
    border: 1px solid #e4c4ab;
    border-radius: 5px;
    padding: 13px 16px;
    font-size: 12px;
    font-weight: 700;
    color: #68665f;
}
.net-preview strong {
    font-size: 22px;
    letter-spacing: -0.5px;
    color: #ad3b19;
    font-variant-numeric: tabular-nums;
}
.solid-button,
.ghost-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 11px 16px;
    white-space: nowrap;
}
.solid-button {
    background: #c3441c;
    color: #fff;
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

@media (max-width: 1150px) {
    .work-bar {
        align-items: flex-start;
        flex-direction: column;
    }
}
@media (max-width: 900px) {
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .hris-heading {
        flex-wrap: wrap;
    }
}
@media (max-width: 767px) {
    .desktop-only {
        display: none;
    }
    .card-list {
        display: block;
    }
    .panel {
        padding: 18px;
    }
    .search,
    .select {
        width: 100%;
    }
}
@media (max-width: 540px) {
    .grill-hris {
        padding: 24px 16px;
    }
    .hris-heading h1 {
        font-size: 34px;
    }
    .metric-grid {
        gap: 10px;
    }
    .metric {
        padding: 15px;
    }
    .metric strong {
        font-size: 22px;
    }
    .work-bar {
        padding: 20px;
    }
    .work-actions {
        width: 100%;
        flex-direction: column;
    }
    .primary-action,
    .secondary-action {
        width: 100%;
    }
    .hris-footer {
        flex-direction: column;
        line-height: 1.6;
    }
    .modal-backdrop {
        padding: 0;
        align-items: stretch;
    }
    .modal {
        max-width: none;
        max-height: none;
        border-radius: 0;
    }
    .field-row {
        grid-template-columns: 1fr;
    }
    .modal-foot > button {
        flex: 1;
    }
}
</style>
