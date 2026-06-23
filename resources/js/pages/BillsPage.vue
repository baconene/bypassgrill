<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    CalendarDays, Plus, X, Pencil, Trash2, TrendingUp, TrendingDown,
} from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Bills', href: '/bills' },
        ],
    },
})

// ── Types ─────────────────────────────────────────────────────────────────────
interface BillInstallment {
    id: number; installment_number: number; amount: number
    due_date: string; paid_at: string | null
    status: 'overdue' | 'due_today' | 'upcoming' | 'scheduled' | 'paid'
}
interface Bill {
    id: number; name: string; description: string | null; amount: number
    frequency: string; due_date: string; category: string | null
    is_active: boolean; is_installment: boolean
    installment_count: number | null; installments_paid: number | null
    last_paid_at: string | null
    status: 'overdue' | 'due_today' | 'upcoming' | 'scheduled' | 'inactive'
    installments: BillInstallment[]
}
interface BillForecastEntry {
    bill_id: number; installment_id: number | null; name: string; label: string | null
    category: string | null; amount: number; due_date: string
    frequency: string; is_installment: boolean; status: string
}
interface BillForecast {
    entries: BillForecastEntry[]
    by_month: Record<string, BillForecastEntry[]>
    total_forecast: number; months: number
}

// ── State ──────────────────────────────────────────────────────────────────────
const bills = ref<Bill[]>([])
const billForecast = ref<BillForecast | null>(null)
const forecastMonths = ref(3)
const loading = ref(false)
const showBillForm = ref(false)
const editingBill = ref<Bill | null>(null)
const billForm = ref({
    name: '', description: '', amount: '', frequency: 'monthly',
    due_date: '', category: '', is_installment: false, installment_count: '3',
})
const billSaving = ref(false)
const billPaying = ref<number | null>(null)
const billDeleting = ref<number | null>(null)
const expandedBillId = ref<number | null>(null)
const payingInstallmentId = ref<number | null>(null)

// ── Helpers ───────────────────────────────────────────────────────────────────
const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December']
const monthName = (n: number) => monthNames[n - 1] ?? ''

const fmt = (v: number | string | null | undefined) =>
    '₱' + parseFloat(String(v ?? 0)).toLocaleString('en-PH', { minimumFractionDigits: 2 })

const fmtDatetime = (s: string) => {
    if (!s) return '—'
    const d = new Date(s)
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) + ' ' +
        d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
}

const frequencyLabel = (f: string) => ({
    one_time: 'One Time', daily: 'Daily', weekly: 'Weekly', bi_weekly: 'Bi-Weekly',
    monthly: 'Monthly', quarterly: 'Quarterly', semi_annual: 'Semi-Annual', annual: 'Annual',
}[f] ?? f)

const billStatusBadge = (s: string) => ({
    overdue:   'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    due_today: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    upcoming:  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    scheduled: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    inactive:  'bg-muted text-muted-foreground',
}[s] ?? 'bg-muted text-muted-foreground')

const installmentStatusBadge = (s: string) => ({
    overdue:    'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    due_today:  'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    upcoming:   'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    scheduled:  'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    paid:       'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
}[s] ?? 'bg-muted text-muted-foreground')

// ── Chart helpers ──────────────────────────────────────────────────────────────
const fmtShort = (v: number) => {
    if (v >= 1_000_000) return `₱${(v / 1_000_000).toFixed(1)}M`
    if (v >= 1_000) return `₱${(v / 1_000).toFixed(1)}K`
    return `₱${v.toFixed(0)}`
}

const sortedInstallments = (bill: Bill) =>
    [...bill.installments].sort((a, b) => a.installment_number - b.installment_number)

const runningRemaining = (bill: Bill, upToNumber: number) => {
    let rem = bill.amount
    for (const inst of sortedInstallments(bill)) {
        rem -= inst.amount
        if (inst.installment_number === upToNumber) break
    }
    return Math.max(0, rem)
}

// ── Computed ───────────────────────────────────────────────────────────────────
const monthlySummary = computed(() => {
    const multiplier: Record<string, number> = {
        one_time: 0, daily: 30, weekly: 4.33, bi_weekly: 2.17,
        monthly: 1, quarterly: 1/3, semi_annual: 1/6, annual: 1/12,
    }
    return bills.value.filter(b => b.is_active && !b.is_installment).reduce((sum, b) => sum + b.amount * (multiplier[b.frequency] ?? 0), 0)
})

const annualSummary = computed(() => {
    const recurringAnnual = monthlySummary.value * 12
    const paymentPlansTotal = bills.value
        .filter(b => b.is_active && b.is_installment)
        .reduce((sum, b) => sum + (b.installments?.reduce((isum, i) => isum + (i.paid_at ? 0 : i.amount), 0) ?? 0), 0)
    return recurringAnnual + paymentPlansTotal
})

const overdueBills = computed(() => bills.value.filter(b => b.status === 'overdue'))
const dueSoonBills = computed(() => bills.value.filter(b => b.status === 'due_today' || b.status === 'upcoming'))

const expandedBill = computed(() => bills.value.find(b => b.id === expandedBillId.value) ?? null)

