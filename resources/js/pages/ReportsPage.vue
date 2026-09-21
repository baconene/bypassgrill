<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    BarChart3, Download, RefreshCw, TrendingUp, TrendingDown,
    DollarSign, Plus, X, Search, ChevronLeft, ChevronRight, ChevronDown,
    ShoppingBag, ClipboardList, Package, Trash2, Pencil, CalendarDays,
    ArrowUp, ArrowDown, ChevronsUpDown, CalendarRange, Flame, Printer,
    Receipt, Scale, Timer,
} from 'lucide-vue-next'
import AnalyticsTab from '@/pages/reports/AnalyticsTab.vue'
import ServingTimeTab from '@/pages/reports/ServingTimeTab.vue'

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
    financial_balance?: number | null; notes: string | null; order_id?: number | null
    user?: { name: string }; tender?: { name: string }
}
interface OrderRow {
    id: number; queue_number: number | null; order_type: string; status: string
    payment_status: string; table_number: string | null; notes: string | null
    customer_name: string | null; total_amount: number
    items: { data: any[] } | any[]; user?: { data?: any; name?: string }
    created_at: string
    payments?: { id: number; method: string; amount: number; status: string }[]
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
type Tab = 'orders' | 'inventory' | 'financial' | 'daily' | 'monthly' | 'products' | 'pl' | 'bills' | 'heatmap' | 'analytics' | 'serving'
const tab = ref<Tab>('orders')
const loading = ref(false)

// Reports grouped by the question they answer. 'financial' has no entry: the Financial
// page replaced it, but its tab still works when opened by URL.
const tabGroups = [
    { label: 'Sales', tabs: [
        { key: 'orders' as Tab,    label: 'Orders',        icon: ClipboardList, hint: 'Every order in a date range. Search, filter by product, and open any order.' },
        { key: 'daily' as Tab,     label: 'Daily sales',   icon: CalendarDays,  hint: 'One day at a glance, with income and expenses for the last few weeks.' },
        { key: 'monthly' as Tab,   label: 'Monthly sales', icon: CalendarRange, hint: 'A month at a glance, set against the rest of the year.' },
        { key: 'products' as Tab,  label: 'Products',      icon: ShoppingBag,   hint: 'Best sellers by revenue. Open a product to see its daily sales.' },
        { key: 'analytics' as Tab, label: 'Trends',        icon: TrendingUp,    hint: 'Sales trends over time.' },
    ] },
    { label: 'Money', tabs: [
        { key: 'pl' as Tab,    label: 'Profit & loss', icon: Scale,   hint: 'Revenue, costs and profit for any period, compared with the period before.' },
        { key: 'bills' as Tab, label: 'Bills',         icon: Receipt, hint: 'Recurring bills and payment plans, with what is due next.' },
    ] },
    { label: 'Operations', tabs: [
        { key: 'inventory' as Tab, label: 'Inventory',    icon: Package, hint: 'Every stock movement: deliveries, usage, waste and counts.' },
        { key: 'heatmap' as Tab,   label: 'Peak hours',   icon: Flame,   hint: 'When orders come in, by day of the week and hour.' },
        { key: 'serving' as Tab,   label: 'Serving time', icon: Timer,   hint: 'How long orders take from placing to serving.' },
    ] },
]
const allTabs = tabGroups.flatMap((g) => g.tabs)
const activeTabInfo = computed(() =>
    allTabs.find((t) => t.key === tab.value) ?? { hint: 'Income, expenses and ledger entries.' },
)

// ── Daily / Monthly ────────────────────────────────────────────────────────────
const toManilaDate = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: 'Asia/Manila' })
const manilaToday = () => toManilaDate(new Date())
const daysAgo = (n: number) => toManilaDate(new Date(Date.now() - n * 864e5))
const manilaMonthStart = () => { const d = new Date(); return toManilaDate(new Date(d.getFullYear(), d.getMonth(), 1)) }
const selectedDate = ref(manilaToday())
const selectedYear = ref(new Date().getFullYear())
const selectedMonth = ref(new Date().getMonth() + 1)
const dailyReport = ref<DailyReport | null>(props.initialDailyReport)
const monthlyReport = ref<MonthlyReport | null>(null)

// ── FT Breakdown (daily + monthly) ────────────────────────────────────────────
interface FtBreakdownType  { type: string; total: number; count: number }
interface FtBreakdownTender { tender: string; total_in: number; total_out: number; net: number; count: number }
interface FtBreakdown { period: { start: string; end: string }; by_type: FtBreakdownType[]; by_tender: FtBreakdownTender[] }
const ftBreakdown = ref<FtBreakdown | null>(null)

const ftTypeLabel: Record<string, string> = {
    payment:           'Payments (Income)',
    income_adjustment: 'Income Adjustment',
    expense:           'Expense',
    payroll:           'Payroll',
    asset_deduction:   'Asset Deduction',
    payout_share:      'Profit Distribution Payout',
}
const ftTypeColor: Record<string, string> = {
    payment:           'text-green-600',
    income_adjustment: 'text-teal-600',
    expense:           'text-red-500',
    payroll:           'text-purple-600',
    asset_deduction:   'text-orange-500',
    payout_share:      'text-indigo-600',
}

const loadFtBreakdown = async (startDate: string, endDate: string) => {
    ftBreakdown.value = null
    const res = await api.get('/api/v1/reports/ft-breakdown', { params: { start_date: startDate, end_date: endDate } })
    ftBreakdown.value = res.data
}

// ── Products ───────────────────────────────────────────────────────────────────
const productSales = ref<ProductSale[]>(props.initialProductSales)
const prodDateFrom = ref(manilaMonthStart())
const prodDateTo = ref(manilaToday())

// ── Orders ─────────────────────────────────────────────────────────────────────
const ordSearch = ref('')
const ordDateFrom = ref(daysAgo(30))
const ordDateTo = ref(manilaToday())
const ordStatus = ref('')
const ordPayment = ref('')
const ordProductIds = ref<number[]>([])
const ordProductDropdown = ref(false)
const allProducts = ref<{ id: number; name: string }[]>([])
const ordersData = ref<OrderRow[]>([])
const ordersMeta = ref<any>(null)
const ordersSummary = ref<{ total_count: number; paid_count: number; unpaid_count: number; paid_revenue: number } | null>(null)
const ordPage = ref(1)
const ordSortBy = ref('created_at')
const ordSortDir = ref<'asc' | 'desc'>('desc')
const ordDeleting = ref<number | null>(null)

const buildOrdBackUrl = () => {
    const p = new URLSearchParams()
    p.set('tab', 'orders')
    if (ordDateFrom.value) p.set('df', ordDateFrom.value)
    if (ordDateTo.value) p.set('dt', ordDateTo.value)
    if (ordStatus.value) p.set('st', ordStatus.value)
    if (ordPayment.value) p.set('py', ordPayment.value)
    if (ordSearch.value) p.set('q', ordSearch.value)
    if (ordProductIds.value.length) p.set('pids', ordProductIds.value.join(','))
    if (ordPage.value > 1) p.set('pg', String(ordPage.value))
    if (ordSortBy.value !== 'created_at') p.set('sb', ordSortBy.value)
    if (ordSortDir.value !== 'desc') p.set('sd', ordSortDir.value)
    return '/reports?' + p.toString()
}

const sortOrders = (col: string) => {
    if (ordSortBy.value === col) {
        ordSortDir.value = ordSortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        ordSortBy.value = col
        ordSortDir.value = col === 'total_amount' ? 'desc' : 'asc'
    }
    loadOrders(1)
}

// ── Inventory transactions ─────────────────────────────────────────────────────
const invDateFrom = ref(daysAgo(30))
const invDateTo = ref(manilaToday())
const invType = ref('')
const invIngredientId = ref('')
const invTransactions = ref<InvTransaction[]>([])
const invMeta = ref<any>(null)
const invPage = ref(1)
const ingredients = ref<Ingredient[]>([])

// Inventory adjustment modal
const adjustingTx = ref<InvTransaction | null>(null)
const adjType = ref('stock_in')
const adjQty = ref(0)
const adjNotes = ref('')
const adjSaving = ref(false)

const openAdjust = (tx: InvTransaction) => {
    adjustingTx.value = tx
    adjType.value = 'stock_in'
    adjQty.value = 0
    adjNotes.value = ''
}

const saveAdjust = async () => {
    if (!adjustingTx.value?.ingredient?.id || adjQty.value <= 0) return
    adjSaving.value = true
    try {
        await api.post('/api/v1/inventory/adjust', {
            ingredient_id: adjustingTx.value.ingredient.id,
            type: adjType.value,
            quantity: adjQty.value,
            notes: adjNotes.value || null,
        })
        toast.success(`${adjustingTx.value.ingredient.name} adjusted successfully`)
        adjustingTx.value = null
        await loadInventory(invPage.value)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to adjust stock.')
    } finally {
        adjSaving.value = false
    }
}

// ── P&L ───────────────────────────────────────────────────────────────────────
interface PLBreakdownItem { description: string; amount: number; transacted_at: string }
interface PL {
    period: { start: string; end: string }
    revenue: { order_count: number; gross_sales: number; discounts: number; net_revenue: number }
    cogs: { total: number; has_data: boolean }
    gross_profit: number; gross_margin: number
    income_adjustments: { total: number; count: number; breakdown: PLBreakdownItem[] }
    expenses: { total: number; count: number; breakdown: PLBreakdownItem[] }
    inventory_purchases: { total: number; count: number; included_in_expenses: boolean; breakdown: PLBreakdownItem[] }
    payroll: { total: number; count: number; breakdown: PLBreakdownItem[] }
    payout_share?: { total: number; count: number; breakdown: PLBreakdownItem[] }
    net_profit: number; net_margin: number
    include_cogs?: boolean
    unpaid_completed?: { total: number; count: number }
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

const plStartDate = ref(manilaMonthStart())
const plEndDate = ref(manilaToday())
const plIncludeCogs = ref(true)
const plReport     = ref<PL | null>(null)

// ── P&L period, comparison and statement ──────────────────────────────────────
type PlPreset = 'month' | 'lastMonth' | '30d' | 'quarter' | 'year' | 'custom'
const plPresets: { key: PlPreset; label: string }[] = [
    { key: 'month', label: 'This month' },
    { key: 'lastMonth', label: 'Last month' },
    { key: '30d', label: 'Last 30 days' },
    { key: 'quarter', label: 'This quarter' },
    { key: 'year', label: 'This year' },
    { key: 'custom', label: 'Custom' },
]
const plPreset = ref<PlPreset>('month')
const plCompare = ref(true)
const plPrev = ref<PL | null>(null)
const plOpen = ref<Record<string, boolean>>({})
const plSpendActive = ref<string | null>(null)

// Calendar maths on plain Y-M-D values, independent of the browser's timezone.
const parseYmd = (s: string) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d) }
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const addDays = (d: Date, n: number) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)
const round2 = (v: number) => Math.round(v * 100) / 100

const setPlPreset = (key: PlPreset) => {
    plPreset.value = key
    if (key === 'custom') return
    const today = parseYmd(manilaToday())
    const y = today.getFullYear(), m = today.getMonth()
    const ranges: Record<Exclude<PlPreset, 'custom'>, [Date, Date]> = {
        month: [new Date(y, m, 1), today],
        lastMonth: [new Date(y, m - 1, 1), new Date(y, m, 0)],
        '30d': [addDays(today, -29), today],
        quarter: [new Date(y, Math.floor(m / 3) * 3, 1), today],
        year: [new Date(y, 0, 1), today],
    }
    plStartDate.value = ymd(ranges[key][0])
    plEndDate.value = ymd(ranges[key][1])
    generateReport()
}

// The period to compare with: the same days of the previous month when the range
// starts on the 1st within one month, otherwise the same number of days just before.
const plPrevRange = (start: string, end: string): [string, string] => {
    const s = parseYmd(start), e = parseYmd(end)
    if (s.getDate() === 1 && s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
        const ps = new Date(s.getFullYear(), s.getMonth() - 1, 1)
        const lastPrev = new Date(s.getFullYear(), s.getMonth(), 0).getDate()
        const wholeMonth = e.getDate() === new Date(e.getFullYear(), e.getMonth() + 1, 0).getDate()
        return [ymd(ps), ymd(new Date(ps.getFullYear(), ps.getMonth(), wholeMonth ? lastPrev : Math.min(e.getDate(), lastPrev)))]
    }
    const days = Math.round((e.getTime() - s.getTime()) / 864e5) + 1
    return [ymd(addDays(s, -days)), ymd(addDays(s, -1))]
}

interface PLRow {
    key: string
    label: string
    kind: 'line' | 'less' | 'subtotal' | 'total' | 'memo'
    cur: number
    prev: number | null
    higherIsBetter: boolean
    note?: string
    items?: PLBreakdownItem[]
}

// Revenue the order totals don't explain: partial payments, tax, refunds.
const otherCollections = (r: PL) => round2(r.revenue.net_revenue - (r.revenue.gross_sales - r.revenue.discounts))
const plCosts = (r: PL) =>
    (plIncludeCogs.value ? r.cogs.total : 0) + r.expenses.total + (r.payroll?.total ?? 0) + (r.payout_share?.total ?? 0)

