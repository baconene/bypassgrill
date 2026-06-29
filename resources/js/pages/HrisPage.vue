<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    Plus, X, Pencil, CheckCircle, Trash2, Search,
} from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'HRIS', href: '/hris' },
        ],
    },
})

interface Employee {
    id: number
    name: string
    position: string | null
    employment_type: 'full_time' | 'part_time' | 'contractual'
    salary_type: 'monthly' | 'daily' | 'hourly'
    base_rate: number
    is_active: boolean
    hired_at: string | null
    notes: string | null
}

interface PayrollRecord {
    id: number
    employee_id: number
    employee_name: string
    period_start: string
    period_end: string
    days_worked: number
    gross_pay: number
    deductions: number
    net_pay: number
    status: 'pending' | 'approved' | 'paid'
    notes: string | null
    paid_at: string | null
}

const props = defineProps<{
    employees: Employee[]
    payrollRecords: PayrollRecord[]
}>()

// ── State ──────────────────────────────────────────────────────────────────────
const employees = ref<Employee[]>([...props.employees])
const payrollRecords = ref<PayrollRecord[]>([...props.payrollRecords])
const tab = ref<'employees' | 'payroll'>('employees')
const loading = ref(false)

// ── Employee form ──────────────────────────────────────────────────────────────
const showEmpModal = ref(false)
const editingEmp = ref<Employee | null>(null)
const empForm = ref({
    name: '',
    position: '',
    employment_type: 'full_time' as Employee['employment_type'],
    salary_type: 'monthly' as Employee['salary_type'],
    base_rate: 0,
    is_active: true,
    hired_at: '',
    notes: '',
})

function openAddEmp() {
    editingEmp.value = null
    empForm.value = {
        name: '', position: '', employment_type: 'full_time', salary_type: 'monthly',
        base_rate: 0, is_active: true, hired_at: '', notes: '',
    }
    showEmpModal.value = true
}

function openEditEmp(emp: Employee) {
    editingEmp.value = emp
    empForm.value = {
        name: emp.name,
        position: emp.position ?? '',
        employment_type: emp.employment_type,
        salary_type: emp.salary_type,
        base_rate: emp.base_rate,
        is_active: emp.is_active,
        hired_at: emp.hired_at ?? '',
        notes: emp.notes ?? '',
    }
    showEmpModal.value = true
}

async function saveEmployee() {
    loading.value = true
    try {
        const payload = {
            ...empForm.value,
            base_rate: Number(empForm.value.base_rate),
            hired_at: empForm.value.hired_at || null,
            notes: empForm.value.notes || null,
            position: empForm.value.position || null,
        }
        if (editingEmp.value) {
            const res = await api.put(`/api/v1/hris/employees/${editingEmp.value.id}`, payload)
            const idx = employees.value.findIndex(e => e.id === editingEmp.value!.id)
            if (idx !== -1) employees.value[idx] = res.data.data
            toast.success('Employee updated.')
        } else {
            const res = await api.post('/api/v1/hris/employees', payload)
            employees.value.push(res.data.data)
            toast.success('Employee added.')
        }
        showEmpModal.value = false
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to save employee.')
    } finally {
        loading.value = false
    }
}

async function deleteEmployee(emp: Employee) {
    if (!confirm(`Delete ${emp.name}? This cannot be undone.`)) return
    try {
        await api.delete(`/api/v1/hris/employees/${emp.id}`)
        employees.value = employees.value.filter(e => e.id !== emp.id)
        toast.success('Employee deleted.')
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to delete employee.')
    }
}

// ── Payroll form ───────────────────────────────────────────────────────────────
const showPayrollModal = ref(false)
const payrollForm = ref({
    employee_id: 0,
    period_start: '',
    period_end: '',
    days_worked: 0,
    gross_pay: 0,
    deductions: 0,
    notes: '',
})

const selectedEmpForPayroll = computed(() =>
    employees.value.find(e => e.id === payrollForm.value.employee_id) ?? null
)

