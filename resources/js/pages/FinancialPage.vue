<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    BarChart3, DollarSign, Plus, Trash2, ChevronLeft, ChevronRight,
    TrendingUp, TrendingDown, ChevronDown, Search, Pencil, X,
} from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Financial', href: '/financial' },
        ],
    },
})

// ── Types ───────────────────────────────────────────────────────────────────────
interface FtSummary {
    period: { start: string; end: string }
    payments: { total: number; count: number }
    expenses: { total: number; count: number }
    income_adjustments: { total: number; count: number }
    payroll: { total: number; count: number }
    asset_deductions: { total: number; count: number }
    net: number
    balance_as_of_end: number
    balance_by_tender: { tender: string; balance: number; count: number }[]
    by_tender: { tender: string; total: number; count: number }[]
    net_by_tender: { tender: string; total_in: number; total_out: number; net: number; count: number }[]
    include_asset_deductions: boolean
}
interface PaymentTender {
    id: number; name: string; is_active: boolean
}
interface FtTransaction {
    id: number; type: string; amount: number; description: string; notes: string | null
    transacted_at: string; financial_balance: number | null
    payment_tender_id: number | null
    user?: { name: string }; tender?: { id: number; name: string }
}
interface BillsSummary {
    total_due: number; overdue: number; upcoming: number; count: number
    period: { start: string; end: string }
}

// ── Auth ──────────────────────────────────────────────────────────────────────
const page    = usePage()
const isAdmin = computed(() => ((page.props.auth as any)?.roles ?? []).includes('admin'))

// ── State ──────────────────────────────────────────────────────────────────────
const today = new Date().toISOString().split('T')[0]
const ftStartDate = ref(today)
const ftEndDate = ref(today)
const ftTypeFilter = ref('')
const loading = ref(false)
const ftSummary = ref<FtSummary | null>(null)
const billsSummary = ref<BillsSummary | null>(null)
const ftTransactions = ref<FtTransaction[]>([])
const ftMeta = ref<any>(null)
const ftPage = ref(1)
const showEntryForm = ref(false)
const entryForm = ref({ type: 'expense' as 'expense' | 'income_adjustment', description: '', amount: '', notes: '', transacted_at: '', payment_tender_id: null as number | null })
const entrySaving = ref(false)
const ftDeleting = ref<number | null>(null)
const summaryOpen = ref(true)
const showTenderBreakdown = ref(false)
const showBalanceByTender = ref(false)
const ftSearch = ref('')
const ftSortKey = ref<'transacted_at' | 'type' | 'amount' | 'description'>('transacted_at')
const ftSortDir = ref<'asc' | 'desc'>('desc')
const tenders = ref<PaymentTender[]>([])
const includeAssetDeductions = ref(true)
const editingTx = ref<FtTransaction | null>(null)
const editForm = ref({ description: '', amount: '', notes: '', transacted_at: '', payment_tender_id: null as number | null })
const editSaving = ref(false)

// ── Helpers ───────────────────────────────────────────────────────────────────
const fmt = (v: number | string | null | undefined) =>
    '₱' + parseFloat(String(v ?? 0)).toLocaleString('en-PH', { minimumFractionDigits: 2 })

const fmtDatetime = (s: string) => {
    if (!s) return '—'
    // MySQL returns "YYYY-MM-DD HH:MM:SS" (space separator); Safari rejects that format.
    // Replace the space with T so all browsers get a valid ISO-8601 string.
    const d = new Date(s.replace(' ', 'T'))
    if (isNaN(d.getTime())) return s
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: '2-digit' }) + ' ' +
        d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
}

const typeLabel = (t: string) => ({
    order: 'Order', payment: 'Payment', expense: 'Expense', income_adjustment: 'Income Adj.', payroll: 'Payroll',
}[t] ?? t)

const typeBadgeClass = (t: string) => ({
    order:             'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    payment:           'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    expense:           'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    income_adjustment: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400',
    payroll:           'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
}[t] ?? 'bg-muted text-muted-foreground')

const isCredit = (t: string) => t === 'payment' || t === 'income_adjustment'