const plRows = computed<PLRow[]>(() => {
    const r = plReport.value
    if (!r) return []
    const p = plPrev.value
    const rows: PLRow[] = [
        { key: 'gross', label: 'Gross sales', note: `${r.revenue.order_count} paid order${r.revenue.order_count !== 1 ? 's' : ''}`, kind: 'line', cur: r.revenue.gross_sales, prev: p?.revenue.gross_sales ?? null, higherIsBetter: true },
        { key: 'discounts', label: 'Discounts', kind: 'less', cur: r.revenue.discounts, prev: p?.revenue.discounts ?? null, higherIsBetter: false },
    ]
    if (Math.abs(otherCollections(r)) >= 0.01 || (p && Math.abs(otherCollections(p)) >= 0.01)) {
        rows.push({ key: 'collections', label: 'Other collections', note: 'Partial payments, tax and refunds', kind: 'line', cur: otherCollections(r), prev: p ? otherCollections(p) : null, higherIsBetter: true })
    }
    rows.push({ key: 'net_revenue', label: 'Net revenue', kind: 'subtotal', cur: r.revenue.net_revenue, prev: p?.revenue.net_revenue ?? null, higherIsBetter: true })
    if (plIncludeCogs.value) {
        rows.push({ key: 'cogs', label: 'Cost of goods sold', note: r.cogs.has_data ? undefined : 'No product costs set yet', kind: 'less', cur: r.cogs.total, prev: p?.cogs.total ?? null, higherIsBetter: false })
        rows.push({ key: 'gross_profit', label: 'Gross profit', note: `${r.gross_margin}% margin`, kind: 'subtotal', cur: r.gross_profit, prev: p?.gross_profit ?? null, higherIsBetter: true })
    }
    rows.push(
        { key: 'other_income', label: 'Other income', kind: 'line', cur: r.income_adjustments?.total ?? 0, prev: p ? (p.income_adjustments?.total ?? 0) : null, higherIsBetter: true, items: r.income_adjustments?.breakdown },
        { key: 'expenses', label: 'Operating expenses', note: plIncludeCogs.value ? undefined : 'Includes inventory purchases', kind: 'less', cur: r.expenses.total, prev: p?.expenses.total ?? null, higherIsBetter: false, items: r.expenses.breakdown },
        { key: 'payroll', label: 'Payroll', kind: 'less', cur: r.payroll?.total ?? 0, prev: p ? (p.payroll?.total ?? 0) : null, higherIsBetter: false, items: r.payroll?.breakdown },
        { key: 'payouts', label: 'Profit payouts', kind: 'less', cur: r.payout_share?.total ?? 0, prev: p ? (p.payout_share?.total ?? 0) : null, higherIsBetter: false, items: r.payout_share?.breakdown },
        { key: 'net_profit', label: r.net_profit >= 0 ? 'Net profit' : 'Net loss', note: `${r.net_margin}% margin`, kind: 'total', cur: r.net_profit, prev: p?.net_profit ?? null, higherIsBetter: true },
    )
    if ((r.inventory_purchases?.total ?? 0) > 0 || (p?.inventory_purchases?.total ?? 0) > 0) {
        rows.push({
            key: 'inventory', label: 'Memo: inventory purchases',
            note: r.inventory_purchases.included_in_expenses ? 'Already inside operating expenses' : 'Stock bought, not deducted',
            kind: 'memo', cur: r.inventory_purchases.total, prev: p ? (p.inventory_purchases?.total ?? 0) : null,
            higherIsBetter: false, items: r.inventory_purchases.breakdown,
        })
    }
    return rows
})

const plKpis = computed(() => {
    const r = plReport.value
    if (!r) return []
    const p = plPrev.value
    const kpis = [
        { label: 'Net revenue', value: r.revenue.net_revenue, prev: p?.revenue.net_revenue ?? 0, higherIsBetter: true, note: `${r.revenue.order_count} paid orders`, tone: '' },
    ]
    if (plIncludeCogs.value) {
        kpis.push({ label: 'Gross profit', value: r.gross_profit, prev: p?.gross_profit ?? 0, higherIsBetter: true, note: `${r.gross_margin}% margin`, tone: '' })
    }
    kpis.push(
        { label: 'Total costs', value: plCosts(r), prev: p ? plCosts(p) : 0, higherIsBetter: false, note: plIncludeCogs.value ? 'COGS, expenses, payroll, payouts' : 'Expenses, payroll, payouts', tone: '' },
        { label: r.net_profit >= 0 ? 'Net profit' : 'Net loss', value: r.net_profit, prev: p?.net_profit ?? 0, higherIsBetter: true, note: `${r.net_margin}% margin`, tone: r.net_profit >= 0 ? 'is-surplus' : 'is-deficit' },
    )
    return kpis
})

// Income split into costs and profit, in pesos per ₱100.
const plSpend = computed(() => {
    const r = plReport.value
    if (!r) return null
    const income = r.revenue.net_revenue + (r.income_adjustments?.total ?? 0)
    const costs = [
        { key: 'cogs', label: 'Cost of goods', value: plIncludeCogs.value ? r.cogs.total : 0 },
        { key: 'expenses', label: 'Expenses', value: r.expenses.total },
        { key: 'payroll', label: 'Payroll', value: r.payroll?.total ?? 0 },
        { key: 'payouts', label: 'Payouts', value: r.payout_share?.total ?? 0 },
    ]
    const totalCosts = costs.reduce((s, c) => s + c.value, 0)
    if (income <= 0 && totalCosts <= 0) return null
    const loss = totalCosts > income
    const base = loss ? totalCosts : income
    const segments = [...costs, ...(loss ? [] : [{ key: 'profit', label: 'Profit kept', value: income - totalCosts }])]
        .filter((s) => s.value > 0.004)
        .map((s) => ({ ...s, per100: (s.value / base) * 100 }))
    return { segments, loss }
})

const fmtSigned = (v: number) => (v < 0 ? '−' : '') + fmt(Math.abs(v))
const fmtLine = (row: PLRow, v: number) => (row.kind === 'less' && v > 0 ? '−' + fmt(v) : fmtSigned(v))
const amountTone = (row: PLRow) => (row.kind === 'total' ? (row.cur >= 0 ? 'is-in' : 'is-out') : '')
const deltaText = (cur: number, prev: number) => {
    const diff = round2(cur - prev)
    if (Math.abs(diff) < 0.005) return 'No change'
    const pct = prev !== 0 ? Math.round((diff / Math.abs(prev)) * 100) : null
    return `${diff > 0 ? '+' : '−'}${fmt(Math.abs(diff))}${pct !== null ? ` (${pct > 0 ? '+' : pct < 0 ? '−' : ''}${Math.abs(pct)}%)` : ''}`
}
const deltaTone = (diff: number, higherIsBetter: boolean) =>
    Math.abs(diff) < 0.005 ? 'is-flat' : (higherIsBetter ? diff > 0 : diff < 0) ? 'is-good' : 'is-bad'
const fmtRange = (start: string, end: string) => {
    const opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric', year: 'numeric' }
    const s = parseYmd(start).toLocaleDateString('en-PH', opts)
    return start === end ? s : `${s} – ${parseYmd(end).toLocaleDateString('en-PH', opts)}`
}

watch([plIncludeCogs, plCompare], () => {
    if (tab.value === 'pl') generateReport()
})

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

// ── Heatmap ───────────────────────────────────────────────────────────────────
interface HeatmapSlot { day: string; hour: number; orders: number }
interface HeatmapInsights {
    total_orders: number
    peak_slot:   HeatmapSlot
    peak_hour:   { hour: number; total_orders: number }
    peak_day:    { day: string;  total_orders: number }
    hour_totals: number[]
    day_totals:  Record<string, number>
}
interface HeatmapData {
    data:     HeatmapSlot[]
    matrix:   Record<string, number[]>
    insights: HeatmapInsights
}

const hmDateFrom  = ref(daysAgo(89))
const hmDateTo    = ref(manilaToday())
const hmData      = ref<HeatmapData | null>(null)
const hmLoading   = ref(false)
const hmTooltip   = ref<{ slot: HeatmapSlot; x: number; y: number } | null>(null)

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const HOURS = Array.from({ length: 24 }, (_, i) => i)

const hmMax = computed(() => {
    if (!hmData.value) return 1
    return Math.max(1, ...hmData.value.data.map(d => d.orders))
})

// Returns a Tailwind-compatible inline style for the cell background
function cellStyle(orders: number): string {
    if (orders === 0) return 'background:#efeadf'
    const ratio = orders / hmMax.value
    // Interpolate from #431407 (very dark) → #f97316 (orange-500) → #fef08a (yellow-200)
    if (ratio < 0.25)  return `background:rgba(249,115,22,${0.15 + ratio * 0.6})`
    if (ratio < 0.50)  return `background:rgba(249,115,22,${0.30 + ratio * 0.7})`
    if (ratio < 0.75)  return `background:rgba(234,88,12,${0.55 + ratio * 0.4})`
    return `background:rgba(220,38,38,${0.70 + ratio * 0.3})`
}

function cellText(orders: number): string {
    const ratio = orders / hmMax.value
    return ratio > 0.4 ? 'text-white' : orders > 0 ? 'text-orange-950' : 'text-stone-400'
}

function hmFmtHour(h: number): string {
    if (h === 0)  return '12a'
    if (h < 12)   return `${h}a`
    if (h === 12) return '12p'
    return `${h - 12}p`
}

function showTooltip(e: MouseEvent, slot: HeatmapSlot) {
    const rect = (e.target as HTMLElement).getBoundingClientRect()
    hmTooltip.value = { slot, x: rect.left + rect.width / 2, y: rect.top - 8 }
}

const loadHeatmap = async () => {
    hmLoading.value = true
    try {
        const res = await api.get('/api/v1/reports/heatmap', {
            params: { date_from: hmDateFrom.value, date_to: hmDateTo.value },
        })
        hmData.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load heatmap')
    } finally {
        hmLoading.value = false
    }
}

// ── Financial ─────────────────────────────────────────────────────────────────
const ftStartDate = ref(daysAgo(30))
const ftEndDate = ref(manilaToday())
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
    pending:   'bg-yellow-100 text-yellow-700',
    preparing: 'bg-blue-100 text-blue-700',
    ready:     'bg-purple-100 text-purple-700',
    completed: 'bg-green-100 text-green-700',
    cancelled: 'bg-red-100 text-red-700',
}[s] ?? 'bg-muted text-muted-foreground')

const payBadge = (s: string) => ({
    paid:     'bg-green-100 text-green-700',
    pending:  'bg-yellow-100 text-yellow-700',
    refunded: 'bg-purple-100 text-purple-700',
    voided:   'bg-red-100 text-red-700',
}[s] ?? 'bg-muted text-muted-foreground')

const invTypeBadge = (t: string) => ({
    stock_in:   'bg-green-100 text-green-700',
    stock_out:  'bg-red-100 text-red-700',
    adjustment: 'bg-blue-100 text-blue-700',
    waste:      'bg-orange-100 text-orange-700',
    usage:      'bg-yellow-100 text-yellow-700',
    purchase:   'bg-purple-100 text-purple-700',
}[t] ?? 'bg-muted text-muted-foreground')

const typeLabel = (t: string) => ({
    order: 'Order', payment: 'Payment', expense: 'Expense', income_adjustment: 'Income Adj.', payroll: 'Payroll',
}[t] ?? t)
const typeBadgeClass = (t: string) => ({
    order:             'bg-blue-100 text-blue-700',
    payment:           'bg-green-100 text-green-700',
    expense:           'bg-red-100 text-red-700',
    income_adjustment: 'bg-teal-100 text-teal-700',
    payroll:           'bg-purple-100 text-purple-700',
}[t] ?? 'bg-muted text-muted-foreground')
const isCredit = (t: string) => t === 'payment' || t === 'income_adjustment'

const orderTypeBadge = (t: string) => ({ dine_in: 'Dine-In', takeout: 'Takeout', delivery: 'Delivery' }[t] ?? t)

const frequencyLabel = (f: string) => ({
    one_time: 'One Time', daily: 'Daily', weekly: 'Weekly', bi_weekly: 'Bi-Weekly',
    monthly: 'Monthly', quarterly: 'Quarterly', semi_annual: 'Semi-Annual', annual: 'Annual',
}[f] ?? f)

