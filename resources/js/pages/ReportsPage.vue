<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    BarChart3, Download, RefreshCw, TrendingUp, TrendingDown,
    DollarSign, Plus, X, Search, ChevronLeft, ChevronRight, ChevronDown,
    ShoppingBag, ClipboardList, Package, Trash2, Pencil, CalendarDays,
} from 'lucide-vue-next'
import AnalyticsTab from '@/pages/reports/AnalyticsTab.vue'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Reports', href: '/reports' },
        ],
    },
})

// ── Types ─────────────────────────────────────────────────────────────────────
interface DailyReport {
    date: string; total_orders: number; total_sales: number; total_discount: number
}
interface MonthlyReport {
    month: string; total_orders: number; total_sales: number; total_discount: number
}
interface ProductSale {
    product_id: number; product_name: string; total_quantity: number; total_sales: number
}
interface FtSummary {
    period: { start: string; end: string }
    payments: { total: number; count: number }
    expenses: { total: number; count: number }
    income_adjustments: { total: number; count: number }
    payroll: { total: number; count: number }
    net: number
    by_tender: { tender: string; total: number; count: number }[]
}
interface FtTransaction {
    id: number; type: string; amount: number; description: string; transacted_at: string
    financial_balance: number | null; notes: string | null
    user?: { name: string }; tender?: { name: string }
}
interface OrderRow {
    id: number; queue_number: number | null; order_type: string; status: string
    payment_status: string; table_number: string | null; notes: string | null
    total_amount: number; items: { data: any[] } | any[]; user?: { data?: any; name?: string }
    created_at: string
}
interface InvTransaction {
    id: number; type: string; quantity: number; old_quantity: number; new_quantity: number
    reference: string | null; notes: string | null; created_at: string
    ingredient?: { id: number; name: string; unit: string }
    user?: { name: string }
}
interface Ingredient { id: number; name: string; unit: string }

const props = defineProps<{
    initialDailyReport: DailyReport
    initialProductSales: ProductSale[]
}>()

// ── Active tab ─────────────────────────────────────────────────────────────────
type Tab = 'orders' | 'inventory' | 'financial' | 'daily' | 'monthly' | 'products' | 'pl' | 'bills' | 'analytics'
const tab = ref<Tab>('orders')
const loading = ref(false)

const tabs: { key: Tab; label: string }[] = [
    { key: 'orders',    label: 'Orders' },
    { key: 'analytics', label: 'Trend Analytics' },
    { key: 'inventory', label: 'Inventory' },
    { key: 'financial', label: 'Financial' },
    { key: 'daily',     label: 'Daily Sales' },
    { key: 'monthly',   label: 'Monthly Sales' },
    { key: 'products',  label: 'Product Sales' },
    { key: 'pl',        label: 'P&L' },
    { key: 'bills',     label: 'Bills' },
]

// ── Daily / Monthly ────────────────────────────────────────────────────────────
const today = new Date().toISOString().split('T')[0]
const thirtyDaysAgo = new Date(Date.now() - 30 * 86400_000).toISOString().split('T')[0]
const selectedDate = ref(today)
const selectedYear = ref(new Date().getFullYear())
const selectedMonth = ref(new Date().getMonth() + 1)
const dailyReport = ref<DailyReport | null>(props.initialDailyReport)
const monthlyReport = ref<MonthlyReport | null>(null)

// ── Products ───────────────────────────────────────────────────────────────────
const productSales = ref<ProductSale[]>(props.initialProductSales)
const prodDateFrom = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0])
const prodDateTo = ref(today)

// ── Orders ─────────────────────────────────────────────────────────────────────
const ordSearch = ref('')
const ordDateFrom = ref(thirtyDaysAgo)
const ordDateTo = ref(today)
const ordStatus = ref('')
const ordPayment = ref('')
const ordersData = ref<OrderRow[]>([])
const ordersMeta = ref<any>(null)
const ordPage = ref(1)

// ── Inventory transactions ─────────────────────────────────────────────────────
const invDateFrom = ref(thirtyDaysAgo)
const invDateTo = ref(today)
const invType = ref('')
const invIngredientId = ref('')
const invTransactions = ref<InvTransaction[]>([])
const invMeta = ref<any>(null)
const invPage = ref(1)
const ingredients = ref<Ingredient[]>([])

// ── P&L ───────────────────────────────────────────────────────────────────────
interface PLBreakdownItem { description: string; amount: number; transacted_at: string }
interface PL {
    period: { start: string; end: string }
    revenue: { order_count: number; gross_sales: number; discounts: number; net_revenue: number }
    cogs: { total: number; has_data: boolean }
    gross_profit: number; gross_margin: number
    income_adjustments: { total: number; count: number; breakdown: PLBreakdownItem[] }
    expenses: { total: number; count: number; breakdown: PLBreakdownItem[] }
    payroll: { total: number; count: number; breakdown: PLBreakdownItem[] }
    net_profit: number; net_margin: number
    include_cogs?: boolean
}
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

const plStartDate = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0])
const plEndDate = ref(today)
const plIncludeCogs = ref(true)
const plReport = ref<PL | null>(null)

// ── Daily chart ────────────────────────────────────────────────────────────────
interface ChartDay { date: string; income: number; expense: number }
const chartDays      = ref(7)
const chartData      = ref<ChartDay[]>([])
const chartLoading   = ref(false)
const chartCollapsed = ref(false)

// ── Monthly chart ──────────────────────────────────────────────────────────────
interface MonthChartEntry { month: string; income: number; expense: number }
const monthChartData      = ref<MonthChartEntry[]>([])
const monthChartLoading   = ref(false)
const monthChartCollapsed = ref(false)

// ── Financial ─────────────────────────────────────────────────────────────────
const ftStartDate = ref(thirtyDaysAgo)
const ftEndDate = ref(today)
const ftTypeFilter = ref('')
const ftSummary = ref<FtSummary | null>(null)
const ftTransactions = ref<FtTransaction[]>([])
const ftMeta = ref<any>(null)
const ftPage = ref(1)
const showEntryForm = ref(false)
const entryForm = ref({ type: 'expense' as 'expense' | 'income_adjustment', description: '', amount: '', notes: '', transacted_at: '' })
const entrySaving = ref(false)
const ftDeleting = ref<number | null>(null)
const editingFt = ref<FtTransaction | null>(null)
const ftEditForm = ref({ amount: '', description: '', notes: '', transacted_at: '' })
const ftEditSaving = ref(false)

// ── Bills / Payables ──────────────────────────────────────────────────────
const bills = ref<Bill[]>([])
const billForecast = ref<BillForecast | null>(null)
const forecastMonths = ref(3)
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

const fmtShort = (v: number) => {
    if (v >= 1_000_000) return '₱' + (v / 1_000_000).toFixed(1) + 'M'
    if (v >= 1_000) return '₱' + (v / 1_000).toFixed(1) + 'K'
    return '₱' + Math.round(v)
}

const itemCount = (items: any) =>
    Array.isArray(items) ? items.length : (items?.data?.length ?? 0)

const fmtDatetime = (s: string) => {
    if (!s) return '—'
    const d = new Date(s)
    return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }) + ' ' +
        d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
}

const statusBadge = (s: string) => ({
    pending:   'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    preparing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    ready:     'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    completed: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    cancelled: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}[s] ?? 'bg-muted text-muted-foreground')

const payBadge = (s: string) => ({
    paid:     'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    pending:  'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    refunded: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
    voided:   'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
}[s] ?? 'bg-muted text-muted-foreground')

const invTypeBadge = (t: string) => ({
    stock_in:   'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    stock_out:  'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    adjustment: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    waste:      'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    usage:      'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    purchase:   'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
}[t] ?? 'bg-muted text-muted-foreground')

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