// ── Computed ─────────────────────────────────────────────────────────────────────
const pieData = computed(() => {
    if (!ftSummary.value) return []
    const s = ftSummary.value
    // Allocation: Payments, Expenses, Income Adj., Payroll (excludes raw order count)
    const items = [
        { label: 'Payments',    value: s.payments.total,                 count: s.payments.count,                 color: '#22c55e' },
        { label: 'Expenses',    value: s.expenses.total,                 count: s.expenses.count,                 color: '#ef4444' },
        { label: 'Income Adj.', value: s.income_adjustments?.total ?? 0, count: s.income_adjustments?.count ?? 0, color: '#14b8a6' },
        { label: 'Payroll',     value: s.payroll?.total ?? 0,            count: s.payroll?.count ?? 0,            color: '#a855f7' },
    ].filter(i => i.value > 0)
    const total = items.reduce((sum, i) => sum + i.value, 0)
    if (!total) return []
    const R = 70, r = 42, cx = 90, cy = 90
    const arcPath = (sa: number, ea: number): string => {
        const sweep = ea - sa
        if (sweep >= Math.PI * 2 - 0.001) {
            const mid = sa + Math.PI
            return [
                `M${cx + R * Math.cos(sa)} ${cy + R * Math.sin(sa)}`,
                `A${R} ${R} 0 0 1 ${cx + R * Math.cos(mid)} ${cy + R * Math.sin(mid)}`,
                `A${R} ${R} 0 0 1 ${cx + R * Math.cos(ea)} ${cy + R * Math.sin(ea)}`,
                `L${cx + r * Math.cos(ea)} ${cy + r * Math.sin(ea)}`,
                `A${r} ${r} 0 0 0 ${cx + r * Math.cos(mid)} ${cy + r * Math.sin(mid)}`,
                `A${r} ${r} 0 0 0 ${cx + r * Math.cos(sa)} ${cy + r * Math.sin(sa)}Z`,
            ].join(' ')
        }
        const large = sweep > Math.PI ? 1 : 0
        return [
            `M${cx + R * Math.cos(sa)} ${cy + R * Math.sin(sa)}`,
            `A${R} ${R} 0 ${large} 1 ${cx + R * Math.cos(ea)} ${cy + R * Math.sin(ea)}`,
            `L${cx + r * Math.cos(ea)} ${cy + r * Math.sin(ea)}`,
            `A${r} ${r} 0 ${large} 0 ${cx + r * Math.cos(sa)} ${cy + r * Math.sin(sa)}Z`,
        ].join(' ')
    }
    let angle = -Math.PI / 2
    return items.map(item => {
        const sweep = (item.value / total) * 2 * Math.PI
        const sa = angle, ea = angle + sweep
        angle = ea
        return { ...item, pct: Math.round((item.value / total) * 100), path: arcPath(sa, ea) }
    })
})

const comparisonBars = computed(() => {
    if (!ftSummary.value) return []
    const s = ftSummary.value
    const income  = s.payments.total + (s.income_adjustments?.total ?? 0)
    const outflow = s.expenses.total + (s.payroll?.total ?? 0) + (s.asset_deductions?.total ?? 0)
    const payable = billsSummary.value?.total_due ?? 0
    const items = [
        { label: 'Total Income',  value: income,  barColor: 'bg-blue-500',    textColor: 'text-blue-600' },
        { label: 'Total Outflow', value: outflow, barColor: 'bg-red-500',     textColor: 'text-red-600' },
        { label: 'Net Cash',      value: s.net,   barColor: s.net >= 0 ? 'bg-emerald-500' : 'bg-red-600', textColor: s.net >= 0 ? 'text-emerald-600' : 'text-red-600' },
        { label: 'Payables Due',  value: payable, barColor: 'bg-orange-500',  textColor: 'text-orange-600' },
    ]
    const maxVal = Math.max(...items.map(i => Math.abs(i.value)), 1)
    return items.map(i => ({ ...i, pct: Math.round((Math.abs(i.value) / maxVal) * 100) }))
})

const totalIncome = computed(() => {
    if (!ftSummary.value) return 0
    return ftSummary.value.payments.total + (ftSummary.value.income_adjustments?.total ?? 0)
})

const sortedTx = computed(() => {
    let list = ftTransactions.value
    const q = ftSearch.value.trim().toLowerCase()
    if (q) {
        list = list.filter(tx =>
            tx.description.toLowerCase().includes(q) ||
            tx.type.toLowerCase().includes(q) ||
            (tx.tender?.name ?? '').toLowerCase().includes(q) ||
            (tx.user?.name ?? '').toLowerCase().includes(q)
        )
    }
    const dir = ftSortDir.value === 'asc' ? 1 : -1
    return [...list].sort((a, b) => {
        switch (ftSortKey.value) {
            case 'transacted_at': return dir * (new Date(a.transacted_at).getTime() - new Date(b.transacted_at).getTime())
            case 'amount':        return dir * (a.amount - b.amount)
            case 'type':          return dir * a.type.localeCompare(b.type)
            case 'description':   return dir * a.description.localeCompare(b.description)
        }
        return 0
    })
})

const toggleSort = (key: typeof ftSortKey.value) => {
    if (ftSortKey.value === key) ftSortDir.value = ftSortDir.value === 'asc' ? 'desc' : 'asc'
    else { ftSortKey.value = key; ftSortDir.value = 'desc' }
}

// ── Data loading ─────────────────────────────────────────────────────────────────
const loadTenders = async () => {
    try {
        const res = await api.get('/api/v1/payment-tenders')
        tenders.value = Array.isArray(res.data) ? res.data : (res.data?.data ?? [])
    } catch { /* non-fatal */ }
}

const loadFinancial = async (page = 1) => {
    ftPage.value = page
    try {
        const [summaryRes, listRes, billsRes] = await Promise.all([
            api.get('/api/v1/financial-transactions/summary', {
                params: {
                    start_date: ftStartDate.value || undefined,
                    end_date: ftEndDate.value || undefined,
                    include_asset_deductions: includeAssetDeductions.value,
                },
            }),
            api.get('/api/v1/financial-transactions', {
                params: {
                    page,
                    start_date: ftStartDate.value || undefined,
                    end_date: ftEndDate.value || undefined,
                    type: ftTypeFilter.value || undefined,
                    include_asset_deductions: includeAssetDeductions.value,
                },
            }),
            api.get('/api/v1/bills/summary', {
                params: { start_date: ftStartDate.value || undefined, end_date: ftEndDate.value || undefined },
            }).catch(() => ({ data: null })),
        ])
        ftSummary.value = summaryRes.data
        ftTransactions.value = listRes.data.data ?? []
        ftMeta.value = listRes.data.meta ?? null
        billsSummary.value = billsRes.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load transactions.')
    }
}