const billStatusBadge = (s: string) => ({
    overdue:   'bg-red-100 text-red-700',
    due_today: 'bg-orange-100 text-orange-700',
    upcoming:  'bg-yellow-100 text-yellow-700',
    scheduled: 'bg-blue-100 text-blue-700',
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
    [...productSales.value].sort((a, b) => b.total_sales - a.total_sales)
)

const totalProductSales = computed(() =>
    productSales.value.reduce((s, p) => s + Number(p.total_sales), 0)
)

// ── Per-product daily chart ────────────────────────────────────────────────────
const expandedProducts  = ref<Record<number, boolean>>({})
const productDailyData  = ref<Record<number, { loading: boolean; points: { date: string; qty: number; sales: number }[] }>>({})

const fetchProductChart = async (productId: number) => {
    if (productDailyData.value[productId]?.points?.length !== undefined && !productDailyData.value[productId]?.loading) return
    productDailyData.value = { ...productDailyData.value, [productId]: { loading: true, points: [] } }
    try {
        const res = await api.get('/api/v1/reports/product-daily-sales', {
            params: { product_id: productId, start_date: prodDateFrom.value, end_date: prodDateTo.value },
        })
        productDailyData.value = { ...productDailyData.value, [productId]: { loading: false, points: res.data } }
        productLineData.value = { ...productLineData.value, [productId]: makeLineData(res.data) }
    } catch {
        productDailyData.value = { ...productDailyData.value, [productId]: { loading: false, points: [] } }
    }
}

const toggleProduct = (id: number) => {
    expandedProducts.value = { ...expandedProducts.value, [id]: !expandedProducts.value[id] }
    if (expandedProducts.value[id]) fetchProductChart(id)
}

const setProductRange = async (preset: '7d' | '30d' | '90d' | 'ytd') => {
    const today = new Date()
    const fmt = (d: Date) => toManilaDate(d)
    if (preset === '7d')       { const f = new Date(today); f.setDate(f.getDate() - 6);  prodDateFrom.value = fmt(f); prodDateTo.value = fmt(today) }
    else if (preset === '30d') { const f = new Date(today); f.setDate(f.getDate() - 29); prodDateFrom.value = fmt(f); prodDateTo.value = fmt(today) }
    else if (preset === '90d') { const f = new Date(today); f.setDate(f.getDate() - 89); prodDateFrom.value = fmt(f); prodDateTo.value = fmt(today) }
    else                       { prodDateFrom.value = today.getFullYear() + '-01-01';     prodDateTo.value = fmt(today) }
    productDailyData.value = {}
    expandedProducts.value = {}
    productLineData.value = {}
    await generateReport()
}

const makeLineData = (points: { date: string; qty: number; sales: number }[]) => {
    const W = 400, H = 80, padT = 10, padB = 10
    const vals = points.map(p => p.sales)
    const max = Math.max(...vals, 0.01)
    const n = points.length
    if (n === 0) return { path: '', area: '', coords: [] as { x: number; y: number }[], max }
    const coords = points.map((_, i) => ({
        x: n > 1 ? (i / (n - 1)) * W : W / 2,
        y: padT + (1 - vals[i] / max) * (H - padT - padB),
    }))
    if (n < 2) return { path: `M ${coords[0].x.toFixed(1)},${coords[0].y.toFixed(1)}`, area: '', coords, max }
    const segs = coords.map(c => `${c.x.toFixed(1)},${c.y.toFixed(1)}`).join(' L ')
    const path = `M ${segs}`
    const area = `${path} L ${W},${H - padB} L 0,${H - padB} Z`
    return { path, area, coords, max }
}

const productLineData = ref<Record<number, { path: string; area: string; coords: { x: number; y: number }[]; max: number }>>({})

const prodChartTooltip = ref<{
    productId: number
    idx: number
    point: { date: string; qty: number; sales: number }
    pct: number
} | null>(null)

const onProdChartHover = (e: MouseEvent, productId: number) => {
    const points = productDailyData.value[productId]?.points
    if (!points?.length) return
    const rect = (e.currentTarget as SVGElement).getBoundingClientRect()
    const relX = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width))
    const idx = Math.max(0, Math.min(points.length - 1, Math.round(relX * (points.length - 1))))
    prodChartTooltip.value = {
        productId, idx, point: points[idx],
        pct: points.length > 1 ? idx / (points.length - 1) : 0.5,
    }
}

watch([prodDateFrom, prodDateTo], () => {
    productDailyData.value = {}
    expandedProducts.value = {}
    productLineData.value = {}
})

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
            product_ids: ordProductIds.value.length ? ordProductIds.value.join(',') : undefined,
            sort_by: ordSortBy.value,
            sort_dir: ordSortDir.value,
        },
    })
    ordersData.value = (res.data.data ?? []).map((o: any) => ({
        ...o,
        total_amount: parseFloat(o.total_amount ?? 0),
    }))
    ordersMeta.value = res.data.meta ?? null
    ordersSummary.value = res.data.summary ?? null
}

const editOrder = (order: OrderRow) =>
    router.visit(`/orders/${order.id}?back=${encodeURIComponent(buildOrdBackUrl())}`)

const deleteOrder = async (order: OrderRow) => {
    if (!confirm(`Delete Order #${order.id}?\nIts payments and financial transactions will also be deleted.\nThis cannot be undone.`)) return
    ordDeleting.value = order.id
    try {
        await api.delete(`/api/v1/orders/${order.id}`)
        toast.success(`Order #${order.id} deleted.`)
        await loadOrders(ordPage.value)
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to delete order.')
    } finally {
        ordDeleting.value = null
    }
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
    const fetchPL = (start: string, end: string) =>
        api.get('/api/v1/reports/profit-loss', {
            params: { start_date: start, end_date: end, include_cogs: plIncludeCogs.value ? 1 : 0 },
        })
    const prev = plCompare.value ? plPrevRange(plStartDate.value, plEndDate.value) : null
    const [cur, before] = await Promise.all([
        fetchPL(plStartDate.value, plEndDate.value),
        prev ? fetchPL(prev[0], prev[1]) : Promise.resolve(null),
    ])
    plReport.value = cur.data
    plPrev.value = before?.data ?? null
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
    // Self-contained tabs handle their own loading
    if (tab.value === 'analytics' || tab.value === 'serving') return
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
                loadFtBreakdown(selectedDate.value, selectedDate.value),
            ])
            dailyReport.value = salesRes.data
        } else if (tab.value === 'monthly') {
            const monthStart = new Date(selectedYear.value, selectedMonth.value - 1, 1)
            const monthEnd   = new Date(selectedYear.value, selectedMonth.value, 0)
            const [res] = await Promise.all([
                api.get('/api/v1/reports/monthly-sales', { params: { year: selectedYear.value, month: selectedMonth.value } }),
                loadMonthlyChartData(),
                loadFtBreakdown(toManilaDate(monthStart), toManilaDate(monthEnd)),
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
        } else if (tab.value === 'heatmap') {
            await loadHeatmap()
        }
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to load report')
    } finally {
        loading.value = false
    }
}