function openAddPayroll() {
    const today = new Date().toISOString().split('T')[0]
    payrollForm.value = {
        employee_id: employees.value.find(e => e.is_active)?.id ?? 0,
        period_start: today,
        period_end: today,
        days_worked: 0,
        gross_pay: 0,
        deductions: 0,
        notes: '',
    }
    showPayrollModal.value = true
}

function autoCalcGross() {
    const emp = selectedEmpForPayroll.value
    if (!emp) return
    const rate = emp.base_rate
    const days = Number(payrollForm.value.days_worked)
    if (emp.salary_type === 'daily') {
        payrollForm.value.gross_pay = Math.round(rate * days * 100) / 100
    } else if (emp.salary_type === 'monthly') {
        payrollForm.value.gross_pay = Math.round((rate / 22) * days * 100) / 100
    } else {
        payrollForm.value.gross_pay = 0
    }
}

const netPayPreview = computed(() =>
    Math.max(0, Number(payrollForm.value.gross_pay) - Number(payrollForm.value.deductions))
)

async function savePayroll() {
    loading.value = true
    try {
        const payload = {
            ...payrollForm.value,
            employee_id: Number(payrollForm.value.employee_id),
            days_worked: Number(payrollForm.value.days_worked),
            gross_pay: Number(payrollForm.value.gross_pay),
            deductions: Number(payrollForm.value.deductions),
            notes: payrollForm.value.notes || null,
        }
        const res = await api.post('/api/v1/hris/payroll', payload)
        payrollRecords.value.unshift(res.data.data)
        toast.success('Payroll record created.')
        showPayrollModal.value = false
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to create payroll.')
    } finally {
        loading.value = false
    }
}

async function markPaid(record: PayrollRecord) {
    const emp = employees.value.find(e => e.id === record.employee_id)
    if (!confirm(`Mark payroll for ${emp?.name ?? record.employee_name} as PAID?\nThis will create a ₱${formatCurrency(record.net_pay)} expense entry.`)) return
    loading.value = true
    try {
        const res = await api.post(`/api/v1/hris/payroll/${record.id}/pay`)
        const idx = payrollRecords.value.findIndex(r => r.id === record.id)
        if (idx !== -1) payrollRecords.value[idx] = res.data.data
        toast.success('Payroll marked as paid and expense recorded.')
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Failed to mark as paid.')
    } finally {
        loading.value = false
    }
}

async function deletePayroll(record: PayrollRecord) {
    if (!confirm('Delete this payroll record?')) return
    try {
        await api.delete(`/api/v1/hris/payroll/${record.id}`)
        payrollRecords.value = payrollRecords.value.filter(r => r.id !== record.id)
        toast.success('Payroll record deleted.')
    } catch (e: any) {
        toast.error(e.response?.data?.message ?? 'Cannot delete a paid payroll record.')
    }
}

// ── Filters / computed ─────────────────────────────────────────────────────────
const empFilter = ref<'all' | 'active' | 'inactive'>('active')
const empSearch = ref('')

const filteredEmployees = computed(() => {
    let list = employees.value
    if (empFilter.value === 'active') list = list.filter(e => e.is_active)
    else if (empFilter.value === 'inactive') list = list.filter(e => !e.is_active)
    if (empSearch.value.trim()) {
        const q = empSearch.value.toLowerCase()
        list = list.filter(e =>
            e.name.toLowerCase().includes(q) || e.position?.toLowerCase().includes(q)
        )
    }
    return list
})

const payrollEmpFilter = ref(0)
const filteredPayroll = computed(() => {
    if (payrollEmpFilter.value === 0) return payrollRecords.value
    return payrollRecords.value.filter(r => r.employee_id === payrollEmpFilter.value)
})

const totalPaidThisMonth = computed(() => {
    const now = new Date()
    const ym = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    return payrollRecords.value
        .filter(r => r.status === 'paid' && r.period_end?.startsWith(ym))
        .reduce((s, r) => s + r.net_pay, 0)
})