const burndownData = computed(() => {
    const bill = expandedBill.value
    if (!bill?.is_installment || !bill.installments.length) return null

    const sorted = sortedInstallments(bill)
    const total = bill.amount
    const n = sorted.length
    const plotW = 434  // 500 - 54(padL) - 12(padR)
    const plotH = 118  // 160 - 14(padT) - 28(padB)

    const toX = (v: number) => 54 + (v / n) * plotW
    const toY = (v: number) => 14 + (1 - v / total) * plotH

    type Pt = { n: number; rem: number; paid: boolean; svgX: number; svgY: number }
    const pts: Pt[] = []
    let rem = total
    pts.push({ n: 0, rem: total, paid: true, svgX: toX(0), svgY: toY(total) })
    for (const inst of sorted) {
        rem = Math.max(0, rem - inst.amount)
        pts.push({ n: inst.installment_number, rem, paid: !!inst.paid_at, svgX: toX(inst.installment_number), svgY: toY(rem) })
    }

    let splitIdx = 0
    for (let i = 1; i < pts.length; i++) {
        if (pts[i].paid) splitIdx = i
    }

    const toStr = (arr: Pt[]) => arr.map(p => `${p.svgX},${p.svgY}`).join(' ')
    const paidPts = pts.slice(0, splitIdx + 1)
    const unpaidPts = pts.slice(splitIdx)

    const step = n <= 6 ? 1 : n <= 12 ? 2 : n <= 24 ? 4 : Math.ceil(n / 6)
    const xTicks = pts
        .filter(p => p.n === 0 || p.n % step === 0 || p.n === n)
        .map(p => ({ n: p.n, x: p.svgX, label: p.n === 0 ? 'Start' : `#${p.n}` }))
    const yTicks = [0, 0.25, 0.5, 0.75, 1].map(f => ({
        v: total * f, y: toY(total * f), label: fmtShort(total * f),
    }))

    return {
        pts, paidPts, unpaidPts,
        paidPolyStr: toStr(paidPts),
        unpaidPolyStr: toStr(unpaidPts),
        areaPoints: paidPts.length >= 2
            ? `54,132 ${toStr(paidPts)} ${paidPts[paidPts.length - 1].svgX},132`
            : '',
        xTicks, yTicks,
    }
})

// ── Actions ────────────────────────────────────────────────────────────────────
const loadBills = async () => {
    const res = await api.get('/api/v1/bills')
    bills.value = res.data.data ?? []
}

const loadForecast = async () => {
    const res = await api.get('/api/v1/bills/forecast', { params: { months: forecastMonths.value } })
    billForecast.value = res.data
}

const openBillForm = (bill?: Bill) => {
    if (bill) {
        editingBill.value = bill
        billForm.value = {
            name: bill.name, description: bill.description ?? '',
            amount: String(bill.amount), frequency: bill.frequency,
            due_date: bill.due_date, category: bill.category ?? '',
            is_installment: bill.is_installment,
            installment_count: String(bill.installment_count ?? 3),
        }
    } else {
        editingBill.value = null
        billForm.value = {
            name: '', description: '', amount: '', frequency: 'monthly',
            due_date: '', category: '', is_installment: false, installment_count: '3',
        }
    }
    showBillForm.value = true
}

const closeBillForm = () => { showBillForm.value = false; editingBill.value = null }

const saveBill = async () => {
    if (!billForm.value.name.trim() || !billForm.value.amount || !billForm.value.due_date) return
    billSaving.value = true
    try {
        const payload: Record<string, any> = {
            name: billForm.value.name,
            description: billForm.value.description || null,
            amount: parseFloat(billForm.value.amount),
            frequency: billForm.value.frequency,
            due_date: billForm.value.due_date,
            category: billForm.value.category || null,
        }
        if (!editingBill.value) {
            payload.is_installment = billForm.value.is_installment
            if (billForm.value.is_installment) {
                payload.installment_count = parseInt(billForm.value.installment_count)
            }
        }
        if (editingBill.value) {
            await api.put(`/api/v1/bills/${editingBill.value.id}`, payload)
            toast.success('Bill updated.')
        } else {
            await api.post('/api/v1/bills', payload)
            toast.success('Bill added.')
        }
        closeBillForm()
        await loadBills(); await loadForecast()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save bill.')
    } finally {
        billSaving.value = false
    }
}

const payBill = async (bill: Bill) => {
    if (!confirm(`Mark "${bill.name}" as paid?\n\nAmount: ${fmt(bill.amount)}\nThis records an expense and advances the due date.`)) return
    billPaying.value = bill.id
    try {
        await api.post(`/api/v1/bills/${bill.id}/pay`)
        toast.success('Bill marked as paid.')
        await loadBills(); await loadForecast()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to mark as paid.')
    } finally {
        billPaying.value = null
    }
}