const deleteEntry = async (tx: FtTransaction) => {
    const orderNote = tx.order_id && ['order', 'payment'].includes(tx.type)
        ? `\n\nThis will also delete Order #${tx.order_id} and all of its payments and transactions.`
        : ''

    if (!confirm(`Delete "${tx.description}"? This cannot be undone.${orderNote}`)) return
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
        transacted_at: tx.transacted_at ? tx.transacted_at.replace(' ', 'T').substring(0, 16) : '',
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

    if (tab.value === 'pl' && plReport.value) {
        const signed = (row: PLRow, v: number) => String(row.kind === 'less' ? -v : v)
        filename = `profit-and-loss-${plStartDate.value}-to-${plEndDate.value}`
        rows = [
            ['Line', 'This period', ...(plPrev.value ? ['Previous', 'Change'] : [])],
            ...plRows.value.map((row) => [
                row.label,
                signed(row, row.cur),
                ...(plPrev.value
                    ? [row.prev == null ? '' : signed(row, row.prev), row.prev == null ? '' : signed(row, round2(row.cur - row.prev))]
                    : []),
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

const canExport = computed(() => ['orders', 'inventory', 'products', 'financial', 'pl'].includes(tab.value))

// Keep the open report in the URL so refresh and shared links land on it.
const switchTab = (t: Tab) => {
    tab.value = t
    const url = new URL(window.location.href)
    url.search = `?tab=${t}`
    window.history.replaceState(window.history.state, '', url)
    generateReport()
}

onMounted(async () => {
    // Restore filter state when coming back from order detail
    const urlParams = new URLSearchParams(window.location.search)
    const restoredTab = urlParams.get('tab') as Tab | null
    if (restoredTab === 'orders') {
        tab.value = 'orders'
        if (urlParams.get('df')) ordDateFrom.value = urlParams.get('df')!
        if (urlParams.get('dt')) ordDateTo.value = urlParams.get('dt')!
        if (urlParams.get('st')) ordStatus.value = urlParams.get('st')!
        if (urlParams.get('py')) ordPayment.value = urlParams.get('py')!
        if (urlParams.get('q')) ordSearch.value = urlParams.get('q')!
        if (urlParams.get('pids')) ordProductIds.value = urlParams.get('pids')!.split(',').map(Number).filter(Boolean)
        if (urlParams.get('pg')) ordPage.value = parseInt(urlParams.get('pg')!)
        if (urlParams.get('sb')) ordSortBy.value = urlParams.get('sb')!
        if (urlParams.get('sd')) ordSortDir.value = urlParams.get('sd')! as 'asc' | 'desc'
    } else if (restoredTab && (restoredTab === 'financial' || allTabs.some((t) => t.key === restoredTab))) {
        tab.value = restoredTab
    }

    // Pre-load ingredient list for the inventory filter dropdown
    try {
        const res = await api.get('/api/v1/inventory')
        ingredients.value = (res.data.data ?? res.data).map((i: any) => ({ id: i.id, name: i.name, unit: i.unit }))
    } catch { /* non-critical */ }

    // Pre-load product list for the orders product filter
    try {
        const res = await api.get('/api/v1/products', { params: { per_page: 500 } })
        allProducts.value = (res.data.data ?? res.data).map((p: any) => ({ id: p.id, name: p.name }))
    } catch { /* non-critical */ }

    if (restoredTab === 'orders') {
        loading.value = true
        try { await loadOrders(ordPage.value) } finally { loading.value = false }
    } else {
        await generateReport()
    }
})
</script>

<template>
    <Head title="Reports" />

    <div class="rpt-theme rpt-page space-y-5">
        <header class="rpt-heading">
            <div>
                <p class="rpt-eyebrow"><span aria-hidden="true" />BYPASS GRILL / REPORTS</p>
                <h1>THE NUMBERS <span>BEHIND THE GRILL.</span></h1>
                <p class="rpt-intro">{{ activeTabInfo.hint }}</p>
            </div>
            <div class="rpt-heading-actions">
                <button class="rpt-ghost-btn" :disabled="!canExport" @click="exportCSV"><Download :size="15" aria-hidden="true" />Export CSV</button>
                <button class="rpt-ghost-btn" @click="printReport"><Printer :size="15" aria-hidden="true" />Print</button>
            </div>
        </header>

        <!-- Grouped report navigation -->
        <nav class="rpt-nav" aria-label="Reports">
            <div v-for="group in tabGroups" :key="group.label" class="rpt-nav-group">
                <p>{{ group.label }}</p>
                <div role="tablist" :aria-label="group.label">
                    <button
                        v-for="t in group.tabs"
                        :key="t.key"
                        role="tab"
                        :aria-selected="tab === t.key"
                        @click="switchTab(t.key)"
                    >
                        <component :is="t.icon" :size="15" aria-hidden="true" />{{ t.label }}
                    </button>
                </div>
            </div>
        </nav>

        <!-- Self-contained tabs -->
        <AnalyticsTab v-if="tab === 'analytics'" />
        <ServingTimeTab v-if="tab === 'serving'" />

        <!-- Filters bar -->
        <div v-if="tab !== 'analytics' && tab !== 'serving'" class="rpt-filters">
            <div v-if="tab === 'pl'" class="rpt-presets" role="group" aria-label="Quick periods">
                <button v-for="p in plPresets" :key="p.key" :aria-pressed="plPreset === p.key" @click="setPlPreset(p.key)">{{ p.label }}</button>
            </div>
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
                    <div class="relative">
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Products</label>
                        <button @click="ordProductDropdown = !ordProductDropdown"
                            class="rounded-lg border bg-background px-3 py-2 text-sm flex items-center gap-2 min-w-[150px] h-[38px]">
                            <Package class="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                            <span v-if="ordProductIds.length === 0" class="text-muted-foreground">All Products</span>
                            <span v-else class="font-medium">{{ ordProductIds.length }} selected</span>
                            <ChevronDown class="h-3.5 w-3.5 ml-auto text-muted-foreground shrink-0" />
                        </button>
                        <div v-if="ordProductDropdown" class="fixed inset-0 z-40" @click="ordProductDropdown = false"></div>
                        <div v-if="ordProductDropdown" class="absolute z-50 top-full mt-1 left-0 w-64 rounded-lg border bg-popover shadow-xl flex flex-col max-h-72 overflow-hidden">
                            <div class="flex items-center justify-between px-3 py-2 border-b shrink-0">
                                <span class="text-xs font-semibold text-muted-foreground">Filter by product</span>
                                <button @click="ordProductIds = []" class="text-xs text-muted-foreground hover:text-foreground transition">Clear all</button>
                            </div>
                            <div class="overflow-y-auto flex-1">
                                <label v-for="p in allProducts" :key="p.id"
                                    class="flex items-center gap-2.5 px-3 py-2 text-sm hover:bg-muted cursor-pointer">
                                    <input type="checkbox" :value="p.id" v-model="ordProductIds" class="rounded border-border shrink-0" />
                                    <span class="truncate">{{ p.name }}</span>
                                </label>
                                <div v-if="allProducts.length === 0" class="px-3 py-4 text-xs text-muted-foreground text-center">No products found</div>
                            </div>
                        </div>
                    </div>
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
                    <div class="flex gap-1 w-full sm:contents">
                        <button v-for="r in [['7d','7D'],['30d','30D'],['90d','90D'],['ytd','YTD']] as const"
                            :key="r[0]" @click="setProductRange(r[0])"
                            class="flex-1 sm:flex-none rounded-lg border px-3 py-2 text-xs font-semibold transition hover:bg-muted">
                            {{ r[1] }}
                        </button>
                    </div>
                    <div class="flex gap-2 w-full sm:contents">
                        <div class="flex-1 sm:flex-none"><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                            <input v-model="prodDateFrom" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                        <div class="flex-1 sm:flex-none"><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                            <input v-model="prodDateTo" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    </div>
                </template>

                <!-- P&L date range -->
                <template v-if="tab === 'pl'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1" for="pl-from">From</label>
                        <input id="pl-from" v-model="plStartDate" type="date" :max="plEndDate" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" @change="plPreset = 'custom'" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1" for="pl-to">To</label>
                        <input id="pl-to" v-model="plEndDate" type="date" :min="plStartDate" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" @change="plPreset = 'custom'" /></div>
                    <label class="rpt-switch">
                        <input v-model="plIncludeCogs" type="checkbox" />
                        <span class="rpt-switch-track" aria-hidden="true"><span /></span>
                        <span>Include COGS<small>{{ plIncludeCogs ? 'Stock bought is an asset; its cost counts when sold' : 'Stock bought counts as an expense when bought' }}</small></span>
                    </label>
                    <label class="rpt-switch">
                        <input v-model="plCompare" type="checkbox" />
                        <span class="rpt-switch-track" aria-hidden="true"><span /></span>
                        <span>Compare<small>{{ plCompare ? 'With the period before' : 'Off' }}</small></span>
                    </label>
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

                <!-- Heatmap date range -->
                <template v-if="tab === 'heatmap'">
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                        <input v-model="hmDateFrom" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                        <input v-model="hmDateTo" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                </template>

                <!-- Financial date range -->
                <template v-if="tab === 'financial'">
                    <div class="flex gap-2 w-full sm:contents">
                        <div class="flex-1 sm:flex-none"><label class="text-xs font-medium text-muted-foreground block mb-1">From</label>
                            <input v-model="ftStartDate" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                        <div class="flex-1 sm:flex-none"><label class="text-xs font-medium text-muted-foreground block mb-1">To</label>
                            <input v-model="ftEndDate" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" /></div>
                    </div>
                    <div class="flex-1 sm:flex-none"><label class="text-xs font-medium text-muted-foreground block mb-1">Type</label>
                        <select v-model="ftTypeFilter" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                            <option value="">All Types</option>
                            <option value="order">Orders</option>
                            <option value="payment">Payments</option>
                            <option value="expense">Expenses</option>
                            <option value="income_adjustment">Income Adjustments</option>
                            <option value="payroll">Payroll</option>
                        </select></div>
                </template>

                <button @click="generateReport" :disabled="loading" class="rpt-primary-btn">
                    <RefreshCw :size="15" :class="{ 'animate-spin': loading }" aria-hidden="true" />
                    {{ loading ? 'Loading…' : 'Update report' }}
                </button>
            </div>
        </div>

        <!-- ── Orders ─────────────────────────────────────────────────────────── -->
        <template v-if="tab === 'orders'">
            <div v-if="ordersSummary" class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><ClipboardList class="h-3 w-3" /> Total Orders</p>
                    <p class="text-3xl font-black">{{ ordersSummary.total_count }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Paid Orders</p>
                    <p class="text-3xl font-black text-green-600">{{ ordersSummary.paid_count }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1">Unpaid Orders</p>
                    <p class="text-3xl font-black text-yellow-600">{{ ordersSummary.unpaid_count }}</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Total Revenue</p>
                    <p class="text-xl font-black text-green-600">{{ fmt(ordersSummary.paid_revenue) }}</p>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between">
                    <h2 class="font-bold text-sm flex items-center gap-2"><ShoppingBag class="h-4 w-4" /> Orders</h2>
                    <span v-if="ordersMeta" class="text-xs text-muted-foreground">
                        Page {{ ordersMeta.current_page }} of {{ ordersMeta.last_page }} &nbsp;·&nbsp; {{ ordersMeta.total }} total
                    </span>
                </div>

                <!-- Mobile card list -->
                <div class="md:hidden divide-y">
                    <div v-for="order in ordersData" :key="order.id"
                        @click="editOrder(order)"
                        class="px-4 py-3 hover:bg-muted/20 transition-colors cursor-pointer">
                        <!-- Row 1: Order # + total + actions -->
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span class="font-bold text-primary text-base">#{{ order.id }}</span>
                                <span v-if="order.queue_number" class="ml-2 text-xs text-muted-foreground">Q{{ order.queue_number }}</span>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <span class="font-bold text-sm">{{ fmt(order.total_amount) }}</span>
                                <button @click.stop="editOrder(order)"
                                    class="text-muted-foreground hover:text-primary transition-colors p-1"
                                    title="Edit order">
                                    <Pencil class="h-4 w-4" />
                                </button>
                                <button @click.stop="deleteOrder(order)" :disabled="ordDeleting === order.id"
                                    class="text-red-500 hover:text-red-700 disabled:opacity-40 transition-colors p-1"
                                    title="Delete order">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                        <!-- Row 2: date + type + table + customer -->
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span>{{ fmtDatetime(order.created_at) }}</span>
                            <span class="rounded-full bg-muted px-2 py-0.5 font-medium">{{ orderTypeBadge(order.order_type) }}</span>
                            <span v-if="order.table_number">Table {{ order.table_number }}</span>
                            <span v-if="order.customer_name" class="font-medium text-foreground">{{ order.customer_name }}</span>
                            <span>{{ itemCount(order.items) }} item{{ itemCount(order.items) !== 1 ? 's' : '' }}</span>
                        </div>
                        <!-- Row 3: status + payment badges -->
                        <div class="mt-1.5 flex items-center gap-2 flex-wrap">
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', statusBadge(order.status)]">{{ order.status }}</span>
                            <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', payBadge(order.payment_status)]">{{ order.payment_status }}</span>
                            <span v-if="order.payments?.[0]?.method" class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{{ order.payments[0].method }}</span>
                            <span v-if="order.notes" class="text-xs text-muted-foreground truncate max-w-[180px]">{{ order.notes }}</span>
                        </div>
                    </div>
                    <div v-if="ordersData.length === 0 && !loading" class="px-4 py-10 text-center text-muted-foreground text-sm">
                        No orders found. Adjust filters and click Generate.
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left">Order</th>
                                <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground"
                                    @click="sortOrders('created_at')">
                                    <span class="inline-flex items-center gap-1">Date & Time
                                        <ArrowUp v-if="ordSortBy === 'created_at' && ordSortDir === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else-if="ordSortBy === 'created_at' && ordSortDir === 'desc'" class="h-3 w-3" />
                                        <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left">Type</th>
                                <th class="px-4 py-3 text-left">Table</th>
                                <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground"
                                    @click="sortOrders('customer_name')">
                                    <span class="inline-flex items-center gap-1">Customer
                                        <ArrowUp v-if="ordSortBy === 'customer_name' && ordSortDir === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else-if="ordSortBy === 'customer_name' && ordSortDir === 'desc'" class="h-3 w-3" />
                                        <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-center">Items</th>
                                <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground"
                                    @click="sortOrders('status')">
                                    <span class="inline-flex items-center gap-1">Status
                                        <ArrowUp v-if="ordSortBy === 'status' && ordSortDir === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else-if="ordSortBy === 'status' && ordSortDir === 'desc'" class="h-3 w-3" />
                                        <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left cursor-pointer select-none hover:text-foreground"
                                    @click="sortOrders('payment_status')">
                                    <span class="inline-flex items-center gap-1">Payment
                                        <ArrowUp v-if="ordSortBy === 'payment_status' && ordSortDir === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else-if="ordSortBy === 'payment_status' && ordSortDir === 'desc'" class="h-3 w-3" />
                                        <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left">Tender</th>
                                <th class="px-4 py-3 text-right cursor-pointer select-none hover:text-foreground"
                                    @click="sortOrders('total_amount')">
                                    <span class="inline-flex items-center justify-end gap-1">Total
                                        <ArrowUp v-if="ordSortBy === 'total_amount' && ordSortDir === 'asc'" class="h-3 w-3" />
                                        <ArrowDown v-else-if="ordSortBy === 'total_amount' && ordSortDir === 'desc'" class="h-3 w-3" />
                                        <ChevronsUpDown v-else class="h-3 w-3 opacity-40" />
                                    </span>
                                </th>
                                <th class="px-4 py-3 text-left">Notes</th>
                                <th class="px-4 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="order in ordersData" :key="order.id"
                                @click="editOrder(order)"
                                class="hover:bg-muted/30 cursor-pointer transition-colors">
                                <td class="px-4 py-3">
                                    <p class="font-bold text-primary">#{{ order.id }}</p>
                                    <p v-if="order.queue_number" class="text-xs text-muted-foreground">Q{{ order.queue_number }}</p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-muted-foreground text-xs">{{ fmtDatetime(order.created_at) }}</td>
                                <td class="px-4 py-3"><span class="rounded-full bg-muted px-2 py-0.5 text-xs font-medium">{{ orderTypeBadge(order.order_type) }}</span></td>
                                <td class="px-4 py-3 text-muted-foreground">{{ order.table_number ?? '—' }}</td>
                                <td class="px-4 py-3 text-muted-foreground">{{ order.customer_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-center font-medium">{{ itemCount(order.items) }}</td>
                                <td class="px-4 py-3"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', statusBadge(order.status)]">{{ order.status }}</span></td>
                                <td class="px-4 py-3"><span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', payBadge(order.payment_status)]">{{ order.payment_status }}</span></td>
                                <td class="px-4 py-3 text-xs text-muted-foreground">{{ order.payments?.[0]?.method ?? '—' }}</td>
                                <td class="px-4 py-3 text-right font-bold">{{ fmt(order.total_amount) }}</td>
                                <td class="px-4 py-3 text-xs text-muted-foreground max-w-[140px] truncate">{{ order.notes ?? '—' }}</td>
                                <td class="px-4 py-3 text-center" @click.stop>
                                    <div class="flex items-center justify-center gap-2">
                                        <button @click="editOrder(order)"
                                            class="text-muted-foreground hover:text-primary transition-colors"
                                            title="Edit order">
                                            <Pencil class="h-4 w-4" />
                                        </button>
                                        <button @click="deleteOrder(order)" :disabled="ordDeleting === order.id"
                                            class="text-red-500 hover:text-red-700 disabled:opacity-40 transition-colors"
                                            title="Delete order">
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="ordersData.length === 0 && !loading">
                                <td colspan="10" class="px-4 py-10 text-center text-muted-foreground">No orders found. Adjust filters and click Generate.</td>
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

        <!-- ── Heatmap (Peak Hours) ───────────────────────────────────────── -->
        <template v-if="tab === 'heatmap'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="font-bold text-sm flex items-center gap-2">🔥 Peak Hours</h2>
                    <div class="text-xs text-muted-foreground">Total orders: {{ hmData?.insights?.total_orders ?? 0 }}</div>
                </div>

                <div v-if="hmLoading" class="py-10 text-center text-muted-foreground">Loading heatmap…</div>

                <div v-else-if="hmData" class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <div class="col-span-2">
                        <div class="overflow-x-auto">
                            <div class="inline-block min-w-full">
                                <div class="grid" :style="{ gridTemplateColumns: '120px repeat(24, 40px)' }">
                                    <!-- header: hour labels -->
                                    <div class="px-2 py-2 text-xs text-muted-foreground">Day / Hour</div>
                                    <div v-for="h in 24" :key="h" class="px-1 py-1 text-[10px] text-center text-muted-foreground">{{ hmFmtHour(h - 1) }}</div>

                                    <!-- rows: days -->
                                    <div v-for="day in DAYS" :key="day" class="contents">
                                        <div class="px-2 py-1 text-sm font-semibold text-muted-foreground">{{ day }}</div>
                                        <div v-for="h in 24" :key="day + '-' + h" class="p-1">
                                            <div
                                                class="h-8 w-10 rounded-sm flex items-center justify-center text-xs font-semibold cursor-default"
                                                :style="cellStyle(hmData!.data.find(d => d.day === day && d.hour === (h - 1))?.orders ?? 0)"
                                                :class="cellText(hmData!.data.find(d => d.day === day && d.hour === (h - 1))?.orders ?? 0)
                                                    .split(' ')
                                                    .join(' ')
                                                "
                                                @mouseenter="(e) => showTooltip(e, { day, hour: h - 1, orders: hmData!.data.find(d => d.day === day && d.hour === (h - 1))?.orders ?? 0 })"
                                                @mouseleave="() => hmTooltip = null"
                                            >
                                                {{ hmData!.data.find(d => d.day === day && d.hour === (h - 1))?.orders ?? 0 }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1 space-y-3">
                        <div class="rounded-xl border p-3 bg-muted/20">
                            <p class="text-xs text-muted-foreground font-semibold">Peak Slot</p>
                            <div class="mt-2">
                                <p class="font-bold text-lg">{{ hmData.insights.peak_slot.day }} · {{ hmFmtHour(hmData.insights.peak_slot.hour) }}</p>
                                <p class="text-sm text-muted-foreground">Orders: <strong>{{ hmData.insights.peak_slot.orders }}</strong></p>
                            </div>
                        </div>

                        <div class="rounded-xl border p-3 bg-card">
                            <p class="text-xs text-muted-foreground font-semibold">Totals</p>
                            <div class="mt-2 text-sm">
                                <p>Total orders: <strong>{{ hmData.insights.total_orders }}</strong></p>
                                <p class="mt-1">Peak day: <strong>{{ hmData.insights.peak_day.day }}</strong> ({{ hmData.insights.peak_day.total_orders }})</p>
                                <p class="mt-1">Peak hour: <strong>{{ hmFmtHour(hmData.insights.peak_hour.hour) }}</strong> ({{ hmData.insights.peak_hour.total_orders }})</p>
                            </div>
                        </div>

                        <div class="rounded-xl border p-3 bg-card">
                            <p class="text-xs text-muted-foreground font-semibold">Legend</p>
                            <div class="mt-2 flex flex-col gap-2">
                                <div class="flex items-center gap-2"><div class="h-3 w-6 rounded-sm bg-orange-500"></div><span class="text-xs text-muted-foreground">High</span></div>
                                <div class="flex items-center gap-2"><div class="h-3 w-6 rounded-sm bg-amber-400"></div><span class="text-xs text-muted-foreground">Medium</span></div>
                                <div class="flex items-center gap-2"><div class="h-3 w-6 rounded-sm" style="background:#efeadf"></div><span class="text-xs text-muted-foreground">None</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-else class="py-10 text-center text-muted-foreground">No heatmap data for the selected range.</div>
            </div>

            <!-- Tooltip -->
            <div v-if="hmTooltip" :style="{ position: 'fixed', left: hmTooltip.x + 'px', top: (hmTooltip.y - 48) + 'px', transform: 'translateX(-50%)' }" class="pointer-events-none z-50">
                <div class="rounded-md bg-black text-white text-xs px-3 py-1 shadow-lg">{{ hmTooltip.slot.day }} {{ hmFmtHour(hmTooltip.slot.hour) }} — {{ hmTooltip.slot.orders }} orders</div>
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
                <!-- Card list (all screen sizes) -->
                <div class="divide-y">
                    <div v-for="tx in invTransactions" :key="tx.id"
                        @click="openAdjust(tx)"
                        class="flex items-center gap-3 px-4 py-3 hover:bg-muted/30 cursor-pointer transition-colors">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm truncate">{{ tx.ingredient?.name ?? '—' }}</p>
                            <div class="mt-1 flex items-center gap-2 flex-wrap">
                                <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold capitalize', invTypeBadge(tx.type)]">
                                    {{ tx.type.replace('_', ' ') }}
                                </span>
                                <span class="text-xs text-muted-foreground">{{ tx.ingredient?.unit ?? '' }}</span>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold text-lg tabular-nums leading-none">{{ tx.new_quantity }}</p>
                            <p class="text-xs text-muted-foreground mt-0.5">{{ tx.ingredient?.unit }}</p>
                        </div>
                    </div>
                    <div v-if="invTransactions.length === 0 && !loading" class="px-4 py-10 text-center text-muted-foreground text-sm">
                        No transactions found. Adjust filters and click Generate.
                    </div>
                </div>

                <!-- Adjust stock modal -->
                <Teleport to="body">
                    <div v-if="adjustingTx"
                        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center sm:bg-black/60"
                        @click.self="adjustingTx = null">
                        <div class="w-full sm:max-w-md rounded-t-2xl sm:rounded-xl bg-card border shadow-xl flex flex-col">
                            <div class="flex items-center justify-between p-4 border-b">
                                <div>
                                    <h3 class="font-bold text-base">{{ adjustingTx.ingredient?.name }}</h3>
                                    <p class="text-xs text-muted-foreground mt-0.5">
                                        Current stock: <strong>{{ adjustingTx.new_quantity }} {{ adjustingTx.ingredient?.unit }}</strong>
                                    </p>
                                </div>
                                <button @click="adjustingTx = null" class="rounded-full p-1.5 text-muted-foreground hover:bg-muted transition">
                                    <X class="h-4 w-4" />
                                </button>
                            </div>
                            <div class="p-4 space-y-3">
                                <div>
                                    <label class="text-xs font-medium text-muted-foreground block mb-1">Type</label>
                                    <select v-model="adjType" class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                                        <option value="stock_in">Stock In (Add)</option>
                                        <option value="stock_out">Stock Out (Remove)</option>
                                        <option value="adjustment">Manual Adjustment (Set to)</option>
                                        <option value="waste">Waste</option>
                                        <option value="purchase">Purchase</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-muted-foreground block mb-1">
                                        {{ adjType === 'adjustment' ? 'New Quantity' : 'Quantity' }}
                                    </label>
                                    <input v-model.number="adjQty" type="number" min="0" step="0.01" placeholder="0"
                                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                                </div>
                                <div>
                                    <label class="text-xs font-medium text-muted-foreground block mb-1">Notes (optional)</label>
                                    <input v-model="adjNotes" type="text" placeholder="Reason for adjustment…"
                                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary" />
                                </div>
                            </div>
                            <div class="px-4 pb-6 sm:pb-4">
                                <button @click="saveAdjust" :disabled="adjSaving || adjQty <= 0"
                                    class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition">
                                    {{ adjSaving ? 'Saving…' : 'Save Adjustment' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </Teleport>
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
                <div :class="['rounded-xl border p-4 shadow-sm', ftSummary.net >= 0 ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200']">
                    <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><DollarSign class="h-3 w-3" /> Net Cash</p>
                    <p class="text-2xl font-black" :class="ftSummary.net >= 0 ? 'text-green-700' : 'text-red-600'">{{ fmt(ftSummary.net) }}</p>
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
                                ? 'border-red-500 bg-red-50 text-red-700'
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
                                ? 'border-teal-500 bg-teal-50 text-teal-700'
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
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Date &amp; Time (optional)</label>
                        <input v-model="entryForm.transacted_at" type="datetime-local"
                            min="2000-01-01T00:00" max="2099-12-31T23:59"
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
                        <label class="text-xs font-medium text-muted-foreground block mb-1">Date &amp; Time</label>
                        <input v-model="ftEditForm.transacted_at" type="datetime-local"
                            min="2000-01-01T00:00" max="2099-12-31T23:59"
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

                <!-- Mobile card list -->
                <div class="md:hidden divide-y">
                    <div v-for="tx in ftTransactions" :key="tx.id" class="px-4 py-3 hover:bg-muted/20 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                    <span :class="['rounded-full px-2 py-0.5 text-xs font-semibold', typeBadgeClass(tx.type)]">{{ typeLabel(tx.type) }}</span>
                                    <span class="text-xs text-muted-foreground tabular-nums">{{ fmtDatetime(tx.transacted_at) }}</span>
                                </div>
                                <p class="text-sm font-medium leading-snug">{{ tx.description }}</p>
                                <div class="flex items-center gap-2 mt-1 text-xs text-muted-foreground flex-wrap">
                                    <span v-if="tx.tender?.name">{{ tx.tender.name }}</span>
                                    <span v-if="tx.user?.name" class="opacity-60">{{ tx.user.name }}</span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0">
                                <span class="font-bold tabular-nums text-sm" :class="isCredit(tx.type) ? 'text-green-600' : 'text-red-600'">
                                    {{ isCredit(tx.type) ? '+' : '-' }}{{ fmt(tx.amount) }}
                                </span>
                                <span class="text-xs tabular-nums" :class="(tx.financial_balance ?? 0) >= 0 ? 'text-muted-foreground' : 'text-red-600'">
                                    {{ fmt(tx.financial_balance ?? 0) }}
                                </span>
                                <div class="flex items-center gap-1 mt-1">
                                    <button v-if="tx.type !== 'order'" @click="openEditFt(tx)"
                                        class="rounded p-1 text-muted-foreground hover:text-blue-600 hover:bg-blue-50 transition"
                                        title="Edit entry">
                                        <Pencil class="h-3.5 w-3.5" />
                                    </button>
                                    <button v-if="tx.type === 'expense' || tx.type === 'income_adjustment'"
                                        @click="deleteEntry(tx)" :disabled="ftDeleting === tx.id"
                                        class="rounded p-1 text-muted-foreground hover:text-red-600 hover:bg-red-50 disabled:opacity-40 transition"
                                        title="Delete entry">
                                        <Trash2 class="h-3.5 w-3.5" />
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
                                        <button v-if="tx.type !== 'order'" @click="openEditFt(tx)"
                                            class="rounded p-1 text-muted-foreground hover:text-blue-600 hover:bg-blue-50 transition"
                                            title="Edit entry">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button v-if="tx.type === 'expense' || tx.type === 'income_adjustment'"
                                            @click="deleteEntry(tx)" :disabled="ftDeleting === tx.id"
                                            class="rounded p-1 text-muted-foreground hover:text-red-600 hover:bg-red-50 disabled:opacity-40 transition"
                                            title="Delete entry">
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
            <div v-if="plReport" class="rpt-stack">
                <!-- Headline figures with change vs the comparison period -->
                <section class="rpt-kpis" aria-label="Profit and loss headline figures">
                    <article v-for="k in plKpis" :key="k.label" class="rpt-kpi" :class="k.tone">
                        <p>{{ k.label }}</p>
                        <strong>{{ fmtSigned(k.value) }}</strong>
                        <span v-if="k.note">{{ k.note }}</span>
                        <span v-if="plPrev" class="rpt-delta" :class="deltaTone(k.value - k.prev, k.higherIsBetter)">
                            {{ deltaText(k.value, k.prev) }}
                        </span>
                    </article>
                </section>

                <!-- Where each peso went -->
                <section v-if="plSpend" class="rpt-panel">
                    <div class="rpt-panel-head">
                        <div>
                            <p class="rpt-kicker">WHERE EACH ₱100 WENT</p>
                            <h2>{{ plSpend.loss ? 'Costs ran past income' : 'Income, split by where it went' }}</h2>
                        </div>
                        <small>Hover or tap a segment</small>
                    </div>
                    <div class="rpt-spend-bar" role="list">
                        <button
                            v-for="seg in plSpend.segments"
                            :key="seg.key"
                            role="listitem"
                            class="rpt-spend-seg"
                            :class="[`seg-${seg.key}`, { 'is-active': plSpendActive === seg.key }]"
                            :style="{ flexGrow: seg.value }"
                            :aria-label="`${seg.label}: ${fmt(seg.value)}, ${seg.per100.toFixed(0)} pesos of every 100`"
                            @mouseenter="plSpendActive = seg.key"
                            @focus="plSpendActive = seg.key"
                            @click="plSpendActive = seg.key"
                        >
                            <span v-if="seg.per100 >= 8">{{ seg.per100.toFixed(0) }}</span>
                        </button>
                    </div>
                    <div class="rpt-spend-legend">
                        <button
                            v-for="seg in plSpend.segments"
                            :key="seg.key"
                            :class="{ 'is-active': plSpendActive === seg.key }"
                            @mouseenter="plSpendActive = seg.key"
                            @click="plSpendActive = seg.key"
                        >
                            <i :class="`seg-${seg.key}`" aria-hidden="true" />{{ seg.label }}
                            <strong>₱{{ seg.per100.toFixed(0) }}</strong>
                        </button>
                    </div>
                    <p v-if="plSpendActive && plSpend.segments.find((s) => s.key === plSpendActive)" class="rpt-spend-note" aria-live="polite">
                        <template v-for="seg in plSpend.segments.filter((s) => s.key === plSpendActive)" :key="seg.key">
                            <strong>{{ seg.label }}</strong> took {{ fmt(seg.value) }}: ₱{{ seg.per100.toFixed(2) }} of every ₱100 of {{ plSpend.loss ? 'total costs' : 'income' }}.
                        </template>
                    </p>
                </section>

                <!-- The statement -->
                <section class="rpt-panel rpt-statement">
                    <div class="rpt-panel-head">
                        <div>
                            <p class="rpt-kicker">PROFIT &amp; LOSS STATEMENT</p>
                            <h2>{{ fmtRange(plReport.period.start, plReport.period.end) }}</h2>
                        </div>
                        <small v-if="plPrev">Compared with {{ fmtRange(plPrev.period.start, plPrev.period.end) }}</small>
                    </div>
                    <div class="rpt-table-scroll" tabindex="0" role="region" aria-label="Profit and loss statement">
                        <table class="rpt-pl-table">
                            <thead>
                                <tr>
                                    <th scope="col">Line</th>
                                    <th scope="col" class="num">This period</th>
                                    <th v-if="plPrev" scope="col" class="num">Previous</th>
                                    <th v-if="plPrev" scope="col" class="num">Change</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="row in plRows" :key="row.key">
                                    <tr :class="[`row-${row.kind}`, { 'is-open': plOpen[row.key] }]">
                                        <th scope="row">
                                            <button v-if="row.items?.length" class="rpt-line-toggle" :aria-expanded="!!plOpen[row.key]" @click="plOpen[row.key] = !plOpen[row.key]">
                                                <ChevronRight :size="14" aria-hidden="true" />{{ row.label }}
                                                <small>{{ row.items.length }}</small>
                                            </button>
                                            <span v-else>{{ row.label }}</span>
                                            <small v-if="row.note" class="rpt-line-note">{{ row.note }}</small>
                                        </th>
                                        <td class="num" :class="amountTone(row)">{{ fmtLine(row, row.cur) }}</td>
                                        <td v-if="plPrev" class="num rpt-muted">{{ row.prev == null ? '—' : fmtLine(row, row.prev) }}</td>
                                        <td v-if="plPrev" class="num">
                                            <span v-if="row.prev != null" class="rpt-delta" :class="deltaTone(row.cur - row.prev, row.higherIsBetter)">{{ deltaText(row.cur, row.prev) }}</span>
                                        </td>
                                    </tr>
                                    <tr v-if="row.items?.length && plOpen[row.key]" class="row-items">
                                        <td :colspan="plPrev ? 4 : 2">
                                            <ul>
                                                <li v-for="(item, i) in row.items" :key="i">
                                                    <span>{{ item.description }}<small>{{ item.transacted_at?.slice(0, 10) }}</small></span>
                                                    <strong>{{ fmt(item.amount) }}</strong>
                                                </li>
                                            </ul>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <p class="rpt-footnote">
                        Cash basis: revenue counts when an order is paid, and only paid bills and expenses are deducted.
                        <template v-if="plIncludeCogs"> Inventory purchases are stock bought, not spent. Their cost reaches profit through COGS when the food sells.</template>
                        <template v-else> COGS is off, so inventory purchases are deducted as operating expenses when bought.</template>
                    </p>
                </section>

                <div v-if="(plReport.unpaid_completed?.count ?? 0) > 0" class="rpt-callout" role="status">
                    <TrendingDown :size="18" aria-hidden="true" />
                    <div>
                        <strong>{{ plReport.unpaid_completed!.count }} completed order{{ plReport.unpaid_completed!.count !== 1 ? 's' : '' }} worth {{ fmt(plReport.unpaid_completed!.total) }} are not in this profit.</strong>
                        <p>Revenue is recognised only when an order is fully paid. Record the outstanding payments to include them.</p>
                    </div>
                </div>
            </div>
            <div v-else-if="!loading" class="rpt-empty">
                <Scale :size="28" aria-hidden="true" />
                <h3>Pick a period to build the statement.</h3>
                <p>Choose a quick period above, or set dates and select Update report.</p>
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
                <div :class="['rounded-xl border p-4 shadow-sm', overdueBills.length > 0 ? 'bg-red-50 border-red-200' : 'bg-card']">
                    <p class="text-xs text-muted-foreground mb-1">Overdue</p>
                    <p class="text-3xl font-black" :class="overdueBills.length > 0 ? 'text-red-600' : ''">{{ overdueBills.length }}</p>
                </div>
                <div :class="['rounded-xl border p-4 shadow-sm', dueSoonBills.length > 0 ? 'bg-yellow-50 border-yellow-200' : 'bg-card']">
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
                                billForm.is_installment ? 'border-orange-500 bg-orange-50 text-orange-700' : 'border-border text-muted-foreground hover:bg-muted']">
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
                                            class="rounded p-1 text-muted-foreground hover:text-blue-600 hover:bg-blue-50 transition">
                                            <Pencil class="h-3.5 w-3.5" />
                                        </button>
                                        <button @click="deleteBill(bill)" :disabled="billDeleting === bill.id"
                                            class="rounded p-1 text-muted-foreground hover:text-red-600 hover:bg-red-50 disabled:opacity-40 transition">
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
                    <div class="rounded-lg bg-green-50 p-4">
                        <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Revenue</p>
                        <p class="text-2xl font-black text-green-600">{{ fmt(dailyReport.total_sales) }}</p>
                    </div>
                    <div class="rounded-lg bg-yellow-50 p-4">
                        <p class="text-xs text-muted-foreground mb-1">Discounts</p>
                        <p class="text-2xl font-black text-yellow-600">{{ fmt(dailyReport.total_discount) }}</p>
                    </div>
                </div>
            </div>

            <!-- FT Breakdown card -->
            <div v-if="ftBreakdown" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b bg-muted/30">
                    <h2 class="font-bold text-sm">Transaction Breakdown — {{ ftBreakdown.period.start }}</h2>
                </div>
                <div class="divide-y">
                    <!-- By Type -->
                    <div class="p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-3">By Transaction Type</p>
                        <div class="space-y-2">
                            <div v-for="row in ftBreakdown.by_type" :key="row.type"
                                class="flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-medium truncate">{{ ftTypeLabel[row.type] ?? row.type }}</span>
                                    <span class="text-[11px] text-muted-foreground shrink-0">{{ row.count }} txn{{ row.count !== 1 ? 's' : '' }}</span>
                                </div>
                                <span class="text-sm font-bold tabular-nums shrink-0 ml-3" :class="ftTypeColor[row.type] ?? ''">
                                    {{ ['payment','income_adjustment'].includes(row.type) ? '+' : '-' }}{{ fmt(row.total) }}
                                </span>
                            </div>
                            <div v-if="!ftBreakdown.by_type.length" class="text-sm text-muted-foreground text-center py-2">No transactions for this period.</div>
                        </div>
                    </div>
                    <!-- By Tender -->
                    <div class="p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-3">By Tender</p>
                        <!-- Mobile -->
                        <div class="md:hidden space-y-2">
                            <div v-for="row in ftBreakdown.by_tender" :key="row.tender"
                                class="rounded-lg bg-muted/30 px-3 py-2">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-semibold text-sm">{{ row.tender }}</span>
                                    <span class="text-[11px] text-muted-foreground">{{ row.count }} txns</span>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                    <span class="text-green-600">In: +{{ fmt(row.total_in) }}</span>
                                    <span class="text-red-500">Out: -{{ fmt(row.total_out) }}</span>
                                    <span :class="row.net >= 0 ? 'text-green-600 font-semibold' : 'text-red-500 font-semibold'">
                                        Net: {{ row.net >= 0 ? '+' : '' }}{{ fmt(row.net) }}
                                    </span>
                                </div>
                            </div>
                            <!-- Total row -->
                            <div v-if="ftBreakdown.by_tender.length" class="rounded-lg border px-3 py-2">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-bold text-sm">Total</span>
                                    <span class="text-[11px] text-muted-foreground">{{ ftBreakdown.by_tender.reduce((s,r) => s+r.count,0) }} txns</span>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs font-bold">
                                    <span class="text-green-600">In: +{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_in,0)) }}</span>
                                    <span class="text-red-500">Out: -{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_out,0)) }}</span>
                                    <span :class="ftBreakdown.by_tender.reduce((s,r)=>s+r.net,0) >= 0 ? 'text-green-600' : 'text-red-500'">
                                        Net: {{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.net,0)) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <!-- Desktop table -->
                        <div class="hidden md:block overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-[11px] text-muted-foreground uppercase tracking-wide border-b">
                                        <th class="text-left pb-2 font-semibold">Tender</th>
                                        <th class="text-right pb-2 font-semibold">In</th>
                                        <th class="text-right pb-2 font-semibold">Out</th>
                                        <th class="text-right pb-2 font-semibold">Net</th>
                                        <th class="text-right pb-2 font-semibold">Txns</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr v-for="row in ftBreakdown.by_tender" :key="row.tender" class="hover:bg-muted/20">
                                        <td class="py-2 font-medium">{{ row.tender }}</td>
                                        <td class="py-2 text-right tabular-nums text-green-600">+{{ fmt(row.total_in) }}</td>
                                        <td class="py-2 text-right tabular-nums text-red-500">-{{ fmt(row.total_out) }}</td>
                                        <td class="py-2 text-right tabular-nums font-semibold" :class="row.net >= 0 ? 'text-green-600' : 'text-red-500'">
                                            {{ row.net >= 0 ? '+' : '' }}{{ fmt(row.net) }}
                                        </td>
                                        <td class="py-2 text-right tabular-nums text-muted-foreground">{{ row.count }}</td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="ftBreakdown.by_tender.length" class="border-t font-bold">
                                    <tr>
                                        <td class="pt-2">Total</td>
                                        <td class="pt-2 text-right tabular-nums text-green-600">+{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_in,0)) }}</td>
                                        <td class="pt-2 text-right tabular-nums text-red-500">-{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_out,0)) }}</td>
                                        <td class="pt-2 text-right tabular-nums" :class="ftBreakdown.by_tender.reduce((s,r)=>s+r.net,0) >= 0 ? 'text-green-600' : 'text-red-500'">
                                            {{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.net,0)) }}
                                        </td>
                                        <td class="pt-2 text-right tabular-nums text-muted-foreground">{{ ftBreakdown.by_tender.reduce((s,r) => s+r.count,0) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p v-if="!ftBreakdown.by_tender.length" class="text-sm text-muted-foreground text-center py-2">No transactions for this period.</p>
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
                    <div class="rounded-lg bg-green-50 p-4">
                        <p class="text-xs text-muted-foreground mb-1 flex items-center gap-1"><TrendingUp class="h-3 w-3" /> Revenue</p>
                        <p class="text-2xl font-black text-green-600">{{ fmt(monthlyReport.total_sales) }}</p>
                    </div>
                    <div class="rounded-lg bg-yellow-50 p-4">
                        <p class="text-xs text-muted-foreground mb-1">Discounts</p>
                        <p class="text-2xl font-black text-yellow-600">{{ fmt(monthlyReport.total_discount) }}</p>
                    </div>
                </div>
            </div>

            <!-- FT Breakdown card -->
            <div v-if="ftBreakdown" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="px-4 py-3 border-b bg-muted/30">
                    <h2 class="font-bold text-sm">Transaction Breakdown — {{ ftBreakdown.period.start }} to {{ ftBreakdown.period.end }}</h2>
                </div>
                <div class="divide-y">
                    <!-- By Type -->
                    <div class="p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-3">By Transaction Type</p>
                        <div class="space-y-2">
                            <div v-for="row in ftBreakdown.by_type" :key="row.type"
                                class="flex items-center justify-between rounded-lg bg-muted/30 px-3 py-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="text-sm font-medium truncate">{{ ftTypeLabel[row.type] ?? row.type }}</span>
                                    <span class="text-[11px] text-muted-foreground shrink-0">{{ row.count }} txn{{ row.count !== 1 ? 's' : '' }}</span>
                                </div>
                                <span class="text-sm font-bold tabular-nums shrink-0 ml-3" :class="ftTypeColor[row.type] ?? ''">
                                    {{ ['payment','income_adjustment'].includes(row.type) ? '+' : '-' }}{{ fmt(row.total) }}
                                </span>
                            </div>
                            <div v-if="!ftBreakdown.by_type.length" class="text-sm text-muted-foreground text-center py-2">No transactions for this period.</div>
                        </div>
                    </div>
                    <!-- By Tender -->
                    <div class="p-4">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground mb-3">By Tender</p>
                        <!-- Mobile -->
                        <div class="md:hidden space-y-2">
                            <div v-for="row in ftBreakdown.by_tender" :key="row.tender"
                                class="rounded-lg bg-muted/30 px-3 py-2">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-semibold text-sm">{{ row.tender }}</span>
                                    <span class="text-[11px] text-muted-foreground">{{ row.count }} txns</span>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                    <span class="text-green-600">In: +{{ fmt(row.total_in) }}</span>
                                    <span class="text-red-500">Out: -{{ fmt(row.total_out) }}</span>
                                    <span :class="row.net >= 0 ? 'text-green-600 font-semibold' : 'text-red-500 font-semibold'">
                                        Net: {{ row.net >= 0 ? '+' : '' }}{{ fmt(row.net) }}
                                    </span>
                                </div>
                            </div>
                            <div v-if="ftBreakdown.by_tender.length" class="rounded-lg border px-3 py-2">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="font-bold text-sm">Total</span>
                                    <span class="text-[11px] text-muted-foreground">{{ ftBreakdown.by_tender.reduce((s,r) => s+r.count,0) }} txns</span>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs font-bold">
                                    <span class="text-green-600">In: +{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_in,0)) }}</span>
                                    <span class="text-red-500">Out: -{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_out,0)) }}</span>
                                    <span :class="ftBreakdown.by_tender.reduce((s,r)=>s+r.net,0) >= 0 ? 'text-green-600' : 'text-red-500'">
                                        Net: {{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.net,0)) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <!-- Desktop table -->
                        <div class="hidden md:block overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-[11px] text-muted-foreground uppercase tracking-wide border-b">
                                        <th class="text-left pb-2 font-semibold">Tender</th>
                                        <th class="text-right pb-2 font-semibold">In</th>
                                        <th class="text-right pb-2 font-semibold">Out</th>
                                        <th class="text-right pb-2 font-semibold">Net</th>
                                        <th class="text-right pb-2 font-semibold">Txns</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr v-for="row in ftBreakdown.by_tender" :key="row.tender" class="hover:bg-muted/20">
                                        <td class="py-2 font-medium">{{ row.tender }}</td>
                                        <td class="py-2 text-right tabular-nums text-green-600">+{{ fmt(row.total_in) }}</td>
                                        <td class="py-2 text-right tabular-nums text-red-500">-{{ fmt(row.total_out) }}</td>
                                        <td class="py-2 text-right tabular-nums font-semibold" :class="row.net >= 0 ? 'text-green-600' : 'text-red-500'">
                                            {{ row.net >= 0 ? '+' : '' }}{{ fmt(row.net) }}
                                        </td>
                                        <td class="py-2 text-right tabular-nums text-muted-foreground">{{ row.count }}</td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="ftBreakdown.by_tender.length" class="border-t font-bold">
                                    <tr>
                                        <td class="pt-2">Total</td>
                                        <td class="pt-2 text-right tabular-nums text-green-600">+{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_in,0)) }}</td>
                                        <td class="pt-2 text-right tabular-nums text-red-500">-{{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.total_out,0)) }}</td>
                                        <td class="pt-2 text-right tabular-nums" :class="ftBreakdown.by_tender.reduce((s,r)=>s+r.net,0) >= 0 ? 'text-green-600' : 'text-red-500'">
                                            {{ fmt(ftBreakdown.by_tender.reduce((s,r) => s+r.net,0)) }}
                                        </td>
                                        <td class="pt-2 text-right tabular-nums text-muted-foreground">{{ ftBreakdown.by_tender.reduce((s,r) => s+r.count,0) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p v-if="!ftBreakdown.by_tender.length" class="text-sm text-muted-foreground text-center py-2">No transactions for this period.</p>
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

                <!-- Mobile cards -->
                <div class="md:hidden divide-y">
                    <div v-for="(item, i) in topProducts" :key="item.product_id" class="transition-colors">
                        <button class="w-full px-4 py-3 hover:bg-muted/20 text-left" @click="toggleProduct(item.product_id)">
                            <div class="flex items-start gap-3">
                                <!-- Rank badge -->
                                <div :class="['shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-black',
                                    i === 0 ? 'bg-amber-100 text-amber-700' :
                                    i === 1 ? 'bg-zinc-100 text-zinc-600' :
                                    i === 2 ? 'bg-orange-100 text-orange-700' :
                                    'bg-muted text-muted-foreground']">
                                    {{ i + 1 }}
                                </div>
                                <!-- Content -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2 mb-1.5">
                                        <p class="font-semibold text-sm truncate">{{ item.product_name }}</p>
                                        <div class="flex items-center gap-1.5 shrink-0">
                                            <span class="text-xs bg-muted rounded-full px-2 py-0.5 font-medium tabular-nums">×{{ item.total_quantity }}</span>
                                            <span class="text-xs bg-primary/10 text-primary rounded-full px-2 py-0.5 font-semibold tabular-nums">
                                                {{ totalProductSales > 0 ? ((Number(item.total_sales) / totalProductSales) * 100).toFixed(1) : '0.0' }}%
                                            </span>
                                            <ChevronDown :class="['h-3.5 w-3.5 text-muted-foreground transition-transform', expandedProducts[item.product_id] ? 'rotate-180' : '']" />
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <p class="text-sm font-bold text-green-600 tabular-nums shrink-0">{{ fmt(item.total_sales) }}</p>
                                        <div class="flex-1 h-1.5 bg-muted rounded-full overflow-hidden">
                                            <div class="h-full bg-primary rounded-full transition-all duration-500"
                                                :style="{ width: topProducts[0]?.total_sales ? ((Number(item.total_sales) / Number(topProducts[0].total_sales)) * 100) + '%' : '0%' }" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </button>
                        <!-- Collapsible chart -->
                        <div v-if="expandedProducts[item.product_id]" class="px-4 pb-4 bg-muted/20">
                            <div v-if="productDailyData[item.product_id]?.loading" class="py-4 text-center text-xs text-muted-foreground">Loading…</div>
                            <template v-else-if="productDailyData[item.product_id]?.points?.length">
                                <div class="flex justify-between text-[10px] text-muted-foreground mb-1">
                                    <span>{{ productDailyData[item.product_id].points[0]?.date }}</span>
                                    <span>{{ productDailyData[item.product_id].points.at(-1)?.date }}</span>
                                </div>
                                <div class="relative pt-4" @mouseleave="prodChartTooltip = null">
                                    <div class="absolute top-0 right-0 text-[9px] text-muted-foreground tabular-nums leading-none">{{ fmtShort(productLineData[item.product_id]?.max ?? 0) }}</div>
                                    <svg viewBox="0 0 400 80" class="w-full h-20 text-emerald-500" preserveAspectRatio="none" style="cursor:crosshair"
                                         @mousemove="onProdChartHover($event, item.product_id)">
                                        <defs>
                                            <linearGradient :id="`pg-${item.product_id}`" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stop-color="currentColor" stop-opacity="0.4"/>
                                                <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
                                            </linearGradient>
                                        </defs>
                                        <line x1="0" y1="70" x2="400" y2="70" stroke="currentColor" stroke-opacity="0.2" stroke-width="1"/>
                                        <line x1="0" y1="40" x2="400" y2="40" stroke="currentColor" stroke-opacity="0.1" stroke-width="1" stroke-dasharray="4,3"/>
                                        <path v-if="productLineData[item.product_id]?.area" :d="productLineData[item.product_id].area" :fill="`url(#pg-${item.product_id})`" />
                                        <path v-if="productLineData[item.product_id]?.path" :d="productLineData[item.product_id].path" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <template v-if="prodChartTooltip?.productId === item.product_id && productLineData[item.product_id]?.coords[prodChartTooltip.idx]">
                                            <line :x1="productLineData[item.product_id].coords[prodChartTooltip.idx].x" y1="0" :x2="productLineData[item.product_id].coords[prodChartTooltip.idx].x" y2="70" stroke="currentColor" stroke-opacity="0.3" stroke-width="1" stroke-dasharray="3,2"/>
                                            <circle :cx="productLineData[item.product_id].coords[prodChartTooltip.idx].x" :cy="productLineData[item.product_id].coords[prodChartTooltip.idx].y" r="3.5" fill="currentColor" stroke="hsl(var(--card))" stroke-width="2"/>
                                        </template>
                                    </svg>
                                    <div v-if="prodChartTooltip?.productId === item.product_id"
                                         class="absolute bottom-full mb-1 bg-popover text-popover-foreground border rounded-md px-2 py-1 text-xs shadow-md pointer-events-none whitespace-nowrap z-20"
                                         :style="{ left: `${(prodChartTooltip.pct * 100).toFixed(1)}%`, transform: 'translateX(-50%)' }">
                                        <div class="font-medium text-muted-foreground">{{ prodChartTooltip.point.date }}</div>
                                        <div class="font-bold text-primary">{{ fmt(prodChartTooltip.point.sales) }}</div>
                                        <div class="text-muted-foreground">×{{ prodChartTooltip.point.qty }} sold</div>
                                    </div>
                                </div>
                            </template>
                            <div v-else class="py-4 text-center text-xs text-muted-foreground">No sales in this period</div>
                        </div>
                    </div>
                    <div v-if="topProducts.length === 0" class="px-4 py-8 text-center text-muted-foreground text-sm">
                        No data available for this period.
                    </div>
                </div>

                <!-- Desktop table -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase tracking-wide">
                            <tr>
                                <th class="px-4 py-3 text-left w-8">#</th>
                                <th class="px-4 py-3 text-left">Product</th>
                                <th class="px-4 py-3 text-right">Qty Sold</th>
                                <th class="px-4 py-3 text-right">Revenue</th>
                                <th class="px-4 py-3 text-right">% of Sales</th>
                                <th class="px-4 py-3 text-left">Share</th>
                                <th class="px-2 py-3 w-8"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(item, i) in topProducts" :key="item.product_id">
                                <tr class="border-t hover:bg-muted/20 cursor-pointer" @click="toggleProduct(item.product_id)">
                                    <td class="px-4 py-2 text-muted-foreground font-medium">{{ i + 1 }}</td>
                                    <td class="px-4 py-2 font-medium">{{ item.product_name }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ item.total_quantity }}</td>
                                    <td class="px-4 py-2 text-right font-bold text-green-600 tabular-nums">{{ fmt(item.total_sales) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-muted-foreground">
                                        {{ totalProductSales > 0 ? ((Number(item.total_sales) / totalProductSales) * 100).toFixed(1) : '0.0' }}%
                                    </td>
                                    <td class="px-4 py-2 w-40">
                                        <div class="flex-1 h-1.5 bg-muted rounded-full overflow-hidden">
                                            <div class="h-full bg-primary rounded-full"
                                                :style="{ width: topProducts[0]?.total_sales ? ((Number(item.total_sales) / Number(topProducts[0].total_sales)) * 100) + '%' : '0%' }" />
                                        </div>
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        <ChevronDown :class="['h-4 w-4 text-muted-foreground transition-transform', expandedProducts[item.product_id] ? 'rotate-180' : '']" />
                                    </td>
                                </tr>
                                <!-- Collapsible chart row -->
                                <tr v-if="expandedProducts[item.product_id]" class="border-t bg-muted/20">
                                    <td colspan="7" class="px-6 py-3">
                                        <div v-if="productDailyData[item.product_id]?.loading" class="py-2 text-xs text-muted-foreground text-center">Loading…</div>
                                        <template v-else-if="productDailyData[item.product_id]?.points?.length">
                                            <div class="flex justify-between text-[10px] text-muted-foreground mb-1">
                                                <span>{{ productDailyData[item.product_id].points[0]?.date }}</span>
                                                <span>{{ productDailyData[item.product_id].points.at(-1)?.date }}</span>
                                            </div>
                                            <div class="relative pt-4" @mouseleave="prodChartTooltip = null">
                                                <div class="absolute top-0 right-0 text-[9px] text-muted-foreground tabular-nums leading-none">{{ fmtShort(productLineData[item.product_id]?.max ?? 0) }}</div>
                                                <svg viewBox="0 0 400 80" class="w-full h-20 text-emerald-500" preserveAspectRatio="none" style="cursor:crosshair"
                                                     @mousemove="onProdChartHover($event, item.product_id)">
                                                    <defs>
                                                        <linearGradient :id="`pgd-${item.product_id}`" x1="0" y1="0" x2="0" y2="1">
                                                            <stop offset="0%" stop-color="currentColor" stop-opacity="0.4"/>
                                                            <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
                                                        </linearGradient>
                                                    </defs>
                                                    <line x1="0" y1="70" x2="400" y2="70" stroke="currentColor" stroke-opacity="0.2" stroke-width="1"/>
                                                    <line x1="0" y1="40" x2="400" y2="40" stroke="currentColor" stroke-opacity="0.1" stroke-width="1" stroke-dasharray="4,3"/>
                                                    <path v-if="productLineData[item.product_id]?.area" :d="productLineData[item.product_id].area" :fill="`url(#pgd-${item.product_id})`" />
                                                    <path v-if="productLineData[item.product_id]?.path" :d="productLineData[item.product_id].path" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                    <template v-if="prodChartTooltip?.productId === item.product_id && productLineData[item.product_id]?.coords[prodChartTooltip.idx]">
                                                        <line :x1="productLineData[item.product_id].coords[prodChartTooltip.idx].x" y1="0" :x2="productLineData[item.product_id].coords[prodChartTooltip.idx].x" y2="70" stroke="currentColor" stroke-opacity="0.3" stroke-width="1" stroke-dasharray="3,2"/>
                                                        <circle :cx="productLineData[item.product_id].coords[prodChartTooltip.idx].x" :cy="productLineData[item.product_id].coords[prodChartTooltip.idx].y" r="3.5" fill="currentColor" stroke="hsl(var(--card))" stroke-width="2"/>
                                                    </template>
                                                </svg>
                                                <div v-if="prodChartTooltip?.productId === item.product_id"
                                                     class="absolute bottom-full mb-1 bg-popover text-popover-foreground border rounded-md px-2 py-1 text-xs shadow-md pointer-events-none whitespace-nowrap z-20"
                                                     :style="{ left: `${(prodChartTooltip.pct * 100).toFixed(1)}%`, transform: 'translateX(-50%)' }">
                                                    <div class="font-medium text-muted-foreground">{{ prodChartTooltip.point.date }}</div>
                                                    <div class="font-bold text-primary">{{ fmt(prodChartTooltip.point.sales) }}</div>
                                                    <div class="text-muted-foreground">×{{ prodChartTooltip.point.qty }} sold</div>
                                                </div>
                                            </div>
                                        </template>
                                        <div v-else class="py-2 text-xs text-muted-foreground text-center">No sales in this period</div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="topProducts.length === 0">
                                <td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No data available for this period.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Welcome-page palette. The tokens also restyle every Tailwind utility on this page and
   inside the Trends and Serving time tabs, which inherit them. Stays light like the dashboard. */
.rpt-theme {
    --background: #fffcf6;
    --foreground: #24231e;
    --card: #fffcf6;
    --card-foreground: #24231e;
    --popover: #fffcf6;
    --popover-foreground: #24231e;
    --primary: #ef5b2a;
    --primary-foreground: #fff;
    --secondary: #efeadf;
    --secondary-foreground: #24231e;
    --muted: #efeadf;
    --muted-foreground: #68665f;
    --accent: #efeadf;
    --accent-foreground: #24231e;
    --destructive: #c0391b;
    --destructive-foreground: #fff;
    --border: #ded7cb;
    --input: #d4cdbf;
    --ring: #c3441c;
    --ink: #24231e;
    --cream: #f6f2e9;
    --paper: #fffcf6;
    --orange: #ef5b2a;
    --orange-deep: #c3441c;
    --line-soft: #ece5da;
    --in: #3f7a33;
    --out: #c0391b;
    color: var(--ink);
    color-scheme: light;
    font-family: Arial, Helvetica, sans-serif;
}
.rpt-page {
    min-height: 100%;
    padding: 32px clamp(16px, 3vw, 44px) 48px;
    background: var(--cream);
}
.rpt-theme :focus-visible {
    outline: 2px solid var(--orange-deep);
    outline-offset: 2px;
}
.rpt-theme button:not(:disabled) {
    cursor: pointer;
}
.is-in {
    color: var(--in);
}
.is-out {
    color: var(--out);
}

/* Heading */
.rpt-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    flex-wrap: wrap;
    gap: 18px 24px;
}
.rpt-eyebrow {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
}
.rpt-eyebrow > span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--orange);
}
.rpt-heading h1 {
    margin: 14px 0 10px;
    font-family: Impact, 'Arial Narrow', sans-serif;
    font-size: clamp(40px, 5.4vw, 72px);
    font-weight: 900;
    line-height: 0.92;
    letter-spacing: -1px;
}
.rpt-heading h1 span {
    color: var(--orange);
}
.rpt-intro {
    max-width: 460px;
    min-height: 1.7em;
    font-size: 14px;
    line-height: 1.7;
    color: #68665f;
}
.rpt-heading-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