const pendingPayrollCount = computed(() =>
    payrollRecords.value.filter(r => r.status !== 'paid').length
)

// ── Helpers ────────────────────────────────────────────────────────────────────
function formatCurrency(v: number) {
    return (v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function initials(name: string) {
    return name.split(' ').map(n => n[0]).filter(Boolean).slice(0, 2).join('').toUpperCase()
}

function fmtDate(s: string | null) {
    if (!s) return '—'
    return new Date(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

const empTypeLabel: Record<string, string> = {
    full_time: 'Full-time', part_time: 'Part-time', contractual: 'Contractual',
}
const salaryTypeLabel: Record<string, string> = {
    monthly: '/mo', daily: '/day', hourly: '/hr',
}
const statusBadge: Record<string, string> = {
    pending:  'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400',
    approved: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    paid:     'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400',
}
</script>

<template>
    <Head title="HRIS" />

    <div class="space-y-5">
        <!-- Header -->
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold">Human Resources</h1>
                <p class="text-sm text-muted-foreground">Employee directory and payroll management</p>
            </div>
        </div>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground mb-1">Active Staff</p>
                <p class="text-3xl font-bold">{{ employees.filter(e => e.is_active).length }}</p>
                <p class="text-xs text-muted-foreground mt-1">{{ employees.filter(e => !e.is_active).length }} inactive</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground mb-1">Full-time</p>
                <p class="text-3xl font-bold">{{ employees.filter(e => e.employment_type === 'full_time' && e.is_active).length }}</p>
                <p class="text-xs text-muted-foreground mt-1">{{ employees.filter(e => e.employment_type === 'part_time' && e.is_active).length }} part-time</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground mb-1">Pending Payroll</p>
                <p class="text-3xl font-bold text-amber-500">{{ pendingPayrollCount }}</p>
                <p class="text-xs text-muted-foreground mt-1">awaiting payment</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs font-medium text-muted-foreground mb-1">Paid This Month</p>
                <p class="text-2xl font-bold text-green-600">₱{{ formatCurrency(totalPaidThisMonth) }}</p>
                <p class="text-xs text-muted-foreground mt-1">net payroll disbursed</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center border-b gap-4">
            <button
                v-for="t in [{ key: 'employees', label: 'Employees' }, { key: 'payroll', label: 'Payroll' }]"
                :key="t.key"
                @click="tab = t.key as typeof tab"
                class="flex items-center gap-1.5 px-1 py-2.5 text-sm font-medium border-b-2 transition-colors"
                :class="tab === t.key
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                {{ t.label }}
                <span v-if="t.key === 'payroll' && pendingPayrollCount > 0"
                    class="rounded-full bg-amber-500 text-white text-[10px] px-1.5 py-0.5 font-bold leading-none">
                    {{ pendingPayrollCount }}
                </span>
            </button>
        </div>

        <!-- ── Employees Tab ─────────────────────────────────────────────────── -->
        <div v-if="tab === 'employees'" class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex gap-1">
                    <button
                        v-for="f in ['all', 'active', 'inactive']" :key="f"
                        @click="empFilter = f as typeof empFilter"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium border transition"
                        :class="empFilter === f
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'border-border text-muted-foreground hover:bg-muted'">
                        {{ f.charAt(0).toUpperCase() + f.slice(1) }}
                    </button>
                </div>
                <div class="relative flex-1 min-w-0 sm:max-w-xs">
                    <Search class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
                    <input v-model="empSearch" type="text" placeholder="Search by name or position…"
                        class="w-full rounded-lg border bg-background pl-8 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <button @click="openAddEmp"
                    class="ml-auto flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 shrink-0">
                    <Plus class="h-4 w-4" />
                    <span class="hidden sm:inline">Add Employee</span>
                    <span class="sm:hidden">Add</span>
                </button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div v-if="filteredEmployees.length === 0" class="p-12 text-center text-muted-foreground text-sm">
                    No employees found.
                </div>
                <template v-else>
                    <!-- Mobile: card list -->
                    <div class="md:hidden divide-y">
                        <div v-for="emp in filteredEmployees" :key="emp.id"
                            class="flex items-center gap-3 px-4 py-3">
                            <div class="h-9 w-9 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-bold shrink-0">
                                {{ initials(emp.name) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-sm">{{ emp.name }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="emp.is_active
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                            : 'bg-muted text-muted-foreground'">
                                        {{ emp.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground mt-0.5 truncate">
                                    {{ emp.position ?? empTypeLabel[emp.employment_type] }} ·
                                    ₱{{ formatCurrency(emp.base_rate) }}{{ salaryTypeLabel[emp.salary_type] }}
                                </p>
                                <p v-if="emp.hired_at" class="text-xs text-muted-foreground">Hired {{ emp.hired_at }}</p>
                            </div>
                            <div class="flex gap-1 shrink-0">
                                <button @click="openEditEmp(emp)" class="p-2 rounded-lg hover:bg-muted" title="Edit">
                                    <Pencil class="h-3.5 w-3.5 text-muted-foreground" />
                                </button>
                                <button @click="deleteEmployee(emp)" class="p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete">
                                    <Trash2 class="h-3.5 w-3.5 text-red-500" />
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Desktop: full-width table -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-5 py-3 text-left">Employee</th>
                                    <th class="px-4 py-3 text-left">Position</th>
                                    <th class="px-4 py-3 text-left">Type</th>
                                    <th class="px-4 py-3 text-right">Base Rate</th>
                                    <th class="px-4 py-3 text-left">Status</th>
                                    <th class="px-4 py-3 text-left">Hired</th>
                                    <th class="px-4 py-3 text-left max-w-[200px]">Notes</th>
                                    <th class="px-4 py-3 w-24"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="emp in filteredEmployees" :key="emp.id"
                                    class="hover:bg-muted/30 group transition-colors">
                                    <td class="px-5 py-3">
                                        <div class="flex items-center gap-3">
                                            <div class="h-8 w-8 rounded-full bg-primary/10 text-primary flex items-center justify-center text-xs font-bold shrink-0">
                                                {{ initials(emp.name) }}
                                            </div>
                                            <span class="font-semibold">{{ emp.name }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground">{{ emp.position ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">
                                            {{ empTypeLabel[emp.employment_type] }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono font-medium tabular-nums">
                                        ₱{{ formatCurrency(emp.base_rate) }}<span class="text-muted-foreground text-xs font-normal">{{ salaryTypeLabel[emp.salary_type] }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="emp.is_active
                                                ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                                                : 'bg-muted text-muted-foreground'">
                                            {{ emp.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground text-xs whitespace-nowrap">{{ emp.hired_at ?? '—' }}</td>
                                    <td class="px-4 py-3 text-muted-foreground text-xs max-w-[200px] truncate">{{ emp.notes ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-1 justify-end opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button @click="openEditEmp(emp)" class="p-1.5 rounded-lg hover:bg-muted" title="Edit">
                                                <Pencil class="h-3.5 w-3.5 text-muted-foreground" />
                                            </button>
                                            <button @click="deleteEmployee(emp)" class="p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete">
                                                <Trash2 class="h-3.5 w-3.5 text-red-500" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>

        <!-- ── Payroll Tab ───────────────────────────────────────────────────── -->
        <div v-if="tab === 'payroll'" class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <select v-model="payrollEmpFilter"
                    class="rounded-lg border bg-card px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary flex-1 sm:flex-none sm:min-w-[200px]">
                    <option :value="0">All Employees</option>
                    <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                </select>
                <button @click="openAddPayroll"
                    class="ml-auto flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 shrink-0">
                    <Plus class="h-4 w-4" />
                    <span class="hidden sm:inline">New Payroll</span>
                    <span class="sm:hidden">New</span>
                </button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div v-if="filteredPayroll.length === 0" class="p-12 text-center text-muted-foreground text-sm">
                    No payroll records found.
                </div>
                <template v-else>
                    <!-- Mobile: card list -->
                    <div class="md:hidden divide-y">
                        <div v-for="r in filteredPayroll" :key="r.id" class="px-4 py-4 space-y-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-sm">{{ r.employee_name }}</p>
                                    <p class="text-xs text-muted-foreground mt-0.5">
                                        {{ r.period_start }} → {{ r.period_end }} · {{ r.days_worked }} days
                                    </p>
                                </div>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold capitalize shrink-0"
                                    :class="statusBadge[r.status]">
                                    {{ r.status }}
                                </span>
                            </div>
                            <div class="grid grid-cols-3 gap-2 bg-muted/40 rounded-lg p-3">
                                <div>
                                    <p class="text-[10px] text-muted-foreground uppercase font-medium mb-0.5">Gross</p>
                                    <p class="text-sm font-mono">₱{{ formatCurrency(r.gross_pay) }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-muted-foreground uppercase font-medium mb-0.5">Deductions</p>
                                    <p class="text-sm font-mono text-red-500">{{ r.deductions > 0 ? '−₱' + formatCurrency(r.deductions) : '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-[10px] text-muted-foreground uppercase font-medium mb-0.5">Net Pay</p>
                                    <p class="text-sm font-bold text-green-600">₱{{ formatCurrency(r.net_pay) }}</p>
                                </div>
                            </div>
                            <div v-if="r.status !== 'paid'" class="flex gap-2">
                                <button @click="markPaid(r)"
                                    class="flex-1 flex items-center justify-center gap-1.5 py-2.5 rounded-lg bg-green-50 hover:bg-green-100 dark:bg-green-900/20 dark:hover:bg-green-900/40 text-green-700 dark:text-green-400 text-sm font-semibold border border-green-200 dark:border-green-800">
                                    <CheckCircle class="h-4 w-4" /> Mark as Paid
                                </button>
                                <button @click="deletePayroll(r)"
                                    class="p-2.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 border" title="Delete">
                                    <Trash2 class="h-4 w-4 text-red-500" />
                                </button>
                            </div>
                            <p v-else class="text-xs text-muted-foreground">Paid {{ fmtDate(r.paid_at) }}</p>
                        </div>
                    </div>

                    <!-- Desktop: full-width table -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-5 py-3 text-left">Employee</th>
                                    <th class="px-4 py-3 text-left">Period</th>
                                    <th class="px-4 py-3 text-center">Days</th>
                                    <th class="px-4 py-3 text-right">Gross</th>
                                    <th class="px-4 py-3 text-right">Deductions</th>
                                    <th class="px-4 py-3 text-right">Net Pay</th>
                                    <th class="px-4 py-3 text-left">Status</th>
                                    <th class="px-4 py-3 text-left">Paid On</th>
                                    <th class="px-4 py-3 text-left max-w-[160px]">Notes</th>
                                    <th class="px-4 py-3 w-28"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="r in filteredPayroll" :key="r.id"
                                    class="hover:bg-muted/30 group transition-colors">
                                    <td class="px-5 py-3 font-semibold">{{ r.employee_name }}</td>
                                    <td class="px-4 py-3 text-muted-foreground text-xs whitespace-nowrap">
                                        {{ r.period_start }} → {{ r.period_end }}
                                    </td>
                                    <td class="px-4 py-3 text-center tabular-nums">{{ r.days_worked }}</td>
                                    <td class="px-4 py-3 text-right font-mono tabular-nums">₱{{ formatCurrency(r.gross_pay) }}</td>
                                    <td class="px-4 py-3 text-right font-mono tabular-nums text-red-500">
                                        {{ r.deductions > 0 ? '−₱' + formatCurrency(r.deductions) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right font-bold tabular-nums text-green-600">₱{{ formatCurrency(r.net_pay) }}</td>
                                    <td class="px-4 py-3">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold capitalize"
                                            :class="statusBadge[r.status]">
                                            {{ r.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground text-xs whitespace-nowrap">
                                        {{ r.paid_at ? fmtDate(r.paid_at) : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-muted-foreground text-xs max-w-[160px] truncate">{{ r.notes ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex gap-1 justify-end opacity-0 group-hover:opacity-100 transition-opacity">
                                            <button v-if="r.status !== 'paid'" @click="markPaid(r)"
                                                class="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-green-50 hover:bg-green-100 dark:bg-green-900/20 dark:hover:bg-green-900/40 text-green-700 dark:text-green-400 text-xs font-semibold border border-green-200 dark:border-green-800">
                                                <CheckCircle class="h-3 w-3" /> Pay
                                            </button>
                                            <button v-if="r.status !== 'paid'" @click="deletePayroll(r)"
                                                class="p-1.5 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete">
                                                <Trash2 class="h-3.5 w-3.5 text-red-500" />
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                            <!-- Totals footer -->
                            <tfoot class="border-t-2 bg-muted/30 text-sm">
                                <tr>
                                    <td colspan="3" class="px-5 py-2.5 text-xs text-muted-foreground font-medium">
                                        {{ filteredPayroll.length }} record{{ filteredPayroll.length !== 1 ? 's' : '' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold tabular-nums">
                                        ₱{{ formatCurrency(filteredPayroll.reduce((s, r) => s + r.gross_pay, 0)) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-mono font-semibold tabular-nums text-red-500">
                                        −₱{{ formatCurrency(filteredPayroll.reduce((s, r) => s + r.deductions, 0)) }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right font-bold tabular-nums text-green-600">
                                        ₱{{ formatCurrency(filteredPayroll.reduce((s, r) => s + r.net_pay, 0)) }}
                                    </td>
                                    <td colspan="4"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── Employee Modal ── full-screen on mobile, centered dialog on desktop ── -->
    <Teleport to="body">
        <div v-if="showEmpModal"
            class="fixed inset-0 z-50 flex flex-col sm:items-center sm:justify-center sm:bg-black/60 bg-background sm:p-4"
            @click.self="showEmpModal = false">
            <div class="flex flex-col flex-1 sm:flex-none w-full sm:max-w-lg sm:rounded-xl bg-background sm:shadow-2xl overflow-hidden">
                <div class="flex items-center justify-between border-b px-5 py-4 shrink-0">
                    <h2 class="font-semibold text-base">{{ editingEmp ? 'Edit Employee' : 'Add Employee' }}</h2>
                    <button @click="showEmpModal = false" class="rounded-lg p-2 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-muted-foreground mb-1.5">Full Name *</label>
                        <input v-model="empForm.name" type="text"
                            class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            placeholder="e.g. Juan dela Cruz" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1.5">Position</label>
                        <input v-model="empForm.position" type="text"
                            class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            placeholder="e.g. Cashier, Cook" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Employment Type</label>
                            <select v-model="empForm.employment_type"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="full_time">Full-time</option>
                                <option value="part_time">Part-time</option>
                                <option value="contractual">Contractual</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Salary Type</label>
                            <select v-model="empForm.salary_type"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                                <option value="hourly">Hourly</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Base Rate (₱)</label>
                            <input v-model.number="empForm.base_rate" type="number" min="0" step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Hired Date</label>
                            <input v-model="empForm.hired_at" type="date"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>
                    <label class="flex items-center gap-3 p-3 rounded-lg border bg-muted/30 cursor-pointer hover:bg-muted/50 transition-colors">
                        <input v-model="empForm.is_active" type="checkbox" class="h-4 w-4 rounded border" />
                        <span class="text-sm font-medium">Active employee</span>
                    </label>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1.5">Notes</label>
                        <textarea v-model="empForm.notes" rows="2" resize-none
                            class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none"
                            placeholder="Optional notes…" />
                    </div>
                </div>
                <div class="flex gap-3 border-t px-5 py-4 shrink-0">
                    <button @click="showEmpModal = false"
                        class="flex-1 sm:flex-none rounded-lg border px-5 py-2.5 text-sm font-medium hover:bg-muted">
                        Cancel
                    </button>
                    <button @click="saveEmployee" :disabled="loading || !empForm.name"
                        class="flex-1 sm:flex-none rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        {{ loading ? 'Saving…' : editingEmp ? 'Update' : 'Add Employee' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ── Payroll Modal ── full-screen on mobile, centered dialog on desktop ── -->
    <Teleport to="body">
        <div v-if="showPayrollModal"
            class="fixed inset-0 z-50 flex flex-col sm:items-center sm:justify-center sm:bg-black/60 bg-background sm:p-4"
            @click.self="showPayrollModal = false">
            <div class="flex flex-col flex-1 sm:flex-none w-full sm:max-w-lg sm:rounded-xl bg-background sm:shadow-2xl overflow-hidden">
                <div class="flex items-center justify-between border-b px-5 py-4 shrink-0">
                    <h2 class="font-semibold text-base">New Payroll Record</h2>
                    <button @click="showPayrollModal = false" class="rounded-lg p-2 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1.5">Employee *</label>
                        <select v-model.number="payrollForm.employee_id"
                            class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option v-for="emp in employees.filter(e => e.is_active)" :key="emp.id" :value="emp.id">
                                {{ emp.name }} — {{ emp.position ?? empTypeLabel[emp.employment_type] }}
                            </option>
                        </select>
                        <div v-if="selectedEmpForPayroll" class="mt-1.5 flex items-center gap-2 text-xs text-muted-foreground">
                            <span class="rounded bg-muted px-2 py-0.5 font-medium">{{ empTypeLabel[selectedEmpForPayroll.employment_type] }}</span>
                            <span>₱{{ formatCurrency(selectedEmpForPayroll.base_rate) }}{{ salaryTypeLabel[selectedEmpForPayroll.salary_type] }}</span>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Period Start *</label>
                            <input v-model="payrollForm.period_start" type="date"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Period End *</label>
                            <input v-model="payrollForm.period_end" type="date"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1.5">Days Worked</label>
                        <div class="flex gap-2">
                            <input v-model.number="payrollForm.days_worked" @input="autoCalcGross"
                                type="number" min="0" step="0.5"
                                class="flex-1 rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                            <button @click="autoCalcGross"
                                class="px-4 py-2.5 rounded-lg border text-xs font-medium hover:bg-muted whitespace-nowrap">
                                Auto-calc
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Gross Pay (₱) *</label>
                            <input v-model.number="payrollForm.gross_pay" type="number" min="0" step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1.5">Deductions (₱)</label>
                            <input v-model.number="payrollForm.deductions" type="number" min="0" step="0.01"
                                class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>
                    <div class="rounded-xl bg-muted/50 border px-5 py-3.5 flex items-center justify-between">
                        <span class="text-sm font-medium text-muted-foreground">Net Pay</span>
                        <span class="text-xl font-bold text-green-600 tabular-nums">₱{{ formatCurrency(netPayPreview) }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1.5">Notes</label>
                        <textarea v-model="payrollForm.notes" rows="2"
                            class="w-full rounded-lg border bg-background px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none"
                            placeholder="Optional notes…" />
                    </div>
                </div>
                <div class="flex gap-3 border-t px-5 py-4 shrink-0">
                    <button @click="showPayrollModal = false"
                        class="flex-1 sm:flex-none rounded-lg border px-5 py-2.5 text-sm font-medium hover:bg-muted">
                        Cancel
                    </button>
                    <button @click="savePayroll"
                        :disabled="loading || !payrollForm.employee_id || !payrollForm.period_start || !payrollForm.period_end"
                        class="flex-1 sm:flex-none rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        {{ loading ? 'Saving…' : 'Create Record' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
