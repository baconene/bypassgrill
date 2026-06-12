<script setup lang="ts">
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    Users, Plus, X, Pencil, CheckCircle, Trash2, ChevronDown, ChevronUp,
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
        employee_id: employees.value[0]?.id ?? 0,
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
    if (!confirm(`Mark payroll for ${emp?.name ?? record.employee_name} as PAID? This will create a ₱${formatCurrency(record.net_pay)} expense entry.`)) return
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
const filteredEmployees = computed(() => {
    if (empFilter.value === 'active') return employees.value.filter(e => e.is_active)
    if (empFilter.value === 'inactive') return employees.value.filter(e => !e.is_active)
    return employees.value
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

const empTypeLabel: Record<string, string> = {
    full_time: 'Full-time', part_time: 'Part-time', contractual: 'Contractual',
}
const salaryTypeLabel: Record<string, string> = {
    monthly: '/mo', daily: '/day', hourly: '/hr',
}
const statusColor: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-blue-100 text-blue-800',
    paid: 'bg-green-100 text-green-800',
}
</script>

<template>
    <Head title="HRIS" />

    <div class="space-y-6 max-w-6xl mx-auto">
        <!-- Header -->
        <div>
            <h1 class="text-xl sm:text-2xl font-bold">HRIS — Human Resources</h1>
            <p class="text-sm text-muted-foreground">Employee directory and payroll management</p>
        </div>

        <!-- Summary cards -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs text-muted-foreground mb-1">Active Employees</p>
                <p class="text-3xl font-bold">{{ employees.filter(e => e.is_active).length }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs text-muted-foreground mb-1">Pending Payroll</p>
                <p class="text-3xl font-bold text-yellow-600">{{ pendingPayrollCount }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm col-span-2">
                <p class="text-xs text-muted-foreground mb-1">Paid This Month</p>
                <p class="text-2xl font-bold text-green-600">₱{{ formatCurrency(totalPaidThisMonth) }}</p>
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-2 border-b">
            <button
                v-for="t in [{ key: 'employees', label: 'Employees' }, { key: 'payroll', label: 'Payroll' }]"
                :key="t.key"
                @click="tab = t.key as typeof tab"
                class="px-4 py-2 text-sm font-medium border-b-2 transition-colors"
                :class="tab === t.key
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:text-foreground'"
            >
                {{ t.label }}
            </button>
        </div>

        <!-- ── Employees Tab ─────────────────────────────────────────────────── -->
        <div v-if="tab === 'employees'" class="space-y-4">
            <div class="flex flex-wrap items-center gap-2 justify-between">
                <!-- Filter -->
                <div class="flex gap-1">
                    <button
                        v-for="f in ['all', 'active', 'inactive']"
                        :key="f"
                        @click="empFilter = f as typeof empFilter"
                        class="px-3 py-1 rounded-full text-xs font-medium border transition"
                        :class="empFilter === f
                            ? 'bg-primary text-primary-foreground border-primary'
                            : 'border-muted text-muted-foreground hover:border-foreground'"
                    >
                        {{ f.charAt(0).toUpperCase() + f.slice(1) }}
                    </button>
                </div>
                <button
                    @click="openAddEmp"
                    class="flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    <Plus class="h-4 w-4" /> Add Employee
                </button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div v-if="filteredEmployees.length === 0" class="p-10 text-center text-muted-foreground">
                    No employees found.
                </div>
                <template v-else>
                    <!-- Mobile: card list -->
                    <div class="sm:hidden divide-y">
                        <div v-for="emp in filteredEmployees" :key="emp.id" class="flex items-start justify-between gap-3 px-4 py-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-sm">{{ emp.name }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="emp.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                                        {{ emp.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground mt-0.5">{{ emp.position ?? '—' }} · {{ empTypeLabel[emp.employment_type] }}</p>
                                <p class="text-xs font-mono mt-0.5">₱{{ formatCurrency(emp.base_rate) }}{{ salaryTypeLabel[emp.salary_type] }}</p>
                                <p v-if="emp.hired_at" class="text-xs text-muted-foreground mt-0.5">Hired {{ emp.hired_at }}</p>
                            </div>
                            <div class="flex gap-1 shrink-0">
                                <button @click="openEditEmp(emp)" class="p-1.5 rounded hover:bg-muted" title="Edit">
                                    <Pencil class="h-4 w-4 text-muted-foreground" />
                                </button>
                                <button @click="deleteEmployee(emp)" class="p-1.5 rounded hover:bg-red-50" title="Delete">
                                    <Trash2 class="h-4 w-4 text-red-500" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- Desktop: table -->
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Name</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Position</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Type</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Base Rate</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Status</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Hired</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="emp in filteredEmployees" :key="emp.id" class="hover:bg-muted/30">
                                    <td class="px-4 py-2 font-semibold">{{ emp.name }}</td>
                                    <td class="px-4 py-2 text-muted-foreground">{{ emp.position ?? '—' }}</td>
                                    <td class="px-4 py-2">
                                        <span class="rounded-full bg-muted px-2 py-0.5 text-xs">{{ empTypeLabel[emp.employment_type] }}</span>
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono">
                                        ₱{{ formatCurrency(emp.base_rate) }}{{ salaryTypeLabel[emp.salary_type] }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium"
                                            :class="emp.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                                            {{ emp.is_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-muted-foreground text-xs">{{ emp.hired_at ?? '—' }}</td>
                                    <td class="px-4 py-2">
                                        <div class="flex gap-1 justify-end">
                                            <button @click="openEditEmp(emp)" class="p-1 rounded hover:bg-muted" title="Edit">
                                                <Pencil class="h-3.5 w-3.5 text-muted-foreground" />
                                            </button>
                                            <button @click="deleteEmployee(emp)" class="p-1 rounded hover:bg-red-50" title="Delete">
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
            <div class="flex flex-wrap items-center gap-2 justify-between">
                <select
                    v-model="payrollEmpFilter"
                    class="rounded-lg border bg-card px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                >
                    <option :value="0">All Employees</option>
                    <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.name }}</option>
                </select>
                <button
                    @click="openAddPayroll"
                    class="flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                >
                    <Plus class="h-4 w-4" /> New Payroll
                </button>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div v-if="filteredPayroll.length === 0" class="p-10 text-center text-muted-foreground">
                    No payroll records found.
                </div>
                <template v-else>
                    <!-- Mobile: card list -->
                    <div class="sm:hidden divide-y">
                        <div v-for="r in filteredPayroll" :key="r.id" class="px-4 py-3 space-y-2">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="font-semibold text-sm">{{ r.employee_name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ r.period_start }} → {{ r.period_end }} · {{ r.days_worked }} days</p>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize shrink-0" :class="statusColor[r.status]">
                                    {{ r.status }}
                                </span>
                            </div>
                            <div class="flex items-center gap-4 text-sm">
                                <div>
                                    <p class="text-xs text-muted-foreground">Gross</p>
                                    <p class="font-mono">₱{{ formatCurrency(r.gross_pay) }}</p>
                                </div>
                                <div v-if="r.deductions > 0">
                                    <p class="text-xs text-muted-foreground">Deductions</p>
                                    <p class="font-mono text-red-600">−₱{{ formatCurrency(r.deductions) }}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-muted-foreground">Net Pay</p>
                                    <p class="font-bold">₱{{ formatCurrency(r.net_pay) }}</p>
                                </div>
                            </div>
                            <div v-if="r.status !== 'paid'" class="flex gap-2">
                                <button @click="markPaid(r)"
                                    class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-green-50 hover:bg-green-100 text-green-700 text-xs font-semibold border border-green-200">
                                    <CheckCircle class="h-3.5 w-3.5" /> Mark as Paid
                                </button>
                                <button @click="deletePayroll(r)" class="p-1.5 rounded-lg hover:bg-red-50 border" title="Delete">
                                    <Trash2 class="h-4 w-4 text-red-500" />
                                </button>
                            </div>
                            <p v-else class="text-xs text-muted-foreground">
                                Paid {{ r.paid_at ? new Date(r.paid_at).toLocaleDateString() : '' }}
                            </p>
                        </div>
                    </div>
                    <!-- Desktop: table -->
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50">
                                <tr>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Employee</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Period</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Days</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Gross</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Deductions</th>
                                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">Net Pay</th>
                                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">Status</th>
                                    <th class="px-4 py-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="r in filteredPayroll" :key="r.id" class="hover:bg-muted/30">
                                    <td class="px-4 py-2 font-semibold">{{ r.employee_name }}</td>
                                    <td class="px-4 py-2 text-muted-foreground text-xs">{{ r.period_start }} → {{ r.period_end }}</td>
                                    <td class="px-4 py-2 text-right">{{ r.days_worked }}</td>
                                    <td class="px-4 py-2 text-right font-mono">₱{{ formatCurrency(r.gross_pay) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-red-600">
                                        {{ r.deductions > 0 ? '-₱' + formatCurrency(r.deductions) : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-right font-bold">₱{{ formatCurrency(r.net_pay) }}</td>
                                    <td class="px-4 py-2">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-medium capitalize" :class="statusColor[r.status]">
                                            {{ r.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <div class="flex gap-1 justify-end">
                                            <button v-if="r.status !== 'paid'" @click="markPaid(r)"
                                                class="flex items-center gap-1 px-2 py-0.5 rounded bg-green-50 hover:bg-green-100 text-green-700 text-xs font-medium"
                                                title="Mark as Paid">
                                                <CheckCircle class="h-3 w-3" /> Pay
                                            </button>
                                            <button v-if="r.status !== 'paid'" @click="deletePayroll(r)"
                                                class="p-1 rounded hover:bg-red-50" title="Delete">
                                                <Trash2 class="h-3.5 w-3.5 text-red-500" />
                                            </button>
                                            <span v-if="r.status === 'paid'" class="text-xs text-muted-foreground">
                                                {{ r.paid_at ? new Date(r.paid_at).toLocaleDateString() : '' }}
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ── Employee Modal ────────────────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="showEmpModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-background shadow-xl">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="font-semibold text-base">{{ editingEmp ? 'Edit Employee' : 'Add Employee' }}</h2>
                    <button @click="showEmpModal = false" class="rounded p-1 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="p-4 space-y-3 max-h-[70vh] overflow-y-auto">
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">Name *</label>
                        <input v-model="empForm.name" type="text" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Full name" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">Position</label>
                        <input v-model="empForm.position" type="text" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" placeholder="e.g. Cashier, Cook" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Employment Type</label>
                            <select v-model="empForm.employment_type" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="full_time">Full-time</option>
                                <option value="part_time">Part-time</option>
                                <option value="contractual">Contractual</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Salary Type</label>
                            <select v-model="empForm.salary_type" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                <option value="monthly">Monthly</option>
                                <option value="daily">Daily</option>
                                <option value="hourly">Hourly</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Base Rate (₱)</label>
                            <input v-model.number="empForm.base_rate" type="number" min="0" step="0.01" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Hired Date</label>
                            <input v-model="empForm.hired_at" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <input v-model="empForm.is_active" type="checkbox" id="emp-active" class="h-4 w-4 rounded border" />
                        <label for="emp-active" class="text-sm">Active employee</label>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">Notes</label>
                        <textarea v-model="empForm.notes" rows="2" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Optional notes..." />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t p-4">
                    <button @click="showEmpModal = false" class="rounded-lg border px-4 py-2 text-sm hover:bg-muted">Cancel</button>
                    <button @click="saveEmployee" :disabled="loading || !empForm.name" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        {{ loading ? 'Saving…' : 'Save' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>

    <!-- ── Payroll Modal ─────────────────────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="showPayrollModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-xl bg-background shadow-xl">
                <div class="flex items-center justify-between border-b p-4">
                    <h2 class="font-semibold text-base">New Payroll Record</h2>
                    <button @click="showPayrollModal = false" class="rounded p-1 hover:bg-muted">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="p-4 space-y-3 max-h-[75vh] overflow-y-auto">
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">Employee *</label>
                        <select v-model.number="payrollForm.employee_id" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option v-for="emp in employees.filter(e => e.is_active)" :key="emp.id" :value="emp.id">
                                {{ emp.name }} — {{ emp.position ?? empTypeLabel[emp.employment_type] }}
                            </option>
                        </select>
                        <p v-if="selectedEmpForPayroll" class="text-xs text-muted-foreground mt-1">
                            Base rate: ₱{{ formatCurrency(selectedEmpForPayroll.base_rate) }}{{ salaryTypeLabel[selectedEmpForPayroll.salary_type] }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Period Start *</label>
                            <input v-model="payrollForm.period_start" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Period End *</label>
                            <input v-model="payrollForm.period_end" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">Days Worked *</label>
                        <div class="flex gap-2">
                            <input
                                v-model.number="payrollForm.days_worked"
                                @input="autoCalcGross"
                                type="number" min="0" step="0.5"
                                class="flex-1 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                            <button
                                @click="autoCalcGross"
                                class="px-3 py-2 rounded-lg border text-xs hover:bg-muted"
                                title="Auto-calculate gross pay from base rate"
                            >
                                Calc
                            </button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Gross Pay (₱) *</label>
                            <input v-model.number="payrollForm.gross_pay" type="number" min="0" step="0.01" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-muted-foreground mb-1">Deductions (₱)</label>
                            <input v-model.number="payrollForm.deductions" type="number" min="0" step="0.01" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        </div>
                    </div>
                    <div class="rounded-lg bg-muted/50 px-4 py-2 flex justify-between text-sm">
                        <span class="text-muted-foreground">Net Pay</span>
                        <span class="font-bold">₱{{ formatCurrency(netPayPreview) }}</span>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">Notes</label>
                        <textarea v-model="payrollForm.notes" rows="2" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" placeholder="Optional notes..." />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t p-4">
                    <button @click="showPayrollModal = false" class="rounded-lg border px-4 py-2 text-sm hover:bg-muted">Cancel</button>
                    <button
                        @click="savePayroll"
                        :disabled="loading || !payrollForm.employee_id || !payrollForm.period_start || !payrollForm.period_end"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                    >
                        {{ loading ? 'Saving…' : 'Create Record' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