/* Buttons */
.rpt-primary-btn,
.rpt-ghost-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border-radius: 4px;
    padding: 10px 16px;
    font-size: 12px;
    font-weight: 800;
    white-space: nowrap;
    transition: background 0.15s;
}
.rpt-primary-btn {
    background: var(--orange);
    color: #fff;
}
.rpt-primary-btn:hover:not(:disabled) {
    background: var(--orange-deep);
}
.rpt-ghost-btn {
    border: 1px solid #d4cdbf;
    background: var(--paper);
    color: var(--ink);
}
.rpt-ghost-btn:hover:not(:disabled) {
    background: #efeadf;
}
.rpt-primary-btn:disabled,
.rpt-ghost-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Navigation */
.rpt-nav {
    display: flex;
    gap: 6px 22px;
    overflow-x: auto;
    padding: 12px 16px;
    border-radius: 6px;
    background: var(--ink);
    scrollbar-width: none;
}
.rpt-nav-group {
    flex-shrink: 0;
}
.rpt-nav-group > p {
    margin: 0 0 7px 2px;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 1.6px;
    text-transform: uppercase;
    color: #9d988b;
}
.rpt-nav-group > div {
    display: flex;
    gap: 4px;
}
.rpt-nav-group + .rpt-nav-group {
    padding-left: 22px;
    border-left: 1px solid #ffffff1f;
}
.rpt-nav button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 4px;
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 700;
    color: #d8d3c7;
    white-space: nowrap;
    transition:
        background 0.15s,
        color 0.15s;
}
.rpt-nav button:hover {
    background: #ffffff14;
    color: #fff;
}
.rpt-nav button[aria-selected='true'] {
    background: var(--orange);
    color: #fff;
}