const orderTypeBadge = (t: string) => ({ dine_in: 'Dine-In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)

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

const billStatusLabel = (s: string) => ({
    overdue: 'Overdue', due_today: 'Due Today', upcoming: 'Due Soon',
    scheduled: 'Scheduled', inactive: 'Inactive',
}[s] ?? s)

const monthlySummary = computed(() => {
    const multiplier: Record<string, number> = {
        one_time: 0, daily: 30, weekly: 4.33, bi_weekly: 2.17,
        monthly: 1, quarterly: 1/3, semi_annual: 1/6, annual: 1/12,
    }
    return bills.value.filter(b => b.is_active).reduce((sum, b) => sum + b.amount * (multiplier[b.frequency] ?? 0), 0)
})
const overdueBills = computed(() => bills.value.filter(b => b.status === 'overdue'))
const dueSoonBills = computed(() => bills.value.filter(b => b.status === 'due_today' || b.status === 'upcoming'))

const topProducts = computed(() =>
    [...productSales.value].sort((a, b) => b.total_sales - a.total_sales).slice(0, 10)
)

const chartTotals = computed(() => {
    const income = chartData.value.reduce((s, d) => s + d.income, 0)
    const expense = chartData.value.reduce((s, d) => s + d.expense, 0)
    return { income, expense, net: income - expense }
})

const chartBars = computed(() => {
    const W = 800, H = 280, padL = 60, padR = 16, padT = 20, padB = 48
    const chartH = H - padT - padB
    const baselineY = padT + chartH
    const data = chartData.value

    const maxVal = data.length ? Math.max(...data.map(d => Math.max(d.income, d.expense)), 1) : 1
    const exp = Math.pow(10, Math.floor(Math.log10(maxVal)))
    const niceMax = Math.ceil(maxVal / exp) * exp

    const ticks = [0, 1, 2, 3, 4].map(i => ({
        y: padT + chartH - (i / 4) * chartH,
        label: fmtShort((niceMax * i) / 4),
    }))

    const dayW = data.length ? (W - padL - padR) / data.length : 1
    const barW = Math.max(2, Math.min(16, dayW * 0.34))
    const showEvery = data.length <= 14 ? 1 : data.length <= 30 ? 3 : data.length <= 60 ? 7 : 14

    const bars = data.map((d, i) => {
        const cx = padL + (i + 0.5) * dayW
        const incomeH = Math.max(0, (d.income / niceMax) * chartH)
        const expenseH = Math.max(0, (d.expense / niceMax) * chartH)
        return {
            incomeX: cx - barW - 1, incomeY: baselineY - incomeH, incomeH,
            expenseX: cx + 1, expenseY: baselineY - expenseH, expenseH,
            barW, labelX: cx, labelY: baselineY + 14,
            label: new Date(d.date + 'T00:00:00').toLocaleDateString('en-PH', { month: 'short', day: 'numeric' }),
            showLabel: i % showEvery === 0 || i === data.length - 1,
        }
    })
    return { bars, ticks, baselineY, padL }
})

const monthChartTotals = computed(() => {
    const income = monthChartData.value.reduce((s, d) => s + d.income, 0)
    const expense = monthChartData.value.reduce((s, d) => s + d.expense, 0)
    return { income, expense, net: income - expense }
})

const monthChartBars = computed(() => {
    const W = 800, H = 280, padL = 60, padR = 16, padT = 20, padB = 48
    const chartH = H - padT - padB
    const baselineY = padT + chartH
    const data = monthChartData.value

    const maxVal = data.length ? Math.max(...data.map(d => Math.max(d.income, d.expense)), 1) : 1
    const exp = Math.pow(10, Math.floor(Math.log10(maxVal)))
    const niceMax = Math.ceil(maxVal / exp) * exp

    const ticks = [0, 1, 2, 3, 4].map(i => ({
        y: padT + chartH - (i / 4) * chartH,
        label: fmtShort((niceMax * i) / 4),
    }))

    const dayW = data.length ? (W - padL - padR) / data.length : 1
    const barW = Math.max(4, Math.min(28, dayW * 0.38))

    const bars = data.map((d, i) => {
        const cx = padL + (i + 0.5) * dayW
        const incomeH = Math.max(0, (d.income / niceMax) * chartH)
        const expenseH = Math.max(0, (d.expense / niceMax) * chartH)
        return {
            incomeX: cx - barW - 1, incomeY: baselineY - incomeH, incomeH,
            expenseX: cx + 1, expenseY: baselineY - expenseH, expenseH,
            barW, labelX: cx, labelY: baselineY + 14,
            label: new Date(d.month + '-02').toLocaleDateString('en-PH', { month: 'short' }),
            showLabel: true,
        }
    })
    return { bars, ticks, baselineY, padL }
})

// ── Data loading ──────────────────────────────────────────────────────────────
const loadOrders = async (page = 1) => {
    ordPage.value = page
    const res = await api.get('/api/v1/orders', {
        params: {
            page,
            per_page: 20,
            search: ordSearch.value || undefined,
            date_from: ordDateFrom.value || undefined,
            date_to: ordDateTo.value || undefined,
            status: ordStatus.value || undefined,
            payment_status: ordPayment.value || undefined,
        },
    })
    ordersData.value = (res.data.data ?? []).map((o: any) => ({
        ...o,
        total_amount: parseFloat(o.total_amount ?? 0),
    }))
    ordersMeta.value = res.data.meta ?? null
}

const loadInventory = async (page = 1) => {
    invPage.value = page
    const res = await api.get('/api/v1/reports/inventory-transactions', {
        params: {
            page,
            date_from: invDateFrom.value || undefined,
            date_to: invDateTo.value || undefined,
            type: invType.value || undefined,
            ingredient_id: invIngredientId.value || undefined,
        },
    })
    const invRaw = res.data
    invTransactions.value = invRaw.data ?? []
    // Backend returns old-style pagination (keys at top level, no nested meta)
    invMeta.value = invRaw.meta ?? (invRaw.current_page != null ? {
        current_page: invRaw.current_page,
        last_page: invRaw.last_page,
        from: invRaw.from,
        to: invRaw.to,
        total: invRaw.total,
    } : null)
}

const loadFinancial = async (page = 1) => {
    ftPage.value = page
    loading.value = true
    try {
        const [summaryRes, listRes] = await Promise.all([
            api.get('/api/v1/financial-transactions/summary', {
                params: { start_date: ftStartDate.value, end_date: ftEndDate.value },
            }),
            api.get('/api/v1/financial-transactions', {
                params: {
                    page,
                    start_date: ftStartDate.value,
                    end_date: ftEndDate.value,
                    type: ftTypeFilter.value || undefined,
                },
            }),
        ])
        ftSummary.value = summaryRes.data
        const ftRaw = listRes.data
        ftTransactions.value = ftRaw.data ?? []
        // Backend returns flat Laravel pagination (no nested meta key)
        ftMeta.value = ftRaw.meta ?? (ftRaw.current_page != null ? {
            current_page: ftRaw.current_page,
            last_page: ftRaw.last_page,
            from: ftRaw.from,
            to: ftRaw.to,
            total: ftRaw.total,
        } : null)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load financial records')
    } finally {
        loading.value = false
    }
}

const loadPL = async () => {
    const res = await api.get('/api/v1/reports/profit-loss', {
        params: { start_date: plStartDate.value, end_date: plEndDate.value, include_cogs: plIncludeCogs.value },
    })
    plReport.value = res.data
}

const loadChartData = async () => {
    chartLoading.value = true
    try {
        const res = await api.get('/api/v1/reports/daily-chart', { params: { days: chartDays.value } })
        chartData.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load chart data')
    } finally {
        chartLoading.value = false
    }
}

const loadMonthlyChartData = async () => {
    monthChartLoading.value = true
    try {
        const res = await api.get('/api/v1/reports/monthly-chart', { params: { year: selectedYear.value } })
        monthChartData.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load monthly chart data')
    } finally {
        monthChartLoading.value = false
    }
}

const generateReport = async () => {
    // The Trend Analytics tab is a self-contained component with its own loading.
    if (tab.value === 'analytics') return
    loading.value = true
    try {
        if (tab.value === 'orders') {
            await loadOrders(1)
        } else if (tab.value === 'pl') {
            await loadPL()
        } else if (tab.value === 'daily') {
            const [salesRes] = await Promise.all([
                api.get('/api/v1/reports/daily-sales', { params: { date: selectedDate.value } }),
                loadChartData(),
            ])
            dailyReport.value = salesRes.data
        } else if (tab.value === 'monthly') {
            const [res] = await Promise.all([
                api.get('/api/v1/reports/monthly-sales', { params: { year: selectedYear.value, month: selectedMonth.value } }),
                loadMonthlyChartData(),
            ])
            monthlyReport.value = res.data
        } else if (tab.value === 'products') {
            const res = await api.get('/api/v1/reports/product-sales', {
                params: { start_date: prodDateFrom.value, end_date: prodDateTo.value },
            })
            productSales.value = res.data
        } else if (tab.value === 'inventory') {
            await loadInventory(1)
        } else if (tab.value === 'financial') {
            await loadFinancial()
        } else if (tab.value === 'bills') {
            await loadBills()
            await loadForecast()
        }
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load report')
    } finally {
        loading.value = false
    }
}

const deleteEntry = async (tx: FtTransaction) => {
    if (!confirm(`Delete "${tx.description}"? This cannot be undone.`)) return
    ftDeleting.value = tx.id
    try {
        await api.delete(`/api/v1/financial-transactions/${tx.id}`)
        toast.success('Entry deleted.')
        await loadFinancial(ftPage.value)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete entry.')
    } finally {
        ftDeleting.value = null
    }
}

const openEditFt = (tx: FtTransaction) => {
    editingFt.value = tx
    ftEditForm.value = {
        amount: String(tx.amount),
        description: tx.description,
        notes: tx.notes ?? '',
        transacted_at: tx.transacted_at.slice(0, 10),
    }
    showEntryForm.value = false
}

const updateEntry = async () => {
    if (!editingFt.value || !ftEditForm.value.description.trim() || !ftEditForm.value.amount) return
    ftEditSaving.value = true
    try {
        await api.patch(`/api/v1/financial-transactions/${editingFt.value.id}`, {
            amount: parseFloat(ftEditForm.value.amount),
            description: ftEditForm.value.description,
            notes: ftEditForm.value.notes || null,
            transacted_at: ftEditForm.value.transacted_at || null,
        })
        toast.success('Entry updated.')
        editingFt.value = null
        await loadFinancial(ftPage.value)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to update entry.')
    } finally {
        ftEditSaving.value = false
    }
}

const saveEntry = async () => {
    if (!entryForm.value.description.trim() || !entryForm.value.amount) return
    entrySaving.value = true
    try {
        await api.post('/api/v1/financial-transactions', {
            type: entryForm.value.type,
            amount: parseFloat(entryForm.value.amount),
            description: entryForm.value.description,
            notes: entryForm.value.notes || null,
            transacted_at: entryForm.value.transacted_at || null,
        })
        const label = entryForm.value.type === 'income_adjustment' ? 'Income adjustment' : 'Expense'
        toast.success(`${label} recorded.`)
        entryForm.value = { type: 'expense', description: '', amount: '', notes: '', transacted_at: '' }
        showEntryForm.value = false
        await loadFinancial()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save entry.')
    } finally {
        entrySaving.value = false
    }
}

// ── Bills actions ─────────────────────────────────────────────────────────────
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

// ── Export ────────────────────────────────────────────────────────────────────
const exportCSV = () => {
    let rows: string[][] = []
    let filename = 'report'

    if (tab.value === 'orders' && ordersData.value.length > 0) {
        filename = `orders-${ordDateFrom.value}-to-${ordDateTo.value}`
        rows = [
            ['ID', 'Queue#', 'Date', 'Type', 'Table', 'Status', 'Payment', 'Items', 'Total'],
            ...ordersData.value.map((o) => [
                String(o.id), String(o.queue_number ?? ''), o.created_at?.slice(0, 16) ?? '',
                o.order_type, o.table_number ?? '', o.status, o.payment_status,
                String(itemCount(o.items)), String(o.total_amount),
            ]),
        ]
    } else if (tab.value === 'inventory' && invTransactions.value.length > 0) {
        filename = `inventory-transactions-${invDateFrom.value}-to-${invDateTo.value}`
        rows = [
            ['Date', 'Ingredient', 'Type', 'Quantity', 'Old Stock', 'New Stock', 'Unit', 'Reference', 'Notes', 'By'],
            ...invTransactions.value.map((t) => [
                t.created_at?.slice(0, 10) ?? '', t.ingredient?.name ?? '',
                t.type, String(t.quantity), String(t.old_quantity), String(t.new_quantity),
                t.ingredient?.unit ?? '', t.reference ?? '', t.notes ?? '', t.user?.name ?? '',
            ]),
        ]
    } else if (tab.value === 'products') {
        filename = `product-sales`
        rows = [['Product', 'Qty Sold', 'Total Sales'], ...productSales.value.map((p) => [p.product_name, String(p.total_quantity), String(p.total_sales)])]
    } else if (tab.value === 'financial' && ftTransactions.value.length > 0) {
        filename = `financial-transactions-${ftStartDate.value}-to-${ftEndDate.value}`
        rows = [
            ['Date', 'Type', 'Description', 'Tender', 'Amount', 'User'],
            ...ftTransactions.value.map((t) => [
                t.transacted_at?.slice(0, 10) ?? '', t.type, t.description,
                t.tender?.name ?? '', String(t.amount), t.user?.name ?? '',
            ]),
        ]
    }

    if (rows.length === 0) { toast.info('No data to export'); return }

    const csv = rows.map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n')
    const a = document.createElement('a')
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv)
    a.download = `${filename}.csv`
    a.click()
    toast.success('CSV downloaded')
}

const printReport = () => window.print()

const switchTab = (t: Tab) => {
    tab.value = t
    generateReport()
}

onMounted(async () => {
    // Pre-load ingredient list for the inventory filter dropdown
    try {
        const res = await api.get('/api/v1/inventory')
        ingredients.value = (res.data.data ?? res.data).map((i: any) => ({ id: i.id, name: i.name, unit: i.unit }))
    } catch { /* non-critical */ }
    await generateReport()
})
</script>

<template>
    <Head title="Reports" />

    <div class="space-y-5">
        <!-- Tab bar -->
        <div class="flex flex-wrap gap-1 rounded-xl border bg-card p-1.5 shadow-sm">
            <button
                v-for="t in tabs" :key="t.key"
                @click="switchTab(t.key)"
                :class="[
                    'rounded-lg px-4 py-2 text-sm font-medium transition',
                    tab === t.key
                        ? 'bg-primary text-primary-foreground shadow-sm'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                ]"
            >{{ t.label }}</button>
        </div>

        <!-- Trend Analytics (self-contained tab with its own filters) -->
        <AnalyticsTab v-if="tab === 'analytics'" />

        <!-- Filters bar -->
        <div v-if="tab !== 'analytics'" class="rounded-xl border bg-card shadow-sm p-4">
            <div class="flex flex-wrap gap-3 items-end">

                <!-- Orders filters -->
                <template v-if="tab === 'orders'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="ordDateFrom" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="ordDateTo" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Order Status</label>
                        <select v-model="ordStatus" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All Statuses</option>
                            <option value="pending">Pending</option>
                            <option value="preparing">Preparing</option>
                            <option value="ready">Ready</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Payment</label>
                        <select v-model="ordPayment" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All</option>
                            <option value="paid">Paid</option>
                            <option value="pending">Unpaid</option>
                            <option value="refunded">Refunded</option>
                            <option value="voided">Voided</option>
                        </select></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Search</label>
                        <div class="relative">
                            <Search class="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                            <input v-model="ordSearch" type="text" placeholder="Order #, table, notes…"
                                class="rounded-lg border bg-background pl-8 pr-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary w-48"
                                @keydown.enter="generateReport" />
                        </div></div>
                </template>

                <!-- Inventory filters -->
                <template v-if="tab === 'inventory'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="invDateFrom" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="invDateTo" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Transaction Type</label>
                        <select v-model="invType" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All Types</option>
                            <option value="stock_in">Stock In</option>
                            <option value="stock_out">Stock Out</option>
                            <option value="adjustment">Adjustment</option>
                            <option value="waste">Waste</option>
                            <option value="usage">Usage</option>
                            <option value="purchase">Purchase</option>
                        </select></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Ingredient</label>
                        <select v-model="invIngredientId" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All Ingredients</option>
                            <option v-for="ing in ingredients" :key="ing.id" :value="ing.id">{{ ing.name }}</option>
                        </select></div>
                </template>

                <!-- Daily date -->
                <div v-if="tab === 'daily'">
                    <label class="text-xs font-medium text-muted-foreground block mb-1">Date</label>
                    <input v-model="selectedDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                </div>

                <!-- Monthly pickers -->
                <template v-if="tab === 'monthly'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Year</label>
                        <input v-model.number="selectedYear" type="number" min="2020" class="w-24 rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Month</label>
                        <select v-model.number="selectedMonth" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option v-for="m in 12" :key="m" :value="m">{{ monthName(m) }}</option>
                        </select></div>
                </template>

                <!-- Product sales date range -->
                <template v-if="tab === 'products'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="prodDateFrom" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="prodDateTo" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                </template>

                <!-- P&L date range -->
                <template v-if="tab === 'pl'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="plStartDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="plEndDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div class="flex items-center gap-2">
                        <input v-model="plIncludeCogs" type="checkbox" id="pl_cogs_toggle" class="rounded border-gray-300" />
                        <label for="pl_cogs_toggle" class="text-xs font-medium text-muted-foreground">Include COGS in expenses</label>
                    </div>
                </template>

                <!-- Bills forecast months -->
                <template v-if="tab === 'bills'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Forecast Period</label>
                        <select v-model.number="forecastMonths" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option :value="1">1 month</option>
                            <option :value="2">2 months</option>
                            <option :value="3">3 months</option>
                            <option :value="6">6 months</option>
                            <option :value="12">12 months</option>
                        </select>
                    </div>
                </template>

                <!-- Financial date range -->
                <template v-if="tab === 'financial'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="ftStartDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="ftEndDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">Type</label>
                        <select v-model="ftTypeFilter" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All Types</option>
                            <option value="order">Orders</option>
                            <option value="payment">Payments</option>
                            <option value="expense">Expenses</option>
                            <option value="income_adjustment">Income Adjustments</option>
                            <option value="payroll">Payroll</option>
                        </select></div>
                </template>

                <button @click="generateReport" :disabled="loading"
                    class="rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 flex items-center gap-1.5">
                    <RefreshCw v-if="loading" class="h-3.5 w-3.5 animate-spin" />
                    <BarChart3 v-else class="h-3.5 w-3.5" />
                    Generate
                </button>
                <button @click="exportCSV" class="rounded-lg border bg-background px-4 py-2 text-sm font-medium hover:bg-muted flex items-center gap-1.5">
                    <Download class="h-3.5 w-3.5" /> Export CSV
                </button>
                <button @click="printReport" class="rounded-lg border bg-background px-4 py-2 text-sm font-medium hover:bg-muted">Print</button>
            </div>
        </div>

        <!-- ── Orders ─────────────────────────────────────────────────────────── -->
        <template v-if="tab === 'orders'">
            <div v-if="ordersMeta" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><ClipboardList class="h-3 w-3" /> Total Orders</p>
                    <p class="text-3xl font-black">{{ ordersMeta.total }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Paid (this page)</p>
                    <p class="text-3xl font-black text-green-600">{{ ordersData.filter(o => o.payment_status === 'paid').length }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Unpaid (this page)</p>
                    <p class="text-3xl font-black text-yellow-600">{{ ordersData.filter(o => o.payment_status === 'pending').length }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Revenue (page)</p>
                    <p class="text-xl font-black text-green-600">{{ fmt(ordersData.filter(o => o.payment_status === 'paid').reduce((s, o) => s + o.total_amount, 0)) }}</p>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between">
                    <h2 class="font-bold text-sm flex items-center gap-2"><ShoppingBag class="h-4 w-4" /> Orders</h2>
                    <span v-if="ordersMeta" class="text-xs text-muted-foreground">
                        Page {{ ordersMeta.current_page }} of {{ ordersMeta.last_page }} &nbsp;·&nbsp; {{ ordersMeta.total }} total
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Order</th>
                                <th class="px-4 py-3 text-left">Date & Time</th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-left">Table</th>
                                <th class="px-4 py-3 text-center">Items</th>
                                <th class="px-4 py-3 text-left">Status</th>
                                <th class="px-4 py-3 text-left">Payment</th>
                                <th class="px-4 py-3 text-right">Total</th>
                                <th class="px-4 py-3 text-left">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="order in ordersData" :key="order.id"
                                @click="router.visit(`/orders/${order.id}`)"
                                class="hover:bg-muted/30 cursor-pointer transition-colors">
                                <td class="px-4 py-3">
                                    <p class="font-bold text-primary">#{{ order.id }}</p>
                                    <p v-if="order.queue_number" class="text-xs text-muted-foreground">Q{{ order.queue_number }}</p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground text-xs">{{ fmtDatetime(order.created_at) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{{ orderTypeBadge(order.order_type) }}</span></td>
                                <td class="px-4 py-3 text-muted-foreground">{{ order.table_number ?? '—' }}</td>
                                <td class="px-4 py-3 text-center font-medium">{{ itemCount(order.items) }}</td>
                                <td class="px-4 py-3"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', statusBadge(order.status)]">{{ order.status }}</span></td>
                                <td class="px-4 py-3"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', payBadge(order.payment_status)]">{{ order.payment_status }}</span></td>
                                <td class="px-4 py-3 text-right font-bold">{{ fmt(order.total_amount) }}</td>
                                <td class="px-4 py-3 text-xs text-muted-foreground max-w-[140px] truncate">{{ order.notes ?? '—' }}</td>
                            </tr>
                            <tr v-if="ordersData.length === 0 && !loading">
                                <td colspan="9" class="px-4 py-10 text-center text-muted-foreground">No orders found. Adjust filters and click Generate.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="ordersMeta && ordersMeta.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t">
                    <button @click="loadOrders(ordPage - 1)" :disabled="ordPage <= 1 || loading"
                        class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-40">
                        <ChevronLeft class="h-3.5 w-3.5" /> Prev
                    </button>
                    <span class="text-xs text-muted-foreground">Showing {{ ordersMeta.from }}–{{ ordersMeta.to }} of {{ ordersMeta.total }}</span>
                    <button @click="loadOrders(ordPage + 1)" :disabled="ordPage >= ordersMeta.last_page || loading"
                        class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-40">
                        Next <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </template>

        <!-- ── Inventory Transactions ──────────────────────────────────────────── -->
        <template v-if="tab === 'inventory'">
            <div v-if="invMeta" class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><Package class="h-3 w-3" /> Total Transactions</p>
                    <p class="text-3xl font-black">{{ invMeta.total }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Stock In (page)</p>
                    <p class="text-3xl font-black text-green-600">{{ invTransactions.filter(t => t.type === 'stock_in' || t.type === 'purchase').length }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Stock Out / Usage (page)</p>
                    <p class="text-3xl font-black text-red-600">{{ invTransactions.filter(t => ['stock_out','waste','usage'].includes(t.type)).length }}</p>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between">
                    <h2 class="font-bold text-sm flex items-center gap-2"><Package class="h-4 w-4" /> Inventory Transactions</h2>
                    <span v-if="invMeta" class="text-xs text-muted-foreground">
                        Page {{ invMeta.current_page }} of {{ invMeta.last_page }} &nbsp;·&nbsp; {{ invMeta.total }} total
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Date</th>
                                <th class="px-4 py-3 text-left">Ingredient</th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-right">Qty</th>
                                <th class="px-4 py-3 text-right">Before</th>
                                <th class="px-4 py-3 text-right">After</th>
                                <th class="px-4 py-3 text-left">Reference</th>
                                <th class="px-4 py-3 text-left">Notes</th>
                                <th class="px-4 py-3 text-left">By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="tx in invTransactions" :key="tx.id" class="hover:bg-muted/20">
                                <td class="px-4 py-2 text-muted-foreground whitespace-nowrap text-xs">{{ tx.created_at?.slice(0, 10) }}</td>
                                <td class="px-4 py-2 font-medium">
                                    {{ tx.ingredient?.name ?? '—' }}
                                    <span class="text-xs text-muted-foreground ml-1">{{ tx.ingredient?.unit }}</span>
                                </td>
                                <td class="px-4 py-2">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', invTypeBadge(tx.type)]">
                                        {{ tx.type.replace('_', ' ') }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-right font-bold" :class="['stock_in','purchase'].includes(tx.type) ? 'text-green-600' : 'text-red-600'">
                                    {{ ['stock_in','purchase'].includes(tx.type) ? '+' : '-' }}{{ tx.quantity }}
                                </td>
                                <td class="px-4 py-2 text-right text-muted-foreground">{{ tx.old_quantity }}</td>
                                <td class="px-4 py-2 text-right font-medium">{{ tx.new_quantity }}</td>
                                <td class="px-4 py-2 text-xs text-muted-foreground">{{ tx.reference ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs text-muted-foreground max-w-[140px] truncate">{{ tx.notes ?? '—' }}</td>
                                <td class="px-4 py-2 text-xs text-muted-foreground">{{ tx.user?.name ?? '—' }}</td>
                            </tr>
                            <tr v-if="invTransactions.length === 0 && !loading">
                                <td colspan="9" class="px-4 py-10 text-center text-muted-foreground">No transactions found. Adjust filters and click Generate.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="invMeta && invMeta.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t">
                    <button @click="loadInventory(invPage - 1)" :disabled="invPage <= 1 || loading"
                        class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-40">
                        <ChevronLeft class="h-3.5 w-3.5" /> Prev
                    </button>
                    <span class="text-xs text-muted-foreground">Showing {{ invMeta.from }}–{{ invMeta.to }} of {{ invMeta.total }}</span>
                    <button @click="loadInventory(invPage + 1)" :disabled="invPage >= invMeta.last_page || loading"
                        class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-40">
                        Next <ChevronRight class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </template>

        <!-- ── Financial ──────────────────────────────────────────────────────── -->
        <template v-if="tab === 'financial'">
            <div v-if="ftSummary" class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Payments</p>
                    <p class="text-2xl font-black">{{ ftSummary.payments.count }}</p>
                    <p class="text-sm font-semibold text-green-600 mt-0.5">{{ fmt(ftSummary.payments.total) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingDown class="h-3 w-3" /> Expenses</p>
                    <p class="text-2xl font-black">{{ ftSummary.expenses.count }}</p>
                    <p class="text-sm font-semibold text-red-600 mt-0.5">{{ fmt(ftSummary.expenses.total) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3 text-teal-500" /> Income Adj.</p>
                    <p class="text-2xl font-black">{{ ftSummary.income_adjustments?.count ?? 0 }}</p>
                    <p class="text-sm font-semibold text-teal-600 mt-0.5">{{ fmt(ftSummary.income_adjustments?.total ?? 0) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingDown class="h-3 w-3 text-purple-500" /> Payroll</p>
                    <p class="text-2xl font-black">{{ ftSummary.payroll?.count ?? 0 }}</p>
                    <p class="text-sm font-semibold text-purple-600 mt-0.5">{{ fmt(ftSummary.payroll?.total ?? 0) }}</p>
                </div>
                <div :class="['rounded-xl border p-4 shadow-sm', ftSummary.net >= 0 ? 'bg-green-50 dark:bg-green-950/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800']">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><DollarSign class="h-3 w-3" /> Net Cash</p>
                    <p class="text-2xl font-black" :class="ftSummary.net >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600'">{{ fmt(ftSummary.net) }}</p>
                    <p class="text-xs text-muted-foreground mt-0.5">Payments + Adj. − Expenses − Payroll</p>
                </div>
            </div>

            <div v-if="ftSummary && ftSummary.by_tender.length > 0" class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-3">Payments by Tender</h3>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div v-for="row in ftSummary.by_tender" :key="row.tender" class="flex items-center justify-between rounded-lg bg-muted/40 px-4 py-3">
                        <div>
                            <p class="text-sm font-semibold">{{ row.tender }}</p>
                            <p class="text-xs text-muted-foreground">{{ row.count }} transaction{{ row.count !== 1 ? 's' : '' }}</p>
                        </div>
                        <p class="text-base font-bold text-green-600">{{ fmt(row.total) }}</p>
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <h3 class="font-bold text-sm text-muted-foreground uppercase tracking-wider">Transaction Log</h3>
                <button @click="showEntryForm = !showEntryForm" class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted transition">
                    <Plus v-if="!showEntryForm" class="h-3.5 w-3.5" />
                    <X v-else class="h-3.5 w-3.5" />
                    {{ showEntryForm ? 'Cancel' : 'Add Entry' }}
                </button>
            </div>

            <div v-if="showEntryForm" class="rounded-xl border bg-card shadow-sm p-4">
                <!-- Type selector -->
                <div class="flex gap-2 mb-4">
                    <button
                        @click="entryForm.type = 'expense'"
                        :class="[
                            'flex-1 rounded-lg border-2 py-2 text-sm font-semibold transition',
                            entryForm.type === 'expense'
                                ? 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-400'
                                : 'border-border text-muted-foreground hover:bg-muted',
                        ]"
                    >
                        Expense / Debit
                    </button>
                    <button
                        @click="entryForm.type = 'income_adjustment'"
                        :class="[
                            'flex-1 rounded-lg border-2 py-2 text-sm font-semibold transition',
                            entryForm.type === 'income_adjustment'
                                ? 'border-teal-500 bg-teal-50 text-teal-700 dark:bg-teal-950/20 dark:text-teal-400'
                                : 'border-border text-muted-foreground hover:bg-muted',
                        ]"
                    >
                        Income Adjustment / Credit
                    </button>
                </div>
                <div class="grid sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Description *</label>
                        <input v-model="entryForm.description" type="text"
                            :placeholder="entryForm.type === 'expense' ? 'e.g. Charcoal supply, LPG refill' : 'e.g. Supplier rebate, cash correction'"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Amount (₱) *</label>
                        <input v-model="entryForm.amount" type="number" min="0.01" step="0.01" placeholder="0.00"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Date (optional)</label>
                        <input v-model="entryForm.transacted_at" type="date"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div class="sm:col-span-3">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Notes (optional)</label>
                        <input v-model="entryForm.notes" type="text" placeholder="Additional details"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div class="flex items-end">
                        <button
                            @click="saveEntry"
                            :disabled="entrySaving || !entryForm.description.trim() || !entryForm.amount"
                            :class="[
                                'w-full rounded-lg px-4 py-2 text-sm font-bold text-white disabled:opacity-50 transition',
                                entryForm.type === 'expense' ? 'bg-red-600 hover:bg-red-700' : 'bg-teal-600 hover:bg-teal-700',
                            ]"
                        >
                            {{ entrySaving ? 'Saving…' : (entryForm.type === 'expense' ? 'Save Expense' : 'Save Credit') }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="editingFt" class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-sm">Edit Transaction</h3>
                    <button @click="editingFt = null" class="rounded p-1 text-muted-foreground hover:bg-muted transition">
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="mb-3">
                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeClass(editingFt.type)]">{{ typeLabel(editingFt.type) }}</span>
                </div>
                <div class="grid sm:grid-cols-4 gap-3">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Description *</label>
                        <input v-model="ftEditForm.description" type="text"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Amount (₱) *</label>
                        <input v-model="ftEditForm.amount" type="number" min="0.01" step="0.01"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div>
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Date</label>
                        <input v-model="ftEditForm.transacted_at" type="date"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div class="sm:col-span-3">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Notes (optional)</label>
                        <input v-model="ftEditForm.notes" type="text" placeholder="Additional details"
                            class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                    </div>
                    <div class="flex items-end">
                        <button
                            @click="updateEntry"
                            :disabled="ftEditSaving || !ftEditForm.description.trim() || !ftEditForm.amount"
                            class="w-full rounded-lg px-4 py-2 text-sm font-bold text-white bg-primary hover:bg-primary/90 disabled:opacity-50 transition"
                        >
                            {{ ftEditSaving ? 'Saving…' : 'Save Changes' }}
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="ftTransactions.length > 0" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Date</th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-left">Description</th>
                                <th class="px-4 py-3 text-left">Tender</th>
                                <th class="px-4 py-3 text-right">Amount</th>
                                <th class="px-4 py-3 text-right">Balance</th>
                                <th class="px-4 py-3 text-left">By</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="tx in ftTransactions" :key="tx.id" class="hover:bg-muted/20">
                                <td class="px-4 py-2 text-muted-foreground whitespace-nowrap">{{ fmtDatetime(tx.transacted_at) }}</td>
                                <td class="px-4 py-2"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeClass(tx.type)]">{{ typeLabel(tx.type) }}</span></td>
                                <td class="px-4 py-2 max-w-xs truncate">{{ tx.description }}</td>
                                <td class="px-4 py-2 text-muted-foreground">{{ tx.tender?.name ?? '—' }}</td>
                                <td class="px-4 py-2 text-right font-bold" :class="isCredit(tx.type) ? 'text-green-600' : 'text-red-600'">
                                    {{ isCredit(tx.type) ? '+' : '-' }}{{ fmt(tx.amount) }}
                                </td>
                                <td class="px-4 py-2 text-right font-semibold" :class="(tx.financial_balance ?? 0) >= 0 ? 'text-foreground' : 'text-red-600'">
                                    {{ fmt(tx.financial_balance ?? 0) }}
                                </td>
                                <td class="px-4 py-2 text-muted-foreground text-xs">{{ tx.user?.name ?? '—' }}</td>
                                <td class="px-4 py-2 text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button
                                            v-if="tx.type !== 'order'"
                                            @click="openEditFt(tx)"
                                            class="rounded p-1 text-muted-foreground hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30 transition"
                                            title="Edit entry"
                                        >
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            v-if="tx.type === 'expense' || tx.type === 'income_adjustment'"
                                            @click="deleteEntry(tx)"
                                            :disabled="ftDeleting === tx.id"
                                            class="rounded p-1 text-muted-foreground hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 disabled:opacity-40 transition"
                                            title="Delete entry"
                                        >
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="ftMeta && ftMeta.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t">
                    <button
                        @click="loadFinancial(ftPage - 1)"
                        :disabled="ftPage <= 1 || loading"
                        class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-40">
                        <ChevronLeft class="h-3.5 w-3.5" /> Prev
                    </button>
                    <span class="text-xs text-muted-foreground">Showing {{ ftMeta.from }}–{{ ftMeta.to }} of {{ ftMeta.total }}</span>
                    <button
                        @click="loadFinancial(ftPage + 1)"
                        :disabled="ftPage >= ftMeta.last_page || loading"
                        class="flex items-center gap-1 rounded-lg border px-3 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-40">
                        Next <ChevronRight class="h-3.5 w-3.5" /></button>
                </div>
            </div>
            <div v-else-if="!loading" class="rounded-xl border bg-card p-10 text-center shadow-sm text-muted-foreground text-sm">
                Select a date range and click <strong>Generate</strong> to load financial records.
            </div>
        </template>

        <!-- ── Profit & Loss ─────────────────────────────────────────────────── -->
        <template v-if="tab === 'pl'">
            <div v-if="plReport" class="space-y-4">
                <p class="text-sm text-muted-foreground">
                    Period: <strong>{{ plReport.period.start }}</strong> to <strong>{{ plReport.period.end }}</strong>
                </p>

                <!-- P&L Statement -->
                <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                    <div class="p-4 border-b bg-muted/30">
                        <h2 class="font-bold text-base flex items-center gap-2"><TrendingUp class="h-4 w-4" /> Profit & Loss Statement</h2>
                    </div>
                    <div class="divide-y">
                        <!-- Revenue -->
                        <div class="px-5 py-4 space-y-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Revenue</p>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Gross Sales ({{ plReport.revenue.order_count }} orders)</span>
                                <span class="font-semibold">{{ fmt(plReport.revenue.gross_sales) }}</span>
                            </div>
                            <div v-if="plReport.revenue.discounts > 0" class="flex justify-between text-sm">
                                <span class="text-muted-foreground pl-4">— Discounts</span>
                                <span class="text-red-500">−{{ fmt(plReport.revenue.discounts) }}</span>
                            </div>
                            <div class="flex justify-between text-sm font-bold border-t pt-2">
                                <span>Net Revenue</span>
                                <span class="text-green-600">{{ fmt(plReport.revenue.net_revenue) }}</span>
                            </div>
                        </div>

                        <!-- COGS -->
                        <div v-if="plIncludeCogs" class="px-5 py-4 space-y-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Cost of Goods Sold (COGS)</p>
                            <div v-if="!plReport.cogs.has_data" class="text-xs text-yellow-600 dark:text-yellow-400 bg-yellow-50 dark:bg-yellow-950/20 rounded-lg px-3 py-2">
                                No cost data — set ingredient costs and product recipes to enable COGS tracking.
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-muted-foreground">Total COGS</span>
                                <span class="font-semibold text-red-500">−{{ fmt(plReport.cogs.total) }}</span>
                            </div>
                            <div class="flex justify-between text-sm font-bold border-t pt-2">
                                <span>Gross Profit <span class="text-xs font-normal text-muted-foreground">({{ plReport.gross_margin }}% margin)</span></span>
                                <span :class="plReport.gross_profit >= 0 ? 'text-green-600' : 'text-red-600'">{{ fmt(plReport.gross_profit) }}</span>
                            </div>
                        </div>

                        <!-- Other Income (income adjustments) -->
                        <div v-if="(plReport.income_adjustments?.total ?? 0) > 0" class="px-5 py-4 space-y-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Other Income / Credit Adjustments ({{ plReport.income_adjustments.count }})</p>
                            <div class="space-y-1">
                                <div v-for="adj in plReport.income_adjustments.breakdown" :key="adj.transacted_at + adj.description"
                                    class="flex justify-between text-xs text-muted-foreground pl-2">
                                    <span class="truncate max-w-xs">{{ adj.description }} <span class="opacity-60">— {{ adj.transacted_at?.slice(0, 10) }}</span></span>
                                    <span class="shrink-0 ml-4 text-teal-600">+{{ fmt(adj.amount) }}</span>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm font-semibold border-t pt-2">
                                <span>Total Other Income</span>
                                <span class="text-teal-600">+{{ fmt(plReport.income_adjustments.total) }}</span>
                            </div>
                        </div>

                        <!-- Operating Expenses -->
                        <div class="px-5 py-4 space-y-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Operating Expenses ({{ plReport.expenses.count }})</p>
                            <div v-if="plReport.expenses.breakdown.length > 0" class="space-y-1">
                                <div v-for="exp in plReport.expenses.breakdown" :key="exp.transacted_at + exp.description"
                                    class="flex justify-between text-xs text-muted-foreground pl-2">
                                    <span class="truncate max-w-xs">{{ exp.description }} <span class="opacity-60">— {{ exp.transacted_at?.slice(0, 10) }}</span></span>
                                    <span class="shrink-0 ml-4">−{{ fmt(exp.amount) }}</span>
                                </div>
                            </div>
                            <div v-else class="text-xs text-muted-foreground pl-2">No expenses recorded for this period.</div>
                            <div class="flex justify-between text-sm font-semibold border-t pt-2">
                                <span>Total Expenses</span>
                                <span class="text-red-500">−{{ fmt(plReport.expenses.total) }}</span>
                            </div>
                        </div>

                        <!-- Payroll -->
                        <div class="px-5 py-4 space-y-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-muted-foreground">Payroll Disbursements ({{ plReport.payroll?.count ?? 0 }})</p>
                            <div v-if="(plReport.payroll?.breakdown ?? []).length > 0" class="space-y-1">
                                <div v-for="pr in plReport.payroll.breakdown" :key="pr.transacted_at + pr.description"
                                    class="flex justify-between text-xs text-muted-foreground pl-2">
                                    <span class="truncate max-w-xs">{{ pr.description }} <span class="opacity-60">— {{ pr.transacted_at?.slice(0, 10) }}</span></span>
                                    <span class="shrink-0 ml-4 text-purple-600">−{{ fmt(pr.amount) }}</span>
                                </div>
                            </div>
                            <div v-else class="text-xs text-muted-foreground pl-2">No payroll disbursements for this period.</div>
                            <div class="flex justify-between text-sm font-semibold border-t pt-2">
                                <span>Total Payroll</span>
                                <span class="text-purple-600">−{{ fmt(plReport.payroll?.total ?? 0) }}</span>
                            </div>
                        </div>

                        <!-- Net Profit -->
                        <div :class="['px-5 py-5', plReport.net_profit >= 0 ? 'bg-green-50 dark:bg-green-950/20' : 'bg-red-50 dark:bg-red-950/20']">
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-base font-black" :class="plReport.net_profit >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600'">
                                        {{ plReport.net_profit >= 0 ? 'Net Profit' : 'Net Loss' }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">Net Margin: {{ plReport.net_margin }}%</p>
                                </div>
                                <p class="text-2xl font-black" :class="plReport.net_profit >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600'">
                                    {{ fmt(plReport.net_profit) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary cards -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <p class="text-xs text-muted-foreground mb-1">Gross Sales</p>
                        <p class="text-xl font-black">{{ fmt(plReport.revenue.gross_sales) }}</p>
                    </div>
                    <div v-if="plIncludeCogs" class="rounded-xl border bg-card p-4 shadow-sm">
                        <p class="text-xs text-muted-foreground mb-1">COGS</p>
                        <p class="text-xl font-black text-red-500">{{ fmt(plReport.cogs.total) }}</p>
                    </div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <p class="text-xs text-muted-foreground mb-1">Other Income</p>
                        <p class="text-xl font-black text-teal-600">{{ fmt(plReport.income_adjustments?.total ?? 0) }}</p>
                    </div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <p class="text-xs text-muted-foreground mb-1">Expenses</p>
                        <p class="text-xl font-black text-red-500">{{ fmt(plReport.expenses.total) }}</p>
                    </div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm">
                        <p class="text-xs text-muted-foreground mb-1">Payroll</p>
                        <p class="text-xl font-black text-purple-600">{{ fmt(plReport.payroll?.total ?? 0) }}</p>
                    </div>
                    <div :class="['rounded-xl border p-4 shadow-sm', plReport.net_profit >= 0 ? 'bg-green-50 dark:bg-green-950/20 border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-950/20 border-red-200 dark:border-red-800']">
                        <p class="text-xs text-muted-foreground mb-1">Net Profit</p>
                        <p class="text-xl font-black" :class="plReport.net_profit >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600'">{{ fmt(plReport.net_profit) }}</p>
                    </div>
                </div>
            </div>
            <div v-else-if="!loading" class="rounded-xl border bg-card p-10 text-center shadow-sm text-muted-foreground text-sm">
                Select a date range and click <strong>Generate</strong> to load the P&amp;L statement.
            </div>
        </template>

        <!-- ── Bills / Payables ─────────────────────────────────────────────── -->
        <template v-if="tab === 'bills'">
            <!-- Summary cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><CalendarDays class="h-3 w-3" /> Monthly Exposure</p>
                    <p class="text-xl font-black text-orange-600">{{ fmt(monthlySummary) }}</p>
                    <p class="text-xs text-muted-foreground mt-0.5">est. per month</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Annual Exposure</p>
                    <p class="text-xl font-black">{{ fmt(monthlySummary * 12) }}</p>
                    <p class="text-xs text-muted-foreground mt-0.5">est. per year</p>
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

                <div class="overflow-x-auto">
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
                        <tbody class="divide-y">
                            <tr v-for="bill in bills" :key="bill.id" :class="['hover:bg-muted/20', !bill.is_active ? 'opacity-50' : '']">
                                <td class="px-4 py-3">
                                    <p class="font-semibold">{{ bill.name }}</p>
                                    <p v-if="bill.description" class="text-xs text-muted-foreground truncate max-w-[200px]">{{ bill.description }}</p>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="bill.category" class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{{ bill.category }}</span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-4 py-3 text-right font-bold">{{ fmt(bill.amount) }}</td>
                                <td class="px-4 py-3 text-muted-foreground text-xs">{{ frequencyLabel(bill.frequency) }}</td>
                                <td class="px-4 py-3 font-medium">{{ bill.due_date }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', billStatusBadge(bill.status)]">
                                        {{ billStatusLabel(bill.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">{{ bill.last_paid_at ? fmtDatetime(bill.last_paid_at) : '—' }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1 justify-center">
                                        <button v-if="bill.is_active" @click="payBill(bill)" :disabled="billPaying === bill.id"
                                            class="rounded px-2.5 py-1 text-xs font-bold bg-green-600 text-white hover:bg-green-700 disabled:opacity-40 transition">
                                            {{ billPaying === bill.id ? '…' : 'Pay' }}
                                        </button>
                                        <button @click="openBillForm(bill)"
                                            class="rounded p-1 text-muted-foreground hover:text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950/30 transition">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button @click="deleteBill(bill)" :disabled="billDeleting === bill.id"
                                            class="rounded p-1 text-muted-foreground hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 disabled:opacity-40 transition">
                                            <Trash2 class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="bills.length === 0 && !loading">
                                <td colspan="8" class="px-4 py-10 text-center text-muted-foreground">
                                    No bills tracked yet. Click <strong>Add Bill</strong> to start tracking payables.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Forecast -->
            <div v-if="billForecast && billForecast.entries.length > 0" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between">
                    <h2 class="font-bold text-sm flex items-center gap-2">
                        <TrendingDown class="h-4 w-4 text-orange-500" />
                        Payment Forecast — Next {{ billForecast.months }} month{{ billForecast.months > 1 ? 's' : '' }}
                    </h2>
                    <p class="text-sm font-bold">Total: <span class="text-orange-600">{{ fmt(billForecast.total_forecast) }}</span></p>
                </div>
                <div class="p-4 grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="(entries, month) in billForecast.by_month" :key="month" class="rounded-xl border overflow-hidden">
                        <div class="px-4 py-2.5 bg-muted/40 border-b flex items-center justify-between">
                            <p class="text-sm font-bold">
                                {{ new Date(String(month) + '-02').toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }) }}
                            </p>
                            <p class="text-sm font-bold text-orange-600">
                                {{ fmt((entries as BillForecastEntry[]).reduce((s, e) => s + e.amount, 0)) }}
                            </p>
                        </div>
                        <div class="divide-y">
                            <div v-for="entry in (entries as BillForecastEntry[])" :key="entry.bill_id + entry.due_date"
                                class="px-4 py-2.5 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm font-medium truncate">{{ entry.name }}</p>
                                    <p class="text-xs text-muted-foreground">Due {{ entry.due_date }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-sm font-bold">{{ fmt(entry.amount) }}</p>
                                    <span :class="['rounded-full px-1.5 py-0.5 text-xs font-semibold', billStatusBadge(entry.status)]">
                                        {{ billStatusLabel(entry.status) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="!loading && tab === 'bills' && bills.length === 0" class="rounded-xl border bg-card p-8 text-center shadow-sm text-muted-foreground text-sm">
                Add bills above and click <strong>Generate</strong> to see the payment forecast.
            </div>
        </template>

        <!-- ── Daily Sales ────────────────────────────────────────────────────── -->
        <template v-if="tab === 'daily'">
            <!-- Daily income vs expense chart -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-3 sm:p-4 border-b flex flex-wrap items-center justify-between gap-2 cursor-pointer select-none"
                    @click="chartCollapsed = !chartCollapsed">
                    <h2 class="font-bold text-sm flex items-center gap-2">
                        <BarChart3 class="h-4 w-4 text-primary" /> Daily Income vs Expense
                    </h2>
                    <div class="flex items-center gap-2">
                        <div class="flex items-center gap-1" @click.stop>
                            <span class="hidden sm:inline text-xs text-muted-foreground mr-0.5">Last:</span>
                            <button
                                v-for="d in [7, 14, 30, 60, 90]" :key="d"
                                @click="chartDays = d; loadChartData()"
                                :class="[
                                    'rounded-lg px-2 py-0.5 text-xs font-semibold transition',
                                    chartDays === d
                                        ? 'bg-primary text-primary-foreground'
                                        : 'border hover:bg-muted text-muted-foreground',
                                ]"
                            >{{ d }}d</button>
                        </div>
                        <ChevronDown v-if="!chartCollapsed" class="h-4 w-4 text-muted-foreground shrink-0" />
                        <ChevronRight v-else class="h-4 w-4 text-muted-foreground shrink-0" />
                    </div>
                </div>

                <div v-show="!chartCollapsed">
                    <div v-if="chartData.length > 0" class="grid grid-cols-3 divide-x border-b text-center">
                        <div class="px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Total Income</p>
                            <p class="text-sm font-bold text-green-600">{{ fmt(chartTotals.income) }}</p>
                        </div>
                        <div class="px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Total Expense</p>
                            <p class="text-sm font-bold text-red-500">{{ fmt(chartTotals.expense) }}</p>
                        </div>
                        <div class="px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Net</p>
                            <p class="text-sm font-bold" :class="chartTotals.net >= 0 ? 'text-green-600' : 'text-red-500'">{{ fmt(chartTotals.net) }}</p>
                        </div>
                    </div>
                    <div class="px-2 pt-3 pb-2">
                        <div v-if="chartLoading" class="flex items-center justify-center h-40 text-muted-foreground text-sm">
                            <RefreshCw class="h-4 w-4 animate-spin mr-2" /> Loading chart…
                        </div>
                        <div v-else-if="chartData.length === 0" class="flex items-center justify-center h-40 text-muted-foreground text-sm">
                            Click <strong class="mx-1">Generate</strong> to load chart data.
                        </div>
                        <svg v-else viewBox="0 0 800 280" class="w-full" preserveAspectRatio="xMidYMid meet">
                            <template v-for="tick in chartBars.ticks" :key="tick.label">
                                <line :x1="chartBars.padL" :y1="tick.y" x2="784" :y2="tick.y"
                                    stroke="currentColor" stroke-opacity="0.08" stroke-width="1" />
                                <text :x="chartBars.padL - 6" :y="tick.y + 4"
                                    text-anchor="end" fill="currentColor" opacity="0.45" font-size="10">{{ tick.label }}</text>
                            </template>
                            <template v-for="bar in chartBars.bars" :key="bar.labelX">
                                <rect v-if="bar.incomeH > 0"
                                    :x="bar.incomeX" :y="bar.incomeY" :width="bar.barW" :height="bar.incomeH"
                                    fill="#22c55e" opacity="0.8" rx="1.5" />
                                <rect v-if="bar.expenseH > 0"
                                    :x="bar.expenseX" :y="bar.expenseY" :width="bar.barW" :height="bar.expenseH"
                                    fill="#ef4444" opacity="0.8" rx="1.5" />
                                <text v-if="bar.showLabel"
                                    :x="bar.labelX" :y="bar.labelY"
                                    text-anchor="middle" fill="currentColor" opacity="0.45" font-size="9">{{ bar.label }}</text>
                            </template>
                            <line :x1="chartBars.padL" :y1="chartBars.baselineY" x2="784" :y2="chartBars.baselineY"
                                stroke="currentColor" stroke-opacity="0.2" stroke-width="1" />
                        </svg>
                        <div class="flex items-center justify-center gap-6 mt-1 pb-1">
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded-sm" style="background:#22c55e;opacity:0.8"></div>
                                <span class="text-xs text-muted-foreground">Income</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded-sm" style="background:#ef4444;opacity:0.8"></div>
                                <span class="text-xs text-muted-foreground">Expense</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Single-day stats -->
            <div v-if="dailyReport" class="rounded-xl border bg-card p-4 shadow-sm">
                <h2 class="font-bold text-base mb-4">Daily Stats — {{ dailyReport.date }}</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div class="rounded-lg bg-muted/40 p-4">
                        <p class="text-xs text-muted-foreground mb-1">Total Orders</p>
                        <p class="text-3xl font-black">{{ dailyReport.total_orders }}</p>
                    </div>
                    <div class="rounded-lg bg-green-50 dark:bg-green-950/20 p-4">
                        <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Revenue</p>
                        <p class="text-2xl font-black text-green-600">{{ fmt(dailyReport.total_sales) }}</p>
                    </div>
                    <div class="rounded-lg bg-yellow-50 dark:bg-yellow-950/20 p-4">
                        <p class="text-xs text-muted-foreground mb-1">Discounts</p>
                        <p class="text-2xl font-black text-yellow-600">{{ fmt(dailyReport.total_discount) }}</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Monthly Sales ──────────────────────────────────────────────────── -->
        <template v-if="tab === 'monthly'">
            <!-- YTD monthly income vs expense chart -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-3 sm:p-4 border-b flex flex-wrap items-center justify-between gap-2 cursor-pointer select-none"
                    @click="monthChartCollapsed = !monthChartCollapsed">
                    <h2 class="font-bold text-sm flex items-center gap-2">
                        <BarChart3 class="h-4 w-4 text-primary" /> {{ selectedYear }} Monthly Income vs Expense (YTD)
                    </h2>
                    <ChevronDown v-if="!monthChartCollapsed" class="h-4 w-4 text-muted-foreground shrink-0" />
                    <ChevronRight v-else class="h-4 w-4 text-muted-foreground shrink-0" />
                </div>

                <div v-show="!monthChartCollapsed">
                    <div v-if="monthChartData.length > 0" class="grid grid-cols-3 divide-x border-b text-center">
                        <div class="px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Total Income</p>
                            <p class="text-sm font-bold text-green-600">{{ fmt(monthChartTotals.income) }}</p>
                        </div>
                        <div class="px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Total Expense</p>
                            <p class="text-sm font-bold text-red-500">{{ fmt(monthChartTotals.expense) }}</p>
                        </div>
                        <div class="px-3 py-2.5">
                            <p class="text-xs text-muted-foreground">Net</p>
                            <p class="text-sm font-bold" :class="monthChartTotals.net >= 0 ? 'text-green-600' : 'text-red-500'">{{ fmt(monthChartTotals.net) }}</p>
                        </div>
                    </div>
                    <div class="px-2 pt-3 pb-2">
                        <div v-if="monthChartLoading" class="flex items-center justify-center h-40 text-muted-foreground text-sm">
                            <RefreshCw class="h-4 w-4 animate-spin mr-2" /> Loading chart…
                        </div>
                        <div v-else-if="monthChartData.length === 0" class="flex items-center justify-center h-40 text-muted-foreground text-sm">
                            Click <strong class="mx-1">Generate</strong> to load chart data.
                        </div>
                        <svg v-else viewBox="0 0 800 280" class="w-full" preserveAspectRatio="xMidYMid meet">
                            <template v-for="tick in monthChartBars.ticks" :key="tick.label">
                                <line :x1="monthChartBars.padL" :y1="tick.y" x2="784" :y2="tick.y"
                                    stroke="currentColor" stroke-opacity="0.08" stroke-width="1" />
                                <text :x="monthChartBars.padL - 6" :y="tick.y + 4"
                                    text-anchor="end" fill="currentColor" opacity="0.45" font-size="10">{{ tick.label }}</text>
                            </template>
                            <template v-for="bar in monthChartBars.bars" :key="bar.labelX">
                                <rect v-if="bar.incomeH > 0"
                                    :x="bar.incomeX" :y="bar.incomeY" :width="bar.barW" :height="bar.incomeH"
                                    fill="#22c55e" opacity="0.8" rx="2" />
                                <rect v-if="bar.expenseH > 0"
                                    :x="bar.expenseX" :y="bar.expenseY" :width="bar.barW" :height="bar.expenseH"
                                    fill="#ef4444" opacity="0.8" rx="2" />
                                <text v-if="bar.showLabel"
                                    :x="bar.labelX" :y="bar.labelY"
                                    text-anchor="middle" fill="currentColor" opacity="0.45" font-size="10">{{ bar.label }}</text>
                            </template>
                            <line :x1="monthChartBars.padL" :y1="monthChartBars.baselineY" x2="784" :y2="monthChartBars.baselineY"
                                stroke="currentColor" stroke-opacity="0.2" stroke-width="1" />
                        </svg>
                        <div class="flex items-center justify-center gap-6 mt-1 pb-1">
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded-sm" style="background:#22c55e;opacity:0.8"></div>
                                <span class="text-xs text-muted-foreground">Income</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded-sm" style="background:#ef4444;opacity:0.8"></div>
                                <span class="text-xs text-muted-foreground">Expense</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Single-month stats -->
            <div v-if="monthlyReport" class="rounded-xl border bg-card p-4 shadow-sm">
                <h2 class="font-bold text-base mb-4">
                    Monthly Stats — {{ monthName(Number(monthlyReport.month?.split('-')[1])) }} {{ monthlyReport.month?.split('-')[0] }}
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <div class="rounded-lg bg-muted/40 p-4">
                        <p class="text-xs text-muted-foreground mb-1">Total Orders</p>
                        <p class="text-3xl font-black">{{ monthlyReport.total_orders }}</p>
                    </div>
                    <div class="rounded-lg bg-green-50 dark:bg-green-950/20 p-4">
                        <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Revenue</p>
                        <p class="text-2xl font-black text-green-600">{{ fmt(monthlyReport.total_sales) }}</p>
                    </div>
                    <div class="rounded-lg bg-yellow-50 dark:bg-yellow-950/20 p-4">
                        <p class="text-xs text-muted-foreground mb-1">Discounts</p>
                        <p class="text-2xl font-black text-yellow-600">{{ fmt(monthlyReport.total_discount) }}</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- ── Product Sales ──────────────────────────────────────────────────── -->
        <template v-if="tab === 'products'">
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b">
                    <h2 class="font-bold text-sm">Product Sales — {{ prodDateFrom }} to {{ prodDateTo }}</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">#</th>
                                <th class="px-4 py-3 text-left">Product</th>
                                <th class="px-4 py-3 text-right">Qty Sold</th>
                                <th class="px-4 py-3 text-right">Revenue</th>
                                <th class="px-4 py-3 text-left">Share</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="(item, i) in topProducts" :key="item.product_id" class="hover:bg-muted/20">
                                <td class="px-4 py-2 text-muted-foreground">{{ i + 1 }}</td>
                                <td class="px-4 py-2 font-medium">{{ item.product_name }}</td>
                                <td class="px-4 py-2 text-right">{{ item.total_quantity }}</td>
                                <td class="px-4 py-2 text-right font-bold text-green-600">{{ fmt(item.total_sales) }}</td>
                                <td class="px-4 py-2 w-40">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-1.5 bg-muted rounded-full overflow-hidden">
                                            <div class="h-full bg-primary rounded-full"
                                                :style="{ width: topProducts[0]?.total_sales ? ((Number(item.total_sales) / Number(topProducts[0].total_sales)) * 100) + '%' : '0%' }" />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="topProducts.length === 0">
                                <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No data available for this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