// ── Actions ────────────────────────────────────────────────────────────────────
const saveEntry = async () => {
    if (!entryForm.value.description.trim() || !entryForm.value.amount) return
    entrySaving.value = true
    const recordedDate = entryForm.value.transacted_at
        ? entryForm.value.transacted_at.substring(0, 10)
        : today
    const payload = {
        type: entryForm.value.type,
        amount: parseFloat(entryForm.value.amount),
        description: entryForm.value.description,
        notes: entryForm.value.notes || null,
        transacted_at: entryForm.value.transacted_at || null,
        payment_tender_id: entryForm.value.payment_tender_id || null,
    }
    try {
        await api.post('/api/v1/financial-transactions', payload)
        const label = entryForm.value.type === 'income_adjustment' ? 'Income adjustment' : 'Expense'
        toast.success(`${label} recorded.`)
        entryForm.value = { type: 'expense', description: '', amount: '', notes: '', transacted_at: '', payment_tender_id: null }
        showEntryForm.value = false
        // Widen the date filter to include the entry's date so it's always visible after save.
        if (recordedDate < ftStartDate.value) ftStartDate.value = recordedDate
        if (recordedDate > ftEndDate.value) ftEndDate.value = recordedDate
        await loadFinancial()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save entry.')
    } finally {
        entrySaving.value = false
    }
}

const startEdit = (tx: FtTransaction) => {
    editingTx.value = tx
    // Format datetime-local value: strip seconds/ms from ISO string
    const dt = tx.transacted_at ? tx.transacted_at.replace(' ', 'T').substring(0, 16) : ''
    editForm.value = {
        description: tx.description,
        amount: String(tx.amount),
        notes: tx.notes ?? '',
        transacted_at: dt,
        payment_tender_id: tx.payment_tender_id ?? null,
    }
}

const cancelEdit = () => { editingTx.value = null }

const saveEdit = async () => {
    if (!editingTx.value) return
    editSaving.value = true
    try {
        await api.patch(`/api/v1/financial-transactions/${editingTx.value.id}`, {
            description: editForm.value.description || undefined,
            amount: editForm.value.amount ? parseFloat(editForm.value.amount) : undefined,
            notes: editForm.value.notes || null,
            transacted_at: editForm.value.transacted_at || undefined,
            payment_tender_id: editForm.value.payment_tender_id || null,
        })
        toast.success('Transaction updated.')
        editingTx.value = null
        await loadFinancial(ftPage.value)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to update transaction.')
    } finally {
        editSaving.value = false
    }
}

const deleteTransaction = async (tx: FtTransaction) => {
    if (!confirm(`Delete transaction?\n${tx.description}\nAmount: ${fmt(tx.amount)}`)) return
    ftDeleting.value = tx.id
    try {
        await api.delete(`/api/v1/financial-transactions/${tx.id}`)
        toast.success('Transaction deleted.')
        await loadFinancial(ftPage.value)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete transaction.')
    } finally {
        ftDeleting.value = null
    }
}

onMounted(async () => {
    loading.value = true
    try { await Promise.all([loadFinancial(), loadTenders()]) } finally { loading.value = false }
})
</script>