/* Filters */
.rpt-filters {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 16px;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: var(--paper);
}
.rpt-presets {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: none;
}
.rpt-presets button {
    flex-shrink: 0;
    border: 1px solid #d4cdbf;
    border-radius: 20px;
    padding: 7px 14px;
    background: #fff;
    font-size: 12px;
    font-weight: 700;
    color: #575144;
}
.rpt-presets button:hover {
    background: #efeadf;
}
.rpt-presets button[aria-pressed='true'] {
    border-color: var(--ink);
    background: var(--ink);
    color: var(--cream);
}
.rpt-switch {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    padding-bottom: 4px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
.rpt-switch small {
    display: block;
    font-size: 10px;
    font-weight: 400;
    color: #777268;
}
.rpt-switch input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.rpt-switch-track {
    position: relative;
    flex-shrink: 0;
    width: 34px;
    height: 20px;
    border-radius: 20px;
    background: #d4cdbf;
    transition: background 0.15s;
}
.rpt-switch-track span {
    position: absolute;
    top: 3px;
    left: 3px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.15s;
}
.rpt-switch input:checked + .rpt-switch-track {
    background: var(--orange);
}
.rpt-switch input:checked + .rpt-switch-track span {
    transform: translateX(14px);
}
.rpt-switch input:focus-visible + .rpt-switch-track {
    outline: 2px solid var(--orange-deep);
    outline-offset: 2px;
}

/* P&L */
.rpt-stack {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.rpt-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.rpt-kpi {
    min-width: 0;
    padding: 18px;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: var(--paper);
}
.rpt-kpi p {
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
}
.rpt-kpi strong {
    display: block;
    margin: 10px 0 5px;
    font-size: clamp(20px, 2.2vw, 28px);
    letter-spacing: -1px;
    line-height: 1.2;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.rpt-kpi > span {
    display: block;
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
}
.rpt-kpi.is-surplus {
    border-color: #b9cfae;
    background: #eef4ea;
}
.rpt-kpi.is-surplus strong {
    color: var(--in);
}
.rpt-kpi.is-deficit {
    border-color: #edc4b7;
    background: #fbe9e4;
}
.rpt-kpi.is-deficit strong {
    color: var(--out);
}
.rpt-kpi .rpt-delta {
    margin-top: 6px;
    font-size: 11px;
}
.rpt-delta {
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.rpt-delta.is-good {
    color: var(--in);
}
.rpt-delta.is-bad {
    color: var(--out);
}
.rpt-delta.is-flat {
    color: #93897b;
    font-weight: 700;
}
.rpt-panel {
    min-width: 0;
    padding: 20px;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    background: var(--paper);
}
.rpt-panel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 6px 14px;
    margin-bottom: 16px;
}
.rpt-panel-head h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.4px;
}
.rpt-panel-head > small {
    padding-top: 4px;
    font-size: 10px;
    color: #777268;
}
.rpt-kicker {
    margin-bottom: 5px;
    font-size: 8px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: var(--orange-deep);
}

/* Where each peso went */
.rpt-spend-bar {
    display: flex;
    gap: 3px;
    height: 38px;
    border-radius: 4px;
    overflow: hidden;
}
.rpt-spend-seg {
    display: grid;
    place-items: center;
    flex-basis: 0;
    min-width: 6px;
    font-size: 11px;
    font-weight: 800;
    color: #fff;
    transition:
        filter 0.15s,
        transform 0.15s;
}
.rpt-spend-seg:hover,
.rpt-spend-seg.is-active {
    filter: brightness(1.08);
    transform: scaleY(1.06);
}
.seg-cogs {
    background: #c3441c;
}
.seg-expenses {
    background: #ef5b2a;
}
.seg-payroll {
    background: #d8962b;
}
.seg-payouts {
    background: #7d6a55;
}
.seg-profit {
    background: #5f8f4e;
}
.rpt-spend-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 12px;
}
.rpt-spend-legend button {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #ece5da;
    border-radius: 20px;
    padding: 5px 10px;
    background: #fff;
    font-size: 11px;
    color: #575144;
}
.rpt-spend-legend button.is-active {
    border-color: var(--ink);
}
.rpt-spend-legend i {
    width: 9px;
    height: 9px;
    border-radius: 2px;
}
.rpt-spend-legend strong {
    color: var(--ink);
    font-variant-numeric: tabular-nums;
}
.rpt-spend-note {
    margin-top: 10px;
    font-size: 12px;
    color: #68665f;
}

/* Statement */
.rpt-table-scroll {
    overflow-x: auto;
}
.rpt-pl-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.rpt-pl-table thead th {
    padding: 0 12px 10px 0;
    text-align: left;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
}
.rpt-pl-table tbody th,
.rpt-pl-table tbody td {
    padding: 11px 12px 11px 0;
    border-top: 1px solid var(--line-soft);
    text-align: left;
    font-weight: 400;
    vertical-align: middle;
}
.rpt-pl-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.rpt-muted {
    color: #93897b;
}
.rpt-line-note {
    display: block;
    margin-top: 2px;
    font-size: 10px;
    color: #93897b;
}
.rpt-pl-table tr.row-less th {
    padding-left: 16px;
}
.rpt-pl-table tr.row-subtotal th,
.rpt-pl-table tr.row-subtotal td {
    border-top: 1px solid #d4cdbf;
    font-weight: 800;
}
.rpt-pl-table tr.row-total th,
.rpt-pl-table tr.row-total td {
    border-top: 2px solid var(--ink);
    background: #f6f2e9;
    font-size: 15px;
    font-weight: 800;
}
.rpt-pl-table tr.row-memo th,
.rpt-pl-table tr.row-memo td {
    border-top: 1px dashed #d4cdbf;
    color: #7b5815;
    font-style: italic;
}
.rpt-line-toggle {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font: inherit;
    color: inherit;
}
.rpt-line-toggle svg {
    color: #93897b;
    transition: transform 0.15s;
}
.rpt-line-toggle[aria-expanded='true'] svg {
    transform: rotate(90deg);
}
.rpt-line-toggle small {
    border-radius: 20px;
    padding: 0 6px;
    background: #efeadf;
    font-size: 10px;
    font-style: normal;
    color: #68665f;
}
.rpt-line-toggle:hover {
    color: var(--orange-deep);
}
.rpt-pl-table tr.row-items td {
    padding: 0 0 10px 32px;
    border-top: 0;
}
.rpt-pl-table tr.row-items ul {
    margin: 0;
    padding: 0;
    list-style: none;
}
.rpt-pl-table tr.row-items li {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 6px 12px 6px 10px;
    border-left: 2px solid #ece5da;
    font-size: 12px;
    color: #68665f;
}
.rpt-pl-table tr.row-items li small {
    margin-left: 8px;
    font-size: 10px;
    color: #aaa294;
}
.rpt-pl-table tr.row-items li strong {
    font-weight: 700;
    color: var(--ink);
    font-variant-numeric: tabular-nums;
}
.rpt-footnote {
    margin-top: 14px;
    font-size: 11px;
    line-height: 1.7;
    color: #777268;
}
.rpt-callout {
    display: flex;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid #e6d09b;
    border-radius: 6px;
    background: #fbf4e2;
    color: #7b5815;
}
.rpt-callout strong {
    display: block;
    font-size: 13px;
    color: var(--ink);
}
.rpt-callout p {
    margin-top: 3px;
    font-size: 12px;
}
.rpt-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 40px 16px;
    border: 1px dashed #d4cdbf;
    border-radius: 6px;
    background: var(--paper);
    text-align: center;
    color: #777268;
}
.rpt-empty > svg {
    color: var(--orange);
}
.rpt-empty h3 {
    font-size: 15px;
    font-weight: 800;
    color: var(--ink);
}
.rpt-empty p {
    font-size: 12px;
}

@media (max-width: 640px) {
    .rpt-page {
        padding: 24px 16px 40px;
    }
    .rpt-heading-actions {
        width: 100%;
    }
    .rpt-heading-actions > button {
        flex: 1;
    }
    .rpt-nav {
        padding: 10px 12px;
    }
    .rpt-panel {
        padding: 16px;
    }
    .rpt-pl-table {
        font-size: 12px;
    }
}
@media (prefers-reduced-motion: reduce) {
    .rpt-spend-seg,
    .rpt-line-toggle svg {
        transition: none;
    }
    .rpt-spend-seg:hover,
    .rpt-spend-seg.is-active {
        transform: none;
    }
}
@media print {
    .rpt-nav,
    .rpt-filters,
    .rpt-heading-actions {
        display: none;
    }
    .rpt-page {
        padding: 0;
        background: #fff;
    }
}
</style>