const payInstallment = async (bill: Bill, inst: BillInstallment) => {
    if (!confirm(`Pay installment #${inst.installment_number} of "${bill.name}"?\n\nAmount: ${fmt(inst.amount)}\nDue: ${inst.due_date}`)) return
    payingInstallmentId.value = inst.id
    try {
        const res = await api.post(`/api/v1/bills/${bill.id}/installments/${inst.id}/pay`)
        const idx = bills.value.findIndex(b => b.id === bill.id)
        if (idx !== -1) bills.value[idx] = res.data.data
        await loadForecast()
        toast.success(`Installment #${inst.installment_number} paid.`)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to pay installment.')
    } finally {
        payingInstallmentId.value = null
    }
}

const deleteBill = async (bill: Bill) => {
    if (!confirm(`Delete "${bill.name}"? This cannot be undone.`)) return
    billDeleting.value = bill.id
    try {
        await api.delete(`/api/v1/bills/${bill.id}`)
        toast.success('Bill deleted.')
        await loadBills(); await loadForecast()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete bill.')
    } finally {
        billDeleting.value = null
    }
}

onMounted(async () => {
    loading.value = true
    try {
        await loadBills()
        await loadForecast()
    } finally {
        loading.value = false
    }
})
</script>

<template>
    <Head title="Bills & Payables" />

    <div class="space-y-5 p-4 md:p-6">
        <!-- Summary cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><CalendarDays class="h-3 w-3" /> Monthly Exposure</p>
                <p class="text-xl font-black text-orange-600">{{ fmt(monthlySummary) }}</p>
                <p class="text-xs text-muted-foreground mt-0.5">recurring bills</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="text-xs text-muted-foreground mb-1">Annual Exposure</p>
                <p class="text-xl font-black">{{ fmt(annualSummary) }}</p>
                <p class="text-xs text-muted-foreground mt-0.5">recurring + fixed</p>
            </div>
            <div :class="['rounded-xl border p-4 shadow-sm', overdueBills.length > 0 ? 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800' : 'bg-card']">
                <p class="text-xs text-muted-foreground mb-1">Overdue</p>
                <p class="text-3xl font-black" :class="overdueBills.length > 0 ? 'text-red-600' : ''">{{ overdueBills.length }}</p>
            </div>
            <div :class="['rounded-xl border p-4 shadow-sm', dueSoonBills.length > 0 ? 'bg-yellow-50 dark:bg-yellow-950/20 border-yellow-200 dark:border-yellow-800' : 'bg-card']">
                <p class="text-xs text-muted-foreground mb-1">Due Soon</p>
                <p class="text-3xl font-black" :class="dueSoonBills.length > 0 ? 'text-yellow-600' : ''">{{ dueSoonBills.length }}</p>
            </div>
        </div>

        <!-- Bills list -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b flex items-center justify-between">
                <h2 class="font-bold text-sm flex items-center gap-2"><CalendarDays class="h-4 w-4" /> Payables</h2>
                <button @click="openBillForm()" class="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-1.5 text-sm font-bold text-primary-foreground hover:bg-primary/90">
                    <Plus class="h-3.5 w-3.5" /> Add Bill
                </button>
            </div>

            <!-- Add / Edit form -->
            <div v-if="showBillForm" class="border-b bg-muted/20 p-4">
                <p class="text-sm font-bold mb-3">{{ editingBill ? 'Edit Bill' : 'New Payable' }}</p>

                <!-- Payment plan toggle (new bills only) -->
                <div v-if="!editingBill" class="flex gap-2 mb-4">
                    <button @click="billForm.is_installment = false"
                        :class="['flex-1 rounded-lg border-2 py-2 text-sm font-semibold transition',
                            !billForm.is_installment ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted']">
                        Recurring Bill
                    </button>
                    <button @click="billForm.is_installment = true"
                        :class="['flex-1 rounded-lg border-2 py-2 text-sm font-semibold transition',
                            billForm.is_installment ? 'border-orange-500 bg-orange-50 text-orange-700 dark:bg-orange-950/20 dark:text-orange-400' : 'border-border text-muted-foreground hover:bg-muted']">
                        Payment Plan (Installments)
                    </button>
                </div>

                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Name *</label>
                        <input v-model="billForm.name" type="text" placeholder="e.g. Shopee Subscription"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">
                            {{ billForm.is_installment ? 'Total Amount (₱) *' : 'Amount (₱) *' }}
                        </label>
                        <input v-model="billForm.amount" type="number" min="0.01" step="0.01" placeholder="0.00"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">
                            {{ billForm.is_installment ? 'Interval Between Payments *' : 'Frequency *' }}
                        </label>
                        <select v-model="billForm.frequency" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option v-if="!billForm.is_installment" value="one_time">One Time</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="bi_weekly">Bi-Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annual">Semi-Annual</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>
                    <!-- Installment count: only for new payment-plan bills -->
                    <div v-if="billForm.is_installment && !editingBill">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Number of Installments *</label>
                        <input v-model="billForm.installment_count" type="number" min="2" max="360" placeholder="e.g. 3"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        <p v-if="billForm.amount && billForm.installment_count" class="text-xs text-muted-foreground mt-1">
                            ≈ {{ fmt(parseFloat(billForm.amount || '0') / parseInt(billForm.installment_count || '1')) }} / installment
                        </p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">
                            {{ billForm.is_installment ? 'First Payment Date *' : 'Next Due Date *' }}
                        </label>
                        <input v-model="billForm.due_date" type="date"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Category</label>
                        <input v-model="billForm.category" type="text" placeholder="e.g. Subscription, Utilities"
                            list="bill-categories"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                        <datalist id="bill-categories">
                            <option value="Subscription" /><option value="Utilities" /><option value="Rent" />
                            <option value="Platform Fee" /><option value="Loan" /><option value="Insurance" />
                            <option value="Maintenance" /><option value="Tax" />
                        </datalist>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Notes</label>
                        <input v-model="billForm.description" type="text" placeholder="Optional details"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                </div>
                <div class="flex gap-2 mt-3">
                    <button @click="saveBill" :disabled="billSaving || !billForm.name.trim() || !billForm.amount || !billForm.due_date"
                        class="rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                        {{ billSaving ? 'Saving…' : (editingBill ? 'Update' : (billForm.is_installment ? 'Create Payment Plan' : 'Add Bill')) }}
                    </button>
                    <button @click="closeBillForm" class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted">Cancel</button>
                </div>
            </div>

            <!-- Mobile bill cards (< sm) -->
            <div class="sm:hidden divide-y">
                <template v-for="bill in bills" :key="`m-${bill.id}`">
                    <div :class="['p-4', bill.is_active ? '' : 'opacity-50']">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-sm">{{ bill.name }}</p>
                                <p v-if="bill.description" class="text-xs text-muted-foreground">{{ bill.description }}</p>
                                <p v-if="bill.is_installment" class="text-xs text-orange-600 mt-0.5">
                                    Plan: {{ bill.installments_paid }}/{{ bill.installment_count }} paid
                                </p>
                            </div>
                            <span :class="['flex-shrink-0 px-2 py-1 rounded-full text-xs font-medium', billStatusBadge(bill.status)]">
                                {{ bill.status.replace(/_/g, ' ') }}
                            </span>
                        </div>
                        <div class="mt-2 flex items-center justify-between">
                            <span class="font-bold text-base">{{ fmt(bill.amount) }}</span>
                            <span class="text-xs text-muted-foreground">{{ frequencyLabel(bill.frequency) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                            <span v-if="bill.category">{{ bill.category }}</span>
                            <span>Due: {{ bill.due_date }}</span>
                            <span v-if="bill.last_paid_at">Last paid: {{ fmtDatetime(bill.last_paid_at) }}</span>
                        </div>
                        <div class="mt-2.5">
                            <button @click="expandedBillId === bill.id ? (expandedBillId = null) : (expandedBillId = bill.id)"
                                class="text-primary hover:underline text-xs font-medium">
                                {{ expandedBillId === bill.id ? 'Hide details ▲' : 'Show details ▼' }}
                            </button>
                        </div>
                        <!-- Expanded detail -->
                        <div v-if="expandedBillId === bill.id" class="mt-3 space-y-3">
                            <!-- Burndown chart (payment plans only) -->
                            <div v-if="bill.is_installment && bill.installments.length > 0 && burndownData" class="space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold text-muted-foreground">Payment Burndown</p>
                                    <div class="flex items-center gap-4 text-xs text-muted-foreground">
                                        <span class="flex items-center gap-1.5">
                                            <svg width="16" height="4"><line x1="0" y1="2" x2="16" y2="2" stroke="#22c55e" stroke-width="2.5"/></svg>
                                            Paid
                                        </span>
                                        <span class="flex items-center gap-1.5">
                                            <svg width="16" height="4"><line x1="0" y1="2" x2="16" y2="2" stroke="#94a3b8" stroke-width="2" stroke-dasharray="4,2"/></svg>
                                            Remaining
                                        </span>
                                    </div>
                                </div>
                                <div class="rounded-lg border bg-background p-2">
                                    <svg viewBox="0 0 500 160" class="w-full" style="height:160px">
                                        <defs>
                                            <linearGradient id="bdPaidGradM" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="#22c55e" stop-opacity="0.25" />
                                                <stop offset="100%" stop-color="#22c55e" stop-opacity="0.02" />
                                            </linearGradient>
                                        </defs>
                                        <g v-for="tick in burndownData!.yTicks" :key="tick.v">
                                            <line :x1="54" :y1="tick.y" :x2="488" :y2="tick.y" stroke="currentColor" stroke-opacity="0.07" stroke-width="1" />
                                            <text :x="50" :y="tick.y + 3.5" text-anchor="end" fill="currentColor" fill-opacity="0.5" font-size="9">{{ tick.label }}</text>
                                        </g>
                                        <text v-for="tick in burndownData!.xTicks" :key="tick.n" :x="tick.x" y="155" text-anchor="middle" fill="currentColor" fill-opacity="0.5" font-size="9">{{ tick.label }}</text>
                                        <line x1="54" y1="14" x2="54" y2="132" stroke="currentColor" stroke-opacity="0.15" stroke-width="1" />
                                        <line x1="54" y1="132" x2="488" y2="132" stroke="currentColor" stroke-opacity="0.15" stroke-width="1" />
                                        <polygon v-if="burndownData!.areaPoints" :points="burndownData!.areaPoints" fill="url(#bdPaidGradM)" />
                                        <polyline v-if="burndownData!.unpaidPts.length >= 2" :points="burndownData!.unpaidPolyStr" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5,3" stroke-linecap="round" />
                                        <polyline v-if="burndownData!.paidPts.length >= 2" :points="burndownData!.paidPolyStr" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle v-for="pt in burndownData!.pts" :key="pt.n" :cx="pt.svgX" :cy="pt.svgY" r="3" :fill="pt.paid ? '#22c55e' : 'none'" :stroke="pt.paid ? '#16a34a' : '#94a3b8'" stroke-width="1.5" />
                                    </svg>
                                </div>
                            </div>
                            <!-- Installment schedule -->
                            <div v-if="bill.is_installment && bill.installments.length > 0" class="space-y-1.5">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Installment Schedule</p>
                                <div class="rounded-lg border overflow-hidden overflow-x-auto">
                                    <table class="w-full text-xs min-w-[520px]">
                                        <thead class="bg-muted/50 text-muted-foreground">
                                            <tr>
                                                <th class="px-3 py-2 text-left font-medium">#</th>
                                                <th class="px-3 py-2 text-right font-medium">Amount</th>
                                                <th class="px-3 py-2 text-left font-medium">Due Date</th>
                                                <th class="px-3 py-2 text-left font-medium">Status</th>
                                                <th class="px-3 py-2 text-left font-medium">Paid On</th>
                                                <th class="px-3 py-2 text-right font-medium">Balance After</th>
                                                <th class="px-3 py-2 text-center font-medium">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="inst in sortedInstallments(bill)" :key="inst.id"
                                                :class="['border-t transition-colors', inst.paid_at ? 'bg-green-50/50 dark:bg-green-950/10' : '']">
                                                <td class="px-3 py-2.5 font-bold text-muted-foreground">#{{ inst.installment_number }}</td>
                                                <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ fmt(inst.amount) }}</td>
                                                <td class="px-3 py-2.5 tabular-nums">{{ inst.due_date }}</td>
                                                <td class="px-3 py-2.5">
                                                    <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', installmentStatusBadge(inst.status)]">
                                                        {{ inst.status.replace(/_/g, ' ') }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2.5 text-muted-foreground tabular-nums">{{ inst.paid_at ? fmtDatetime(inst.paid_at) : '—' }}</td>
                                                <td class="px-3 py-2.5 text-right tabular-nums font-medium"
                                                    :class="runningRemaining(bill, inst.installment_number) === 0 ? 'text-green-600' : 'text-orange-600'">
                                                    {{ fmt(runningRemaining(bill, inst.installment_number)) }}
                                                </td>
                                                <td class="px-3 py-2.5 text-center">
                                                    <span v-if="inst.paid_at" class="text-green-600 font-bold">✓</span>
                                                    <button v-else-if="bill.is_active"
                                                        @click="payInstallment(bill, inst)"
                                                        :disabled="payingInstallmentId === inst.id"
                                                        class="rounded bg-green-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-50">
                                                        {{ payingInstallmentId === inst.id ? '…' : 'Pay' }}
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                        <tfoot class="border-t bg-muted/30">
                                            <tr>
                                                <td colspan="4" class="px-3 py-2 text-muted-foreground">{{ bill.installments_paid }}/{{ bill.installment_count }} paid</td>
                                                <td class="px-3 py-2 text-right font-bold text-green-600">Paid: {{ fmt(bill.installments.filter(i => i.paid_at).reduce((s, i) => s + i.amount, 0)) }}</td>
                                                <td class="px-3 py-2 text-right font-bold text-orange-600">{{ fmt(bill.installments.filter(i => !i.paid_at).reduce((s, i) => s + i.amount, 0)) }} left</td>
                                                <td></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <!-- Action buttons -->
                            <div class="flex flex-wrap gap-2 pt-1">
                                <button v-if="!bill.is_installment && bill.is_active" @click="payBill(bill)" :disabled="billPaying === bill.id"
                                    class="flex-1 rounded bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-50">
                                    {{ billPaying === bill.id ? 'Processing…' : '💰 Mark as Paid' }}
                                </button>
                                <button @click="openBillForm(bill)" class="flex-1 rounded border px-3 py-1.5 text-xs font-medium hover:bg-muted flex items-center justify-center gap-1">
                                    <Pencil class="h-3 w-3" /> Edit
                                </button>
                                <button @click="deleteBill(bill)" :disabled="billDeleting === bill.id"
                                    class="flex-1 rounded border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 disabled:opacity-50">
                                    {{ billDeleting === bill.id ? 'Deleting…' : '🗑️ Delete' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Desktop bill table (≥ sm) -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left">Bill / Payable</th>
                            <th class="px-4 py-3 text-left">Category</th>
                            <th class="px-4 py-3 text-right">Amount</th>
                            <th class="px-4 py-3 text-left">Frequency</th>
                            <th class="px-4 py-3 text-left">Next Due</th>
                            <th class="px-4 py-3 text-left">Status</th>
                            <th class="px-4 py-3 text-left">Last Paid</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="bill in bills" :key="bill.id">
                            <tr :class="['border-t', bill.is_active ? '' : 'opacity-50']">
                                <td class="px-4 py-3">
                                    <div>
                                        <p class="font-medium">{{ bill.name }}</p>
                                        <p v-if="bill.description" class="text-xs text-muted-foreground">{{ bill.description }}</p>
                                        <p v-if="bill.is_installment" class="text-xs text-orange-600 mt-1">
                                            Payment Plan: {{ bill.installments_paid }}/{{ bill.installment_count }} paid
                                        </p>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-muted-foreground">{{ bill.category ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ fmt(bill.amount) }}</td>
                                <td class="px-4 py-3 text-sm">{{ frequencyLabel(bill.frequency) }}</td>
                                <td class="px-4 py-3 text-sm">{{ bill.due_date }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['px-2 py-1 rounded-full text-xs font-medium', billStatusBadge(bill.status)]">
                                        {{ bill.status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-muted-foreground">{{ bill.last_paid_at ? fmtDatetime(bill.last_paid_at) : '—' }}</td>
                                <td class="px-4 py-3 text-center">
                                    <button @click="expandedBillId === bill.id ? (expandedBillId = null) : (expandedBillId = bill.id)"
                                        class="inline-block text-primary hover:underline text-xs font-medium">
                                        {{ expandedBillId === bill.id ? 'Hide' : 'Show' }}
                                    </button>
                                </td>
                            </tr>
                            <!-- Expanded row for installments and actions -->
                            <tr v-if="expandedBillId === bill.id" :class="['border-t bg-muted/30']">
                                <td :colspan="8" class="px-4 py-3">
                                    <div class="space-y-3">
                                        <!-- Burndown chart (payment plans only) -->
                                        <div v-if="bill.is_installment && bill.installments.length > 0 && burndownData" class="space-y-1.5">
                                            <div class="flex items-center justify-between">
                                                <p class="text-xs font-semibold text-muted-foreground">Payment Burndown</p>
                                                <div class="flex items-center gap-4 text-xs text-muted-foreground">
                                                    <span class="flex items-center gap-1.5">
                                                        <svg width="16" height="4"><line x1="0" y1="2" x2="16" y2="2" stroke="#22c55e" stroke-width="2.5"/></svg>
                                                        Paid
                                                    </span>
                                                    <span class="flex items-center gap-1.5">
                                                        <svg width="16" height="4"><line x1="0" y1="2" x2="16" y2="2" stroke="#94a3b8" stroke-width="2" stroke-dasharray="4,2"/></svg>
                                                        Remaining
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="rounded-lg border bg-background p-2">
                                                <svg viewBox="0 0 500 160" class="w-full" style="height:160px">
                                                    <defs>
                                                        <linearGradient id="bdPaidGrad" x1="0" y1="0" x2="0" y2="1">
                                                            <stop offset="0%" stop-color="#22c55e" stop-opacity="0.25" />
                                                            <stop offset="100%" stop-color="#22c55e" stop-opacity="0.02" />
                                                        </linearGradient>
                                                    </defs>
                                                    <!-- Y-axis grid + labels -->
                                                    <g v-for="tick in burndownData!.yTicks" :key="tick.v">
                                                        <line :x1="54" :y1="tick.y" :x2="488" :y2="tick.y" stroke="currentColor" stroke-opacity="0.07" stroke-width="1" />
                                                        <text :x="50" :y="tick.y + 3.5" text-anchor="end" fill="currentColor" fill-opacity="0.5" font-size="9">{{ tick.label }}</text>
                                                    </g>
                                                    <!-- X-axis labels -->
                                                    <text v-for="tick in burndownData!.xTicks" :key="tick.n" :x="tick.x" y="155" text-anchor="middle" fill="currentColor" fill-opacity="0.5" font-size="9">{{ tick.label }}</text>
                                                    <!-- Axes -->
                                                    <line x1="54" y1="14" x2="54" y2="132" stroke="currentColor" stroke-opacity="0.15" stroke-width="1" />
                                                    <line x1="54" y1="132" x2="488" y2="132" stroke="currentColor" stroke-opacity="0.15" stroke-width="1" />
                                                    <!-- Paid area fill -->
                                                    <polygon v-if="burndownData!.areaPoints" :points="burndownData!.areaPoints" fill="url(#bdPaidGrad)" />
                                                    <!-- Remaining dashed line -->
                                                    <polyline v-if="burndownData!.unpaidPts.length >= 2" :points="burndownData!.unpaidPolyStr" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5,3" stroke-linecap="round" />
                                                    <!-- Paid solid line -->
                                                    <polyline v-if="burndownData!.paidPts.length >= 2" :points="burndownData!.paidPolyStr" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                                    <!-- Data points -->
                                                    <circle v-for="pt in burndownData!.pts" :key="pt.n" :cx="pt.svgX" :cy="pt.svgY" r="3" :fill="pt.paid ? '#22c55e' : 'none'" :stroke="pt.paid ? '#16a34a' : '#94a3b8'" stroke-width="1.5" />
                                                </svg>
                                            </div>
                                        </div>

                                        <!-- Installment schedule table -->
                                        <div v-if="bill.is_installment && bill.installments.length > 0" class="space-y-1.5">
                                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Installment Schedule</p>
                                            <div class="rounded-lg border overflow-hidden overflow-x-auto">
                                                <table class="w-full text-xs min-w-[520px]">
                                                    <thead class="bg-muted/50 text-muted-foreground">
                                                        <tr>
                                                            <th class="px-3 py-2 text-left font-medium">#</th>
                                                            <th class="px-3 py-2 text-right font-medium">Amount</th>
                                                            <th class="px-3 py-2 text-left font-medium">Due Date</th>
                                                            <th class="px-3 py-2 text-left font-medium">Status</th>
                                                            <th class="px-3 py-2 text-left font-medium">Paid On</th>
                                                            <th class="px-3 py-2 text-right font-medium">Balance After</th>
                                                            <th class="px-3 py-2 text-center font-medium">Action</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="inst in sortedInstallments(bill)" :key="inst.id"
                                                            :class="['border-t transition-colors', inst.paid_at ? 'bg-green-50/50 dark:bg-green-950/10' : '']">
                                                            <td class="px-3 py-2.5 font-bold text-muted-foreground">#{{ inst.installment_number }}</td>
                                                            <td class="px-3 py-2.5 text-right font-semibold tabular-nums">{{ fmt(inst.amount) }}</td>
                                                            <td class="px-3 py-2.5 tabular-nums">{{ inst.due_date }}</td>
                                                            <td class="px-3 py-2.5">
                                                                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', installmentStatusBadge(inst.status)]">
                                                                    {{ inst.status.replace(/_/g, ' ') }}
                                                                </span>
                                                            </td>
                                                            <td class="px-3 py-2.5 text-muted-foreground tabular-nums">{{ inst.paid_at ? fmtDatetime(inst.paid_at) : '—' }}</td>
                                                            <td class="px-3 py-2.5 text-right tabular-nums font-medium"
                                                                :class="runningRemaining(bill, inst.installment_number) === 0 ? 'text-green-600' : 'text-orange-600'">
                                                                {{ fmt(runningRemaining(bill, inst.installment_number)) }}
                                                            </td>
                                                            <td class="px-3 py-2.5 text-center">
                                                                <span v-if="inst.paid_at" class="text-green-600 font-bold">✓</span>
                                                                <button v-else-if="bill.is_active"
                                                                    @click="payInstallment(bill, inst)"
                                                                    :disabled="payingInstallmentId === inst.id"
                                                                    class="rounded bg-green-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-50">
                                                                    {{ payingInstallmentId === inst.id ? '…' : 'Pay' }}
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    </tbody>
                                                    <tfoot class="border-t bg-muted/30">
                                                        <tr>
                                                            <td colspan="4" class="px-3 py-2 text-muted-foreground">{{ bill.installments_paid }}/{{ bill.installment_count }} paid</td>
                                                            <td class="px-3 py-2 text-right font-bold text-green-600">Paid: {{ fmt(bill.installments.filter(i => i.paid_at).reduce((s, i) => s + i.amount, 0)) }}</td>
                                                            <td class="px-3 py-2 text-right font-bold text-orange-600">{{ fmt(bill.installments.filter(i => !i.paid_at).reduce((s, i) => s + i.amount, 0)) }} left</td>
                                                            <td></td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>

                                        <!-- Action buttons -->
                                        <div class="flex flex-wrap gap-2 pt-2">
                                            <button v-if="!bill.is_installment && bill.is_active" @click="payBill(bill)" :disabled="billPaying === bill.id"
                                                class="flex-1 rounded bg-green-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-green-700 disabled:opacity-50">
                                                {{ billPaying === bill.id ? 'Processing…' : '💰 Mark as Paid' }}
                                            </button>
                                            <button @click="openBillForm(bill)" class="flex-1 rounded border px-3 py-1.5 text-xs font-medium hover:bg-muted flex items-center justify-center gap-1">
                                                <Pencil class="h-3 w-3" /> Edit
                                            </button>
                                            <button @click="deleteBill(bill)" :disabled="billDeleting === bill.id"
                                                class="flex-1 rounded border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/20 disabled:opacity-50">
                                                {{ billDeleting === bill.id ? 'Deleting…' : '🗑️ Delete' }}
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div v-if="bills.length === 0" class="p-8 text-center text-muted-foreground">
                <p>No bills yet. <button @click="openBillForm()" class="text-primary hover:underline">Add one</button></p>
            </div>
        </div>

        <!-- Monthly forecast -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b flex items-center justify-between">
                <h2 class="font-bold text-sm">Payment Forecast</h2>
                <select v-model.number="forecastMonths" @change="loadForecast" class="rounded-lg border bg-background px-3 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-primary">
                    <option :value="1">1 month</option>
                    <option :value="2">2 months</option>
                    <option :value="3">3 months</option>
                    <option :value="6">6 months</option>
                    <option :value="12">12 months</option>
                </select>
            </div>

            <template v-if="billForecast">
                <!-- Mobile forecast cards (< sm) -->
                <div class="sm:hidden divide-y">
                    <template v-for="(entries, month) in billForecast.by_month" :key="month">
                        <!-- Month group header -->
                        <div class="px-4 py-2 bg-muted/40 flex items-center justify-between">
                            <p class="text-xs font-bold text-muted-foreground uppercase tracking-wide">
                                {{ monthName(parseInt((month as string).split('-')[1])) }} {{ (month as string).split('-')[0] }}
                            </p>
                            <div class="text-right">
                                <span class="font-semibold text-foreground text-sm">
                                    {{ fmt((entries as any[]).reduce((s: number, e: any) => s + e.amount, 0)) }}
                                </span>
                                <span class="ml-1 text-xs text-muted-foreground">
                                    ({{ (entries as any[]).length }} item{{ (entries as any[]).length !== 1 ? 's' : '' }})
                                </span>
                            </div>
                        </div>
                        <!-- Entry cards -->
                        <div v-for="entry in (entries as any[])" :key="`m-${entry.bill_id}-${entry.installment_id}`"
                            class="px-4 py-3 flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-sm">{{ entry.name }}</p>
                                <p v-if="entry.label" class="text-xs text-muted-foreground">{{ entry.label }}</p>
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5 text-xs text-muted-foreground">
                                    <span v-if="entry.category">{{ entry.category }}</span>
                                    <span>Due: {{ entry.due_date }}</span>
                                </div>
                                <div class="flex flex-wrap gap-1.5 mt-1.5">
                                    <span :class="['px-2 py-0.5 rounded text-xs font-medium', entry.is_installment ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400']">
                                        {{ entry.is_installment ? 'Installment' : 'Recurring' }}
                                    </span>
                                    <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', billStatusBadge(entry.status)]">
                                        {{ entry.status.replace(/_/g, ' ') }}
                                    </span>
                                </div>
                            </div>
                            <span class="font-bold text-sm tabular-nums text-right flex-shrink-0">{{ fmt(entry.amount) }}</span>
                        </div>
                    </template>
                    <!-- Total footer -->
                    <div class="px-4 py-3 bg-muted/30 flex items-center justify-between">
                        <span class="text-sm font-bold">Total ({{ forecastMonths }} month{{ forecastMonths !== 1 ? 's' : '' }})</span>
                        <span class="text-xl font-black text-orange-600 tabular-nums">{{ fmt(billForecast.total_forecast) }}</span>
                    </div>
                </div>

                <!-- Desktop forecast table (≥ sm) -->
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Payable</th>
                                <th class="px-4 py-3 text-left">Category</th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-right">Due Date</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(entries, month) in billForecast.by_month" :key="month">
                                <!-- Month group header -->
                                <tr class="border-t bg-muted/40">
                                    <td colspan="6" class="px-4 py-2 text-xs font-bold text-muted-foreground uppercase tracking-wide">
                                        {{ monthName(parseInt((month as string).split('-')[1])) }} {{ (month as string).split('-')[0] }}
                                        <span class="ml-2 font-semibold text-foreground normal-case text-sm">
                                            {{ fmt((entries as any[]).reduce((s: number, e: any) => s + e.amount, 0)) }}
                                        </span>
                                        <span class="ml-1 font-normal normal-case text-muted-foreground">
                                            ({{ (entries as any[]).length }} item{{ (entries as any[]).length !== 1 ? 's' : '' }})
                                        </span>
                                    </td>
                                </tr>
                                <!-- Entry rows -->
                                <tr v-for="entry in (entries as any[])" :key="`${entry.bill_id}-${entry.installment_id}`" class="border-t hover:bg-muted/10 transition-colors">
                                    <td class="px-4 py-2.5">
                                        <p class="font-medium">{{ entry.name }}</p>
                                        <p v-if="entry.label" class="text-xs text-muted-foreground">{{ entry.label }}</p>
                                    </td>
                                    <td class="px-4 py-2.5 text-sm text-muted-foreground">{{ entry.category ?? '—' }}</td>
                                    <td class="px-4 py-2.5">
                                        <span :class="['px-2 py-0.5 rounded text-xs font-medium', entry.is_installment ? 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400' : 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400']">
                                            {{ entry.is_installment ? 'Installment' : 'Recurring' }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right text-sm tabular-nums text-muted-foreground">{{ entry.due_date }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ fmt(entry.amount) }}</td>
                                    <td class="px-4 py-2.5">
                                        <span :class="['px-2 py-0.5 rounded-full text-xs font-medium', billStatusBadge(entry.status)]">
                                            {{ entry.status.replace(/_/g, ' ') }}
                                        </span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="border-t-2 bg-muted/30">
                            <tr>
                                <td colspan="4" class="px-4 py-3 text-sm font-bold">
                                    Total Forecast ({{ forecastMonths }} month{{ forecastMonths !== 1 ? 's' : '' }})
                                </td>
                                <td class="px-4 py-3 text-right text-xl font-black text-orange-600 tabular-nums">
                                    {{ fmt(billForecast.total_forecast) }}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>