<template>
    <Head title="Financial Transactions" />

    <div class="space-y-5 p-4 md:p-6">

        <!-- ── Collapsible Financial Overview ─────────────────────────────────── -->
        <div v-if="ftSummary" class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <button @click="summaryOpen = !summaryOpen"
                class="w-full px-4 py-3 flex items-center justify-between hover:bg-muted/30 transition-colors">
                <h2 class="font-bold text-sm flex items-center gap-2 flex-wrap">
                    <BarChart3 class="h-4 w-4 text-primary" />
                    Financial Overview
                    <span class="text-xs font-normal text-muted-foreground">
                        {{ ftSummary.period?.start }} – {{ ftSummary.period?.end }}
                    </span>
                    <span v-if="!includeAssetDeductions"
                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                        Asset Deductions excluded
                    </span>
                </h2>
                <ChevronDown class="h-4 w-4 text-muted-foreground transition-transform duration-200"
                    :class="summaryOpen ? 'rotate-180' : ''" />
            </button>

            <div v-show="summaryOpen" class="border-t p-4 space-y-4">
                <div class="grid lg:grid-cols-2 gap-5">

                    <!-- LEFT: Allocation donut ────────────────────────── -->
                    <div class="rounded-xl border bg-background p-4">
                        <h3 class="font-bold text-sm mb-4">Financial Allocation</h3>
                        <div v-if="pieData.length > 0" class="space-y-4">
                            <!-- Donut chart centred above the table -->
                            <div class="flex justify-center">
                                <svg viewBox="0 0 180 180" style="width:148px;height:148px">
                                    <path v-for="(slice, i) in pieData" :key="i"
                                        :d="slice.path" :fill="slice.color"
                                        class="transition-opacity hover:opacity-75" />
                                    <text x="90" y="82" text-anchor="middle" fill="currentColor" fill-opacity="0.4" font-size="10">Allocation</text>
                                    <text x="90" y="98" text-anchor="middle" fill="currentColor" font-size="13" font-weight="bold">{{ pieData.length }}</text>
                                    <text x="90" y="111" text-anchor="middle" fill="currentColor" fill-opacity="0.4" font-size="9">categories</text>
                                </svg>
                            </div>
                            <!-- Breakdown table -->
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="border-b text-muted-foreground">
                                        <th class="pb-1.5 text-left font-medium">Category</th>
                                        <th class="pb-1.5 text-right font-medium">Txns</th>
                                        <th class="pb-1.5 text-right font-medium">Amount</th>
                                        <th class="pb-1.5 pl-3 text-left font-medium">Share</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr v-for="slice in pieData" :key="slice.label">
                                        <td class="py-2">
                                            <span class="flex items-center gap-1.5">
                                                <span class="w-2 h-2 rounded-sm shrink-0" :style="`background:${slice.color}`"></span>
                                                <span class="font-medium">{{ slice.label }}</span>
                                            </span>
                                        </td>
                                        <td class="py-2 text-right tabular-nums text-muted-foreground">{{ slice.count }}</td>
                                        <td class="py-2 text-right tabular-nums font-semibold">{{ fmt(slice.value) }}</td>
                                        <td class="py-2 pl-3">
                                            <div class="flex items-center gap-1.5">
                                                <div class="w-14 h-1.5 rounded-full bg-muted overflow-hidden shrink-0">
                                                    <div class="h-full rounded-full" :style="`width:${slice.pct}%;background:${slice.color}`" />
                                                </div>
                                                <span class="tabular-nums text-muted-foreground">{{ slice.pct }}%</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="border-t border-border">
                                    <tr class="text-muted-foreground">
                                        <td class="pt-2 font-bold text-foreground">Total</td>
                                        <td class="pt-2 text-right tabular-nums">{{ pieData.reduce((s, i) => s + i.count, 0) }}</td>
                                        <td class="pt-2 text-right tabular-nums font-bold text-foreground">{{ fmt(pieData.reduce((s, i) => s + i.value, 0)) }}</td>
                                        <td class="pt-2 pl-3">100%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p v-else class="text-xs text-muted-foreground py-4 text-center">No allocation data for this period.</p>
                    </div>

                    <!-- RIGHT: Comparison panel ───────────────────────── -->
                    <div class="space-y-3">

                        <!-- Balance as of end date -->
                        <div :class="['rounded-xl border p-3 text-center',
                            (ftSummary.balance_as_of_end ?? 0) >= 0
                                ? 'bg-emerald-50 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800'
                                : 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800']">
                            <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide mb-1">Balance as of {{ ftSummary.period?.end }}</p>
                            <p class="text-xl font-black leading-tight tabular-nums"
                                :class="(ftSummary.balance_as_of_end ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                {{ fmt(ftSummary.balance_as_of_end ?? 0) }}
                            </p>
                            <p class="text-[10px] text-muted-foreground mt-0.5">running balance</p>
                        </div>

                        <!-- 3 key metric chips -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <!-- Net Cash — clickable to reveal tender breakdown -->
                            <button @click="showTenderBreakdown = !showTenderBreakdown"
                                :class="['rounded-xl border p-3 text-center w-full transition-colors',
                                    ftSummary.net >= 0
                                        ? 'bg-emerald-50 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800 hover:bg-emerald-100 dark:hover:bg-emerald-950/40'
                                        : 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800 hover:bg-red-100 dark:hover:bg-red-950/40']">
                                <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide mb-1 flex items-center justify-center gap-1">
                                    Net Cash
                                    <ChevronDown class="h-3 w-3 transition-transform duration-200" :class="showTenderBreakdown ? 'rotate-180' : ''" />
                                </p>
                                <p class="text-base font-black leading-tight tabular-nums"
                                    :class="ftSummary.net >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                    {{ fmt(ftSummary.net) }}
                                </p>
                                <p class="text-[10px] text-muted-foreground mt-0.5">{{ ftSummary.net >= 0 ? 'Surplus' : 'Deficit' }}</p>
                            </button>

                            <div class="rounded-xl border bg-orange-50 dark:bg-orange-950/20 border-orange-200 dark:border-orange-800 p-3 text-center">
                                <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide mb-1">Payables Due</p>
                                <p class="text-base font-black text-orange-600 leading-tight tabular-nums">{{ fmt(billsSummary?.total_due ?? 0) }}</p>
                                <p class="text-[10px] text-muted-foreground mt-0.5">
                                    {{ billsSummary?.count ?? 0 }} bill{{ (billsSummary?.count ?? 0) !== 1 ? 's' : '' }}
                                </p>
                            </div>

                            <div class="rounded-xl border bg-blue-50 dark:bg-blue-950/20 border-blue-200 dark:border-blue-800 p-3 text-center">
                                <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide mb-1">Total Income</p>
                                <p class="text-base font-black text-blue-600 leading-tight tabular-nums">{{ fmt(totalIncome) }}</p>
                                <p class="text-[10px] text-muted-foreground mt-0.5">payments + adj.</p>
                            </div>
                        </div>

                        <!-- Tender Balances — expanded when Net Cash is clicked -->
                        <div v-show="showTenderBreakdown"
                            class="rounded-xl border bg-background overflow-hidden transition-all duration-200">
                            <div class="px-4 py-2.5 bg-muted/40 border-b flex items-center justify-between">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Deposit / Account Balances</h3>
                                <p class="text-[10px] text-muted-foreground">per tender, this period</p>
                            </div>
                            <div v-if="ftSummary.net_by_tender?.length > 0">
                                <!-- Mobile cards -->
                                <div class="md:hidden divide-y divide-border">
                                    <div v-for="row in ftSummary.net_by_tender" :key="row.tender"
                                        class="px-4 py-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="font-semibold text-sm">{{ row.tender }}</span>
                                            <span class="text-[11px] text-muted-foreground">{{ row.count }} txn{{ row.count !== 1 ? 's' : '' }}</span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                            <span class="text-green-600 font-medium">In: +{{ fmt(row.total_in) }}</span>
                                            <span class="text-red-600 font-medium">Out: -{{ fmt(row.total_out) }}</span>
                                            <span class="font-bold"
                                                :class="row.net >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                                Net: {{ row.net >= 0 ? '+' : '' }}{{ fmt(row.net) }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="px-4 py-3 bg-muted/30">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="font-bold text-sm">Total</span>
                                            <span class="text-[11px] text-muted-foreground">{{ ftSummary.net_by_tender.reduce((s, r) => s + r.count, 0) }} txns</span>
                                        </div>
                                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                            <span class="text-green-700 font-bold">In: +{{ fmt(ftSummary.net_by_tender.reduce((s, r) => s + r.total_in, 0)) }}</span>
                                            <span class="text-red-600 font-bold">Out: -{{ fmt(ftSummary.net_by_tender.reduce((s, r) => s + r.total_out, 0)) }}</span>
                                            <span class="font-black"
                                                :class="ftSummary.net >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                                Net: {{ fmt(ftSummary.net_by_tender.reduce((s, r) => s + r.net, 0)) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <!-- Desktop table -->
                                <div class="hidden md:block overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="border-b text-muted-foreground bg-muted/20">
                                                <th class="px-4 py-2 text-left font-medium">Tender / Account</th>
                                                <th class="px-4 py-2 text-right font-medium text-green-700">Money In</th>
                                                <th class="px-4 py-2 text-right font-medium text-red-600">Money Out</th>
                                                <th class="px-4 py-2 text-right font-medium">Net</th>
                                                <th class="px-4 py-2 text-right font-medium text-muted-foreground">Txns</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-border">
                                            <tr v-for="row in ftSummary.net_by_tender" :key="row.tender"
                                                class="hover:bg-muted/20 transition-colors">
                                                <td class="px-4 py-2.5 font-semibold">{{ row.tender }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-green-600 font-medium">+{{ fmt(row.total_in) }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-red-600 font-medium">-{{ fmt(row.total_out) }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums font-bold"
                                                    :class="row.net >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                                    {{ row.net >= 0 ? '+' : '' }}{{ fmt(row.net) }}
                                                </td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ row.count }}</td>
                                            </tr>
                                        </tbody>
                                        <tfoot class="border-t border-border bg-muted/30">
                                            <tr>
                                                <td class="px-4 py-2 font-bold text-xs">Total</td>
                                                <td class="px-4 py-2 text-right tabular-nums font-bold text-green-700 text-xs">
                                                    +{{ fmt(ftSummary.net_by_tender.reduce((s, r) => s + r.total_in, 0)) }}
                                                </td>
                                                <td class="px-4 py-2 text-right tabular-nums font-bold text-red-600 text-xs">
                                                    -{{ fmt(ftSummary.net_by_tender.reduce((s, r) => s + r.total_out, 0)) }}
                                                </td>
                                                <td class="px-4 py-2 text-right tabular-nums font-black text-xs"
                                                    :class="ftSummary.net >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                                    {{ fmt(ftSummary.net_by_tender.reduce((s, r) => s + r.net, 0)) }}
                                                </td>
                                                <td class="px-4 py-2 text-right tabular-nums text-muted-foreground text-xs">
                                                    {{ ftSummary.net_by_tender.reduce((s, r) => s + r.count, 0) }}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                            <p v-else class="px-4 py-4 text-xs text-muted-foreground text-center">
                                No tender-tagged transactions in this period. Tag expenses and income adjustments to a tender when recording entries.
                            </p>
                        </div>

                        <!-- Cash flow comparison bars -->
                        <div class="rounded-xl border bg-background p-4 space-y-3">
                            <h3 class="font-bold text-sm text-muted-foreground uppercase tracking-wide text-xs">Cash Flow Comparison</h3>
                            <div v-for="bar in comparisonBars" :key="bar.label" class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-muted-foreground">{{ bar.label }}</span>
                                    <span class="font-semibold tabular-nums" :class="bar.textColor">{{ fmt(bar.value) }}</span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div :class="['h-full rounded-full transition-all duration-500', bar.barColor]"
                                        :style="`width:${bar.pct}%`" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payments by Tender (secondary detail) -->
                <div v-if="ftSummary.by_tender.length > 0" class="rounded-xl border bg-background p-4">
                    <h3 class="font-bold text-sm mb-3">Payments by Tender</h3>
                    <div class="grid sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <div v-for="row in ftSummary.by_tender" :key="row.tender"
                            class="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2.5">
                            <div>
                                <p class="text-sm font-semibold">{{ row.tender }}</p>
                                <p class="text-xs text-muted-foreground">{{ row.count }} txn{{ row.count !== 1 ? 's' : '' }}</p>
                            </div>
                            <p class="text-sm font-bold text-green-600 tabular-nums">{{ fmt(row.total) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Filters and actions bar ────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card shadow-sm p-4">
            <div class="flex flex-col sm:flex-row flex-wrap gap-3 sm:items-end">
                <div class="flex gap-3 flex-1">
                    <div class="flex-1 min-w-0">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="ftStartDate" type="date" @change="loadFinancial()"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div class="flex-1 min-w-0">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="ftEndDate" type="date" @change="loadFinancial()"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                </div>
                <div class="flex gap-3 flex-1 sm:flex-none">
                    <div class="flex-1 sm:flex-none">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Type</label>
                        <select v-model="ftTypeFilter" @change="loadFinancial()"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All Types</option>
                            <option value="payment">Payment</option>
                            <option value="expense">Expense</option>
                            <option value="income_adjustment">Income Adjustment</option>
                            <option value="payroll">Payroll</option>
                        </select>
                    </div>
                    <div class="flex-1 sm:flex-none">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Search</label>
                        <div class="relative">
                            <Search class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground pointer-events-none" />
                            <input v-model="ftSearch" type="text" placeholder="Filter…"
                                class="w-full pl-8 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary sm:w-52" />
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-between sm:justify-start sm:flex-col sm:items-start gap-3 sm:gap-1">
                    <label class="text-xs font-medium text-muted-foreground">Include Asset Deductions</label>
                    <button @click="includeAssetDeductions = !includeAssetDeductions; loadFinancial()"
                        :class="['relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200',
                            includeAssetDeductions ? 'bg-primary' : 'bg-muted-foreground/30']"
                        role="switch" :aria-checked="includeAssetDeductions">
                        <span :class="['pointer-events-none block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform duration-200',
                            includeAssetDeductions ? 'translate-x-5' : 'translate-x-0']" />
                    </button>
                </div>
                <button @click="showEntryForm = !showEntryForm"
                    class="flex items-center justify-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 sm:self-end">
                    <Plus class="h-3.5 w-3.5" /> Record Entry
                </button>
            </div>
        </div>

        <!-- ── Entry form ─────────────────────────────────────────────────────────── -->
        <div v-if="showEntryForm" class="rounded-xl border bg-card shadow-sm p-4">
            <p class="text-sm font-bold mb-3">Record Expense or Income Adjustment</p>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Type *</label>
                    <select v-model="entryForm.type"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="expense">Expense</option>
                        <option value="income_adjustment">Income Adjustment</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Amount (₱) *</label>
                    <input v-model="entryForm.amount" type="number" min="0.01" step="0.01" placeholder="0.00"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Description *</label>
                    <input v-model="entryForm.description" type="text" placeholder="e.g. Office supplies, Customer refund"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Date/Time</label>
                    <input v-model="entryForm.transacted_at" type="datetime-local"
                        min="2000-01-01T00:00" max="2099-12-31T23:59"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Notes</label>
                    <input v-model="entryForm.notes" type="text" placeholder="Optional reference"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">
                        Tender / Account
                        <span class="text-muted-foreground/60 font-normal">(optional)</span>
                    </label>
                    <select v-model="entryForm.payment_tender_id"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                        <option :value="null">— Not tagged —</option>
                        <option v-for="t in tenders" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>
            </div>
            <div class="flex gap-2 mt-3">
                <button @click="saveEntry" :disabled="entrySaving || !entryForm.description.trim() || !entryForm.amount"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                    {{ entrySaving ? 'Saving…' : 'Record Entry' }}
                </button>
                <button @click="showEntryForm = false" class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </div>

        <!-- ── Edit Entry form ────────────────────────────────────────────────────── -->
        <div v-if="editingTx" class="rounded-xl border border-primary/30 bg-card shadow-sm p-4">
            <div class="flex items-center justify-between mb-3">
                <p class="text-sm font-bold flex items-center gap-2">
                    <Pencil class="h-4 w-4 text-primary" />
                    Edit {{ typeLabel(editingTx.type) }}
                    <span class="text-xs text-muted-foreground font-normal">#{{ editingTx.id }}</span>
                </p>
                <button @click="cancelEdit" class="text-muted-foreground hover:text-foreground">
                    <X class="h-4 w-4" />
                </button>
            </div>
            <div class="grid sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Amount (₱) *</label>
                    <input v-model="editForm.amount" type="number" min="0.01" step="0.01"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Tender / Account</label>
                    <select v-model="editForm.payment_tender_id"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                        <option :value="null">— Not tagged —</option>
                        <option
                            v-if="editingTx?.tender && !tenders.some(t => t.id === editingTx!.tender!.id)"
                            :value="editingTx.tender.id">{{ editingTx.tender.name }}</option>
                        <option v-for="t in tenders" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Description *</label>
                    <input v-model="editForm.description" type="text"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Date/Time</label>
                    <input v-model="editForm.transacted_at" type="datetime-local"
                        min="2000-01-01T00:00" max="2099-12-31T23:59"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
                <div>
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Notes</label>
                    <input v-model="editForm.notes" type="text" placeholder="Optional reference"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>
            </div>
            <div class="flex gap-2 mt-3">
                <button @click="saveEdit" :disabled="editSaving || !editForm.description.trim() || !editForm.amount"
                    class="rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                    {{ editSaving ? 'Saving…' : 'Save Changes' }}
                </button>
                <button @click="cancelEdit" class="rounded-lg border px-4 py-2 text-sm font-medium hover:bg-muted">Cancel</button>
            </div>
        </div>

        <!-- ── Transactions ────────────────────────────────────────────────────────── -->
        <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b flex items-center justify-between">
                <h2 class="font-bold text-sm flex items-center gap-2"><DollarSign class="h-4 w-4" /> Transactions</h2>
                <div class="flex items-center gap-3">
                    <p v-if="ftSearch" class="text-xs text-muted-foreground">{{ sortedTx.length }} result{{ sortedTx.length !== 1 ? 's' : '' }}</p>
                    <button v-if="ftSummary?.balance_by_tender?.length"
                        @click="showBalanceByTender = !showBalanceByTender"
                        :class="['flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors',
                            showBalanceByTender ? 'bg-primary text-primary-foreground border-primary' : 'hover:bg-muted']">
                        Balance by Tender
                        <ChevronDown class="h-3 w-3 transition-transform duration-200" :class="showBalanceByTender ? 'rotate-180' : ''" />
                    </button>
                </div>
            </div>

            <!-- Balance by Tender collapsible -->
            <div v-show="showBalanceByTender && ftSummary?.balance_by_tender?.length"
                class="border-b bg-muted/20">
                <div class="p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground mb-2">
                        Running Balance by Tender — as of {{ ftSummary?.period?.end }}
                    </p>
                    <!-- Mobile -->
                    <div class="md:hidden space-y-1.5">
                        <div v-for="row in ftSummary?.balance_by_tender" :key="row.tender"
                            class="flex items-center justify-between rounded-lg bg-background border px-3 py-2">
                            <div>
                                <span class="text-sm font-semibold">{{ row.tender }}</span>
                                <span class="ml-2 text-[11px] text-muted-foreground">{{ row.count }} txn{{ row.count !== 1 ? 's' : '' }}</span>
                            </div>
                            <span class="font-bold tabular-nums text-sm"
                                :class="row.balance >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                {{ row.balance >= 0 ? '' : '-' }}{{ fmt(Math.abs(row.balance)) }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between rounded-lg bg-muted/60 border px-3 py-2">
                            <span class="text-sm font-bold">Total</span>
                            <span class="font-black tabular-nums text-sm"
                                :class="(ftSummary?.balance_as_of_end ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                {{ fmt(ftSummary?.balance_as_of_end ?? 0) }}
                            </span>
                        </div>
                    </div>
                    <!-- Desktop -->
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="border-b text-muted-foreground">
                                    <th class="pb-1.5 text-left font-medium">Tender / Account</th>
                                    <th class="pb-1.5 text-right font-medium">Txns</th>
                                    <th class="pb-1.5 text-right font-medium">Running Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="row in ftSummary?.balance_by_tender" :key="row.tender"
                                    class="hover:bg-muted/20 transition-colors">
                                    <td class="py-1.5 font-semibold">{{ row.tender }}</td>
                                    <td class="py-1.5 text-right tabular-nums text-muted-foreground">{{ row.count }}</td>
                                    <td class="py-1.5 text-right tabular-nums font-bold"
                                        :class="row.balance >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                        {{ row.balance >= 0 ? '' : '-' }}{{ fmt(Math.abs(row.balance)) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-border">
                                <tr>
                                    <td class="pt-1.5 font-bold">Total</td>
                                    <td class="pt-1.5 text-right tabular-nums text-muted-foreground">
                                        {{ ftSummary?.balance_by_tender?.reduce((s, r) => s + r.count, 0) }}
                                    </td>
                                    <td class="pt-1.5 text-right tabular-nums font-black"
                                        :class="(ftSummary?.balance_as_of_end ?? 0) >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600'">
                                        {{ fmt(ftSummary?.balance_as_of_end ?? 0) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Mobile card list -->
            <div class="md:hidden divide-y">
                <div v-for="tx in sortedTx" :key="tx.id"
                    :class="['px-4 py-3 transition-colors', editingTx?.id === tx.id ? 'bg-primary/5 dark:bg-primary/10' : 'hover:bg-muted/20']">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span :class="['px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap', typeBadgeClass(tx.type)]">
                                    {{ typeLabel(tx.type) }}
                                </span>
                                <span class="text-xs text-muted-foreground tabular-nums">{{ fmtDatetime(tx.transacted_at) }}</span>
                            </div>
                            <p class="text-sm font-medium leading-snug">{{ tx.description }}</p>
                            <div class="flex items-center gap-2 mt-1 text-xs text-muted-foreground flex-wrap">
                                <span v-if="tx.tender?.name">{{ tx.tender.name }}</span>
                                <span v-if="tx.user?.name" class="opacity-60">{{ tx.user.name }}</span>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            <span :class="['font-bold tabular-nums text-sm', isCredit(tx.type) ? 'text-green-600' : 'text-red-600']">
                                {{ isCredit(tx.type) ? '+' : '-' }}{{ fmt(tx.amount) }}
                            </span>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ fmt(tx.financial_balance ?? 0) }}</span>
                            <div class="flex items-center gap-2 mt-1">
                                <button v-if="tx.type !== 'order'" @click="startEdit(tx)"
                                    :class="['hover:text-primary transition-colors', editingTx?.id === tx.id ? 'text-primary' : 'text-muted-foreground']"
                                    title="Edit">
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button v-if="isAdmin || ['expense', 'income_adjustment'].includes(tx.type)"
                                    @click="deleteTransaction(tx)" :disabled="ftDeleting === tx.id"
                                    class="text-red-600 hover:text-red-700 disabled:opacity-50 transition-colors"
                                    title="Delete">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground whitespace-nowrap" @click="toggleSort('transacted_at')">
                                <span class="flex items-center gap-1">Date
                                    <span v-if="ftSortKey === 'transacted_at'" class="text-primary">{{ ftSortDir === 'asc' ? '↑' : '↓' }}</span>
                                    <span v-else class="opacity-30">↕</span>
                                </span>
                            </th>
                            <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground" @click="toggleSort('type')">
                                <span class="flex items-center gap-1">Type
                                    <span v-if="ftSortKey === 'type'" class="text-primary">{{ ftSortDir === 'asc' ? '↑' : '↓' }}</span>
                                    <span v-else class="opacity-30">↕</span>
                                </span>
                            </th>
                            <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground" @click="toggleSort('description')">
                                <span class="flex items-center gap-1">Description
                                    <span v-if="ftSortKey === 'description'" class="text-primary">{{ ftSortDir === 'asc' ? '↑' : '↓' }}</span>
                                    <span v-else class="opacity-30">↕</span>
                                </span>
                            </th>
                            <th class="px-4 py-3 text-left">Tender</th>
                            <th class="px-4 py-3 text-right cursor-pointer select-none hover:text-foreground whitespace-nowrap" @click="toggleSort('amount')">
                                <span class="flex items-center justify-end gap-1">Amount
                                    <span v-if="ftSortKey === 'amount'" class="text-primary">{{ ftSortDir === 'asc' ? '↑' : '↓' }}</span>
                                    <span v-else class="opacity-30">↕</span>
                                </span>
                            </th>
                            <th class="px-4 py-3 text-right whitespace-nowrap">Balance</th>
                            <th class="px-4 py-3 text-left">By</th>
                            <th class="px-4 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tx in sortedTx" :key="tx.id"
                            :class="['border-t transition-colors', editingTx?.id === tx.id ? 'bg-primary/5 dark:bg-primary/10' : 'hover:bg-muted/20']">
                            <td class="px-4 py-3 text-sm tabular-nums whitespace-nowrap">{{ fmtDatetime(tx.transacted_at) }}</td>
                            <td class="px-4 py-3">
                                <span :class="['px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap', typeBadgeClass(tx.type)]">
                                    {{ typeLabel(tx.type) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm font-medium">{{ tx.description }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ tx.tender?.name ?? '—' }}</td>
                            <td :class="['px-4 py-3 text-right font-semibold tabular-nums', isCredit(tx.type) ? 'text-green-600' : 'text-red-600']">
                                {{ isCredit(tx.type) ? '+' : '-' }}{{ fmt(tx.amount) }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm tabular-nums">{{ fmt(tx.financial_balance ?? 0) }}</td>
                            <td class="px-4 py-3 text-sm text-muted-foreground">{{ tx.user?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex items-center justify-center gap-1">
                                    <button v-if="tx.type !== 'order'"
                                        @click="startEdit(tx)"
                                        :class="['hover:text-primary transition-colors', editingTx?.id === tx.id ? 'text-primary' : 'text-muted-foreground']"
                                        title="Edit">
                                        <Pencil class="h-4 w-4" />
                                    </button>
                                    <button v-if="isAdmin || ['expense', 'income_adjustment'].includes(tx.type)"
                                        @click="deleteTransaction(tx)" :disabled="ftDeleting === tx.id"
                                        class="text-red-600 hover:text-red-700 disabled:opacity-50 transition-colors"
                                        title="Delete">
                                        <Trash2 class="h-4 w-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="sortedTx.length === 0" class="p-8 text-center text-muted-foreground">
                <p>{{ ftSearch ? 'No transactions match the search.' : 'No transactions for the selected period.' }}</p>
            </div>

            <!-- Pagination -->
            <div v-if="ftMeta && ftMeta.last_page > 1" class="p-4 border-t flex items-center justify-between">
                <button @click="loadFinancial(ftPage - 1)" :disabled="ftPage === 1"
                    class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-50">
                    <ChevronLeft class="h-4 w-4" /> Previous
                </button>
                <p class="text-xs text-muted-foreground">Page {{ ftPage }} of {{ ftMeta.last_page }}</p>
                <button @click="loadFinancial(ftPage + 1)" :disabled="ftPage === ftMeta.last_page"
                    class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-50">
                    Next <ChevronRight class="h-4 w-4" />
                </button>
            </div>
        </div>

    </div>
</template>
