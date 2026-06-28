<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    PieChart, Users, History, TrendingUp, RefreshCw, Plus, Trash2, Pencil,
    Download, Save, X, HelpCircle, Gift, Package, Banknote, CheckCircle2,
} from 'lucide-vue-next'

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Profit Sharing', href: '/distribution' }] } })

const props = defineProps<{
    categories: { id: number; name: string }[]
    products: { id: number; name: string; category_id: number }[]
    users: { id: number; name: string }[]
}>()

// ── Shared filters ────────────────────────────────────────────────────────
const today = new Date().toISOString().split('T')[0]
const monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0]
const basis = ref<'sales' | 'profit'>('sales')
const startDate = ref(monthStart)
const endDate = ref(today)
const categoryId = ref<number | ''>('')
const productId = ref<number | ''>('')

const subTab = ref<'distribution' | 'shareholders' | 'incentives' | 'trends' | 'history' | 'help'>('distribution')

const fmt = (v: number | null | undefined) => '₱' + (v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pct = (v: number | null | undefined) => ((v ?? 0).toFixed(2)) + '%'

// ── Distribution preview ─────────────────────────────────────────────────────
const result = ref<any>(null)
const loading = ref(false)

const params = () => ({
    basis: basis.value, start_date: startDate.value, end_date: endDate.value,
    category_id: categoryId.value || undefined, product_id: productId.value || undefined,
})

const loadPreview = async () => {
    loading.value = true
    try {
        const res = await api.get('/api/v1/distribution/preview', { params: params() })
        result.value = res.data
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to compute distribution')
    } finally {
        loading.value = false
    }
}

const setMonth   = () => { startDate.value = monthStart; endDate.value = today; loadPreview() }
const setQuarter = () => {
    const q = Math.floor(new Date().getMonth() / 3)
    startDate.value = new Date(new Date().getFullYear(), q * 3, 1).toISOString().split('T')[0]
    endDate.value = today; loadPreview()
}
const setYear = () => { startDate.value = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]; endDate.value = today; loadPreview() }
const setWeek = () => {
    const d = new Date()
    const day = d.getDay() || 7
    d.setDate(d.getDate() - day + 1)
    startDate.value = d.toISOString().split('T')[0]
    endDate.value = today; loadPreview()
}

const exportCsv   = () => { const qs = new URLSearchParams(params() as any).toString(); window.open(`/api/v1/distribution/export?${qs}`, '_blank') }
const saveSnapshot = async () => {
    try {
        await api.post('/api/v1/distribution/snapshots', params())
        toast.success('Snapshot saved to history')
        if (subTab.value === 'history') loadSnapshots()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save snapshot')
    }
}

// ── Combined summary (dividend + incentive) ──────────────────────────────────
const combinedSummary = computed(() => {
    if (!result.value) return []
    const map: Record<number, number> = {}
    for (const s of result.value.incentive?.by_shareholder ?? []) {
        map[s.shareholder_id] = s.incentive_amount ?? 0
    }
    return (result.value.members ?? []).map((m: any) => ({
        ...m,
        incentive_amount: map[m.shareholder_id] ?? 0,
        total_amount: Math.round(((m.amount ?? 0) + (map[m.shareholder_id] ?? 0)) * 100) / 100,
    }))
})

// ── Pie chart — Ownership Dividend ───────────────────────────────────────────
const pieSeries = computed(() => (result.value?.chart ?? []).map((c: any) => c.value))
const pieOptions = computed(() => ({
    chart: { type: 'pie' },
    labels: (result.value?.chart ?? []).map((c: any) => c.label),
    legend: { position: 'bottom' },
    colors: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#14b8a6', '#6b7280'],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: { y: { formatter: (val: number) => '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } },
}))

// ── Pie chart — Product Incentive Distribution ────────────────────────────────
const incentivePieData = computed(() => {
    const inc = result.value?.incentive
    if (!inc || (!(inc.by_shareholder?.length) && !(inc.company_retained > 0))) return null
    const slices = [
        ...(inc.by_shareholder ?? []).map((s: any) => ({ label: s.name, value: s.incentive_amount })),
        ...(inc.company_retained > 0 ? [{ label: 'Company (unowned)', value: inc.company_retained }] : []),
    ]
    return slices
})
const incentivePieSeries  = computed(() => incentivePieData.value?.map(s => s.value) ?? [])
const incentivePieOptions = computed(() => ({
    chart: { type: 'pie' },
    labels: incentivePieData.value?.map(s => s.label) ?? [],
    legend: { position: 'bottom' },
    colors: ['#f59e0b', '#f97316', '#eab308', '#84cc16', '#10b981', '#6b7280', '#8b5cf6'],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: { y: { formatter: (val: number) => '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } },
}))

// ── Pie chart — Combined (Dividend + Incentive) ───────────────────────────────
const combinedPieData = computed(() => {
    if (!result.value || !combinedSummary.value.length) return null
    const companyTotal = Math.round(((result.value.company_amount ?? 0) + (result.value.incentive?.company_retained ?? 0)) * 100) / 100
    return [
        ...combinedSummary.value.map((m: any) => ({ label: m.name, value: m.total_amount })),
        ...(companyTotal > 0 ? [{ label: 'Company', value: companyTotal }] : []),
    ]
})
const combinedPieSeries  = computed(() => combinedPieData.value?.map(s => s.value) ?? [])
const combinedPieOptions = computed(() => ({
    chart: { type: 'pie' },
    labels: combinedPieData.value?.map(s => s.label) ?? [],
    legend: { position: 'bottom' },
    colors: ['#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#14b8a6', '#10b981', '#6b7280'],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: { y: { formatter: (val: number) => '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } },
}))

// ── Shareholders ──────────────────────────────────────────────────────────────
const shareholders = ref<any[]>([])
const totalOwnership = ref(0)
const companyPct = ref(100)
const shForm = ref<any>({ id: null, name: '', email: '', ownership_percentage: '', status: 'active', notes: '' })
const shSaving = ref(false)

const loadShareholders = async () => {
    const res = await api.get('/api/v1/shareholders')
    shareholders.value = res.data.shareholders
    totalOwnership.value = res.data.total_ownership
    companyPct.value = res.data.company_percentage
}
const editSh     = (s: any) => { shForm.value = { ...s } }
const resetShForm = () => { shForm.value = { id: null, name: '', email: '', ownership_percentage: '', status: 'active', notes: '' } }
const saveSh = async () => {
    shSaving.value = true
    try {
        const payload = { ...shForm.value, ownership_percentage: parseFloat(shForm.value.ownership_percentage) || 0 }
        if (shForm.value.id) await api.put(`/api/v1/shareholders/${shForm.value.id}`, payload)
        else await api.post('/api/v1/shareholders', payload)
        toast.success('Shareholder saved')
        resetShForm(); await loadShareholders()
    } catch (err: any) {
        toast.error(Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || err.response?.data?.message || 'Failed to save')
    } finally { shSaving.value = false }
}
const deleteSh = async (s: any) => {
    if (!confirm(`Remove shareholder ${s.name}?`)) return
    await api.delete(`/api/v1/shareholders/${s.id}`); toast.success('Removed'); await loadShareholders()
}

// ── Incentive rules (pool rate config) ───────────────────────────────────────
const incentiveRules = ref<any[]>([])
const iForm = ref<any>({ id: null, name: '', pool_type: 'gross_sales_pct', rate: '', distribution_method: 'by_sales', is_active: true, effective_date: today, expiration_date: '', notes: '' })
const iSaving = ref(false)

const loadIncentiveRules = async () => { incentiveRules.value = (await api.get('/api/v1/incentive-rules')).data }
const editIncentive = (r: any) => { iForm.value = { ...r, expiration_date: r.expiration_date ?? '' } }
const resetIForm = () => { iForm.value = { id: null, name: '', pool_type: 'gross_sales_pct', rate: '', distribution_method: 'by_sales', is_active: true, effective_date: today, expiration_date: '', notes: '' } }
const saveIncentive = async () => {
    iSaving.value = true
    try {
        const payload = { ...iForm.value, rate: parseFloat(iForm.value.rate) || 0, expiration_date: iForm.value.expiration_date || null }
        if (iForm.value.id) await api.put(`/api/v1/incentive-rules/${iForm.value.id}`, payload)
        else await api.post('/api/v1/incentive-rules', payload)
        toast.success('Incentive rule saved'); resetIForm(); await loadIncentiveRules()
    } catch (err: any) {
        toast.error(Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || err.response?.data?.message || 'Failed to save')
    } finally { iSaving.value = false }
}
const deleteIncentive = async (r: any) => {
    if (!confirm(`Delete incentive rule "${r.name}"?`)) return
    await api.delete(`/api/v1/incentive-rules/${r.id}`); toast.success('Deleted'); await loadIncentiveRules()
}

const poolTypeLabel = (t: string) => ({
    gross_sales_pct:    '% of Gross Sales',
    gross_profit_pct:   '% of Gross Profit',
    net_profit_pct:     '% of Net Profit',
    fixed_amount:       'Fixed ₱ Amount',
    product_sales_pct:  '% of Each Product\'s Sales',
}[t] ?? t)

const poolTypeUnit = (t: string) => t === 'fixed_amount' ? '₱' : '%'

// ── Product Ownership ─────────────────────────────────────────────────────────
const productOwnerships = ref<any[]>([])
const productFilter = ref('')
const editProductId = ref<number | null>(null)
const editProductName = ref('')
const editOwnerRows = ref<{ shareholder_id: number | ''; ownership_percentage: number | '' }[]>([])
const productOwnerSaving = ref(false)

const filteredProducts = computed(() => {
    const q = productFilter.value.toLowerCase()
    return q ? productOwnerships.value.filter(p => p.product_name.toLowerCase().includes(q)) : productOwnerships.value
})

const ownerTotalPct = computed(() =>
    editOwnerRows.value.reduce((s, r) => s + (parseFloat(r.ownership_percentage as any) || 0), 0)
)

const loadProductOwnerships = async () => {
    productOwnerships.value = (await api.get('/api/v1/product-ownerships')).data
}

const startEditProduct = (p: any) => {
    editProductId.value = p.product_id
    editProductName.value = p.product_name
    editOwnerRows.value = p.owners.length
        ? p.owners.map((o: any) => ({ shareholder_id: o.shareholder_id, ownership_percentage: o.ownership_percentage }))
        : [{ shareholder_id: '', ownership_percentage: '' }]
}

const addOwnerRow = () => editOwnerRows.value.push({ shareholder_id: '', ownership_percentage: '' })
const removeOwnerRow = (i: number) => editOwnerRows.value.splice(i, 1)

const saveProductOwnership = async () => {
    if (editOwnerRows.value.length > 0 && Math.abs(ownerTotalPct.value - 100) > 0.01) {
        toast.error('Ownership percentages must total 100%')
        return
    }
    productOwnerSaving.value = true
    try {
        const owners = editOwnerRows.value
            .filter(r => r.shareholder_id !== '')
            .map(r => ({ shareholder_id: r.shareholder_id, ownership_percentage: parseFloat(r.ownership_percentage as any) || 0 }))
        await api.put(`/api/v1/product-ownerships/${editProductId.value}`, { owners })
        toast.success('Product ownership saved')
        editProductId.value = null
        await loadProductOwnerships()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save')
    } finally { productOwnerSaving.value = false }
}

const clearProductOwnership = async (productId: number, productName: string) => {
    if (!confirm(`Remove all owners from "${productName}"? It will become company-owned.`)) return
    try {
        await api.delete(`/api/v1/product-ownerships/${productId}`)
        toast.success('Owners cleared')
        if (editProductId.value === productId) editProductId.value = null
        await loadProductOwnerships()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to clear owners')
    }
}

// ── Trends ────────────────────────────────────────────────────────────────────
const trend = ref<any[]>([])

const loadTrends = async () => {
    const yearStart = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
    const res = await api.get('/api/v1/distribution/trend', { params: { basis: basis.value, start_date: yearStart, end_date: today } })
    trend.value = res.data
}
const trendSeries = computed(() => ([
    { name: 'Dividend', data: trend.value.map((t: any) => t.members) },
    { name: 'Incentive', data: trend.value.map((t: any) => t.incentive ?? 0) },
    { name: 'Company', data: trend.value.map((t: any) => t.company) },
]))
const trendOptions = computed(() => ({
    chart: { type: 'line', toolbar: { show: false } },
    stroke: { width: 2, curve: 'smooth' },
    xaxis: { categories: trend.value.map((t: any) => t.month) },
    colors: ['#3b82f6', '#f59e0b', '#10b981'],
    yaxis: { labels: { formatter: (v: number) => '₱' + (v / 1000).toFixed(0) + 'K' } },
    legend: { position: 'top' },
}))

// ── Snapshots history ─────────────────────────────────────────────────────────
const snapshots = ref<any[]>([])
const loadSnapshots = async () => { snapshots.value = (await api.get('/api/v1/distribution/snapshots')).data }

// ── Payout modal ──────────────────────────────────────────────────────────────
const tenders = ref<{ id: number; name: string }[]>([])
const loadTenders = async () => {
    if (tenders.value.length) return
    const res = await api.get('/api/v1/payment-tenders/all')
    tenders.value = res.data
}

const payoutModal = ref<{ open: boolean; snapshot: any | null; tenderId: number | ''; notes: string; loading: boolean }>({
    open: false, snapshot: null, tenderId: '', notes: '', loading: false,
})

const openPayoutModal = async (snapshot: any) => {
    await loadTenders()
    payoutModal.value = { open: true, snapshot, tenderId: '', notes: '', loading: false }
}

const submitPayout = async () => {
    if (!payoutModal.value.tenderId) { toast.error('Please select a tender.'); return }
    payoutModal.value.loading = true
    try {
        await api.post(`/api/v1/distribution/snapshots/${payoutModal.value.snapshot.id}/payout`, {
            tender_id: payoutModal.value.tenderId,
            notes: payoutModal.value.notes || null,
        })
        toast.success('Payout recorded successfully.')
        payoutModal.value.open = false
        loadSnapshots()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to record payout.')
    } finally {
        payoutModal.value.loading = false
    }
}

// ── Tab activation ────────────────────────────────────────────────────────────
watch(subTab, (t) => {
    if (t === 'shareholders') loadShareholders()
    else if (t === 'incentives') { loadIncentiveRules(); loadProductOwnerships(); loadShareholders() }
    else if (t === 'trends') loadTrends()
    else if (t === 'history') loadSnapshots()
})

onMounted(loadPreview)

const tabs = [
    { key: 'distribution', label: 'Distribution', icon: PieChart },
    { key: 'shareholders', label: 'Shareholders', icon: Users },
    { key: 'incentives',   label: 'Incentives',   icon: Gift },
    { key: 'trends',       label: 'Trends',        icon: TrendingUp },
    { key: 'history',      label: 'History',       icon: History },
    { key: 'help',         label: 'Help',          icon: HelpCircle },
] as const
</script>

<template>
    <Head title="Profit Sharing" />

    <div class="w-full space-y-4">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <PieChart class="h-6 w-6 text-primary" />
                <h1 class="text-xl font-black">Profit Distribution</h1>
            </div>
            <button @click="subTab = 'help'" class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium text-muted-foreground hover:bg-muted transition">
                <HelpCircle class="h-4 w-4" /> How it works
            </button>
        </div>

        <!-- Sub-tabs -->
        <div class="flex gap-1 overflow-x-auto border-b">
            <button v-for="t in tabs" :key="t.key" @click="subTab = t.key"
                :class="['flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border-b-2 whitespace-nowrap transition',
                    subTab === t.key ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground']">
                <component :is="t.icon" class="h-4 w-4" /> {{ t.label }}
            </button>
        </div>

        <!-- ── DISTRIBUTION ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'distribution'">
            <!-- Filters -->
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="space-y-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="text-xs font-medium text-muted-foreground block mb-1">Basis</label>
                            <div class="flex rounded-lg border overflow-hidden">
                                <button @click="basis = 'sales'; loadPreview()" :class="['px-3 py-2 text-sm font-semibold', basis === 'sales' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">Sales</button>
                                <button @click="basis = 'profit'; loadPreview()" :class="['px-3 py-2 text-sm font-semibold', basis === 'profit' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">Profit</button>
                            </div>
                        </div>
                        <div><label class="text-xs font-medium text-muted-foreground block mb-1">From</label><input v-model="startDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                        <div><label class="text-xs font-medium text-muted-foreground block mb-1">To</label><input v-model="endDate" type="date" class="rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                        <div class="w-full sm:w-auto"><label class="text-xs font-medium text-muted-foreground block mb-1">Category</label>
                            <select v-model="categoryId" class="w-full sm:w-auto rounded-lg border bg-background px-3 py-2 text-sm"><option value="">All</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                        <div class="w-full sm:w-auto"><label class="text-xs font-medium text-muted-foreground block mb-1">Product</label>
                            <select v-model="productId" class="w-full sm:w-auto rounded-lg border bg-background px-3 py-2 text-sm"><option value="">All</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                        <button @click="loadPreview" :disabled="loading" class="w-full sm:w-auto rounded-lg bg-primary px-5 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 flex items-center justify-center gap-1.5">
                            <RefreshCw v-if="loading" class="h-3.5 w-3.5 animate-spin" /><PieChart v-else class="h-3.5 w-3.5" /> Compute
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button @click="setWeek" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">This Week</button>
                        <button @click="setMonth" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">Month</button>
                        <button @click="setQuarter" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">Quarter</button>
                        <button @click="setYear" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">Year</button>
                        <button @click="exportCsv" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"><Download class="h-3 w-3" /> CSV</button>
                        <button @click="saveSnapshot" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"><Save class="h-3 w-3" /> Snapshot</button>
                    </div>
                </div>
            </div>

            <template v-if="result">
                <!-- Financial Summary -->
                <div v-if="result.financial_summary" class="rounded-xl border bg-card shadow-sm p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Financial Summary — {{ result.financial_summary.period_end }}</p>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        <div class="space-y-0.5">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Gross Sales</p>
                            <p class="text-base font-bold">{{ fmt(result.financial_summary.gross_sales) }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Refunds</p>
                            <p class="text-base font-bold text-red-500">−{{ fmt(result.financial_summary.refunds) }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Net Sales</p>
                            <p class="text-base font-bold text-blue-600">{{ fmt(result.financial_summary.net_sales) }}</p>
                        </div>
                        <div v-if="(result.financial_summary.income_adjustments ?? 0) !== 0" class="space-y-0.5" title="Manual 'Other Income' entries from the Financial module — added to net profit.">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Other Income</p>
                            <p class="text-base font-bold text-teal-600">+{{ fmt(result.financial_summary.income_adjustments) }}</p>
                        </div>
                        <div v-if="(result.financial_summary.expenses ?? 0) !== 0" class="space-y-0.5" title="Operating expenses (incl. paid bills) from the Financial module — deducted from net profit.">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Expenses</p>
                            <p class="text-base font-bold text-red-500">−{{ fmt(result.financial_summary.expenses) }}</p>
                        </div>
                        <div v-if="(result.financial_summary.payroll ?? 0) !== 0" class="space-y-0.5" title="Payroll disbursements — deducted from net profit.">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Payroll</p>
                            <p class="text-base font-bold text-purple-600">−{{ fmt(result.financial_summary.payroll) }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground" title="Cash basis: only paid bills and expenses are deducted — upcoming or unpaid bills are not reflected until paid. Includes manual Other Income / Expenses / Payroll.">
                                Net Profit
                            </p>
                            <p class="text-base font-bold" :class="result.financial_summary.net_profit >= 0 ? 'text-emerald-600' : 'text-red-500'">{{ fmt(result.financial_summary.net_profit) }}</p>
                        </div>
                        <div class="space-y-0.5 border-l pl-3">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ result.basis === 'profit' ? 'Profit Margin' : 'Sales Basis' }}</p>
                            <p class="text-base font-bold text-primary">{{ result.basis === 'profit' ? (result.financial_summary.gross_sales > 0 ? ((result.financial_summary.net_profit / result.financial_summary.gross_sales) * 100).toFixed(1) + '%' : '—') : fmt(result.financial_summary.sales_base) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Flow KPIs -->
                <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ result.base_label }}</p><p class="text-xl font-black mt-1">{{ fmt(result.base_amount) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Distributable</p><p class="text-xl font-black mt-1 text-primary">{{ fmt(result.distributable) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm col-span-2 lg:col-span-1"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Company ({{ result.company_percentage }}%)</p><p class="text-xl font-black mt-1 text-emerald-600">{{ fmt(result.company_amount) }}</p></div>
                </div>

                <!-- Two-column: Dividend + Incentive -->
                <div class="grid lg:grid-cols-2 gap-4">
                    <!-- ── Ownership Dividend ── -->
                    <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                        <div class="p-4 border-b">
                            <div class="flex items-center gap-2 mb-0.5">
                                <h3 class="font-bold text-sm">Ownership Dividend</h3>
                                <span class="rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-2 py-0.5 text-xs font-semibold">{{ result.members_percentage }}% of Distributable</span>
                            </div>
                            <p class="text-xs text-muted-foreground">Based on ownership %. Company keeps its {{ result.company_percentage }}%.</p>
                        </div>
                        <!-- Pie -->
                        <div class="p-4 border-b">
                            <apexchart v-if="pieSeries.length" type="pie" height="240" :options="pieOptions" :series="pieSeries" />
                            <p v-else class="text-sm text-muted-foreground text-center py-8">No distributable amount.</p>
                        </div>
                        <!-- Mobile cards -->
                        <div class="sm:hidden divide-y">
                            <div v-for="m in result.members" :key="m.shareholder_id" class="p-3 space-y-1.5">
                                <div class="flex justify-between"><span class="font-semibold text-sm">{{ m.name }}</span><span class="text-xs text-muted-foreground">{{ m.percentage }}%</span></div>
                                <div class="flex justify-between font-bold text-sm"><span>Dividend</span><span class="text-blue-600">{{ fmt(m.amount) }}</span></div>
                            </div>
                            <div class="p-3 bg-muted/30 flex justify-between font-bold text-sm"><span>Members total</span><span>{{ fmt(result.members_total) }}</span></div>
                            <div class="p-3 bg-emerald-50 dark:bg-emerald-950/20 flex justify-between font-bold text-sm text-emerald-700 dark:text-emerald-400"><span>Company ({{ result.company_percentage }}%)</span><span>{{ fmt(result.company_amount) }}</span></div>
                        </div>
                        <!-- Desktop table -->
                        <table class="hidden sm:table w-full text-sm">
                            <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                                <th class="px-4 py-2 text-left">Member</th><th class="px-4 py-2 text-right">Ownership</th>
                                <th class="px-4 py-2 text-right">Dividend</th>
                            </tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="m in result.members" :key="m.shareholder_id" class="hover:bg-muted/20">
                                    <td class="px-4 py-2 font-medium">{{ m.name }}</td>
                                    <td class="px-4 py-2 text-right">{{ m.percentage }}%</td>
                                    <td class="px-4 py-2 text-right font-bold text-blue-600">{{ fmt(m.amount) }}</td>
                                </tr>
                                <tr class="bg-muted/30 font-bold"><td class="px-4 py-2">Members total</td><td class="px-4 py-2 text-right">{{ result.members_percentage }}%</td><td class="px-4 py-2 text-right">{{ fmt(result.members_total) }}</td></tr>
                                <tr class="bg-emerald-50 dark:bg-emerald-950/20 font-bold text-emerald-700 dark:text-emerald-400"><td class="px-4 py-2">Company retained</td><td class="px-4 py-2 text-right">{{ result.company_percentage }}%</td><td class="px-4 py-2 text-right">{{ fmt(result.company_amount) }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Incentive (label + description differ by basis) ── -->
                    <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                        <div class="p-4 border-b">
                            <div class="flex items-center justify-between mb-0.5">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-bold text-sm">{{ result.basis === 'sales' ? 'Sales Incentive' : 'Product Ownership Incentive' }}</h3>
                                    <span class="rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 px-2 py-0.5 text-xs font-semibold">{{ fmt(result.incentive?.total ?? 0) }}</span>
                                </div>
                                <button @click="subTab = 'incentives'" class="text-xs text-muted-foreground hover:text-foreground underline">Manage</button>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="result.basis === 'sales'">Product sales attributed to shareholders by ownership %. Unowned product sales go to the company.</template>
                                <template v-else>Distributed proportionally by product sales, then split by product ownership %.</template>
                            </p>
                        </div>

                        <!-- No rules configured (both modes) -->
                        <div v-if="!result.incentive?.rules?.length" class="p-6 text-center">
                            <Gift class="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                            <p class="text-sm text-muted-foreground">
                                <template v-if="result.basis === 'sales'">No sales incentive rate configured.</template>
                                <template v-else>No active incentive rules.</template>
                            </p>
                            <button @click="subTab = 'incentives'" class="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90">
                                {{ result.basis === 'sales' ? 'Set up Sales Incentive Rate' : 'Set up Incentive Rules' }}
                            </button>
                        </div>

                        <template v-else>
                            <!-- Active rules summary -->
                            <div class="p-3 border-b space-y-1">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Active Rules</p>
                                <div v-for="r in result.incentive.rules" :key="r.id" class="flex items-center justify-between text-sm">
                                    <span class="text-muted-foreground text-xs">{{ r.name }}</span>
                                    <span class="font-bold text-amber-600 text-xs">
                                        <template v-if="r.pool_type === 'product_sales_pct'">{{ r.rate }}% per product</template>
                                        <template v-else-if="r.pool_type === 'fixed_amount'">₱{{ r.rate }} → {{ fmt(r.pool_amount) }}</template>
                                        <template v-else>{{ r.rate }}% → {{ fmt(r.pool_amount) }}</template>
                                    </span>
                                </div>
                            </div>

                            <!-- Incentive pie chart -->
                            <div v-if="incentivePieData" class="p-4 border-b">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide mb-2">Incentive Distribution</p>
                                <apexchart type="pie" height="220" :options="incentivePieOptions" :series="incentivePieSeries" />
                            </div>

                            <!-- No product breakdown when profit mode has rules but no sales -->
                            <div v-if="!result.incentive.by_product?.length" class="p-6 text-center">
                                <Package class="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                                <p class="text-sm text-muted-foreground">No product sales in this period.</p>
                            </div>

                            <!-- Product breakdown (mobile) -->
                            <div v-else class="sm:hidden divide-y max-h-80 overflow-y-auto">
                                <div v-for="p in result.incentive.by_product" :key="p.product_id" class="p-3 space-y-1.5">
                                    <div class="flex justify-between items-start">
                                        <span class="font-semibold text-sm leading-tight">{{ p.product_name }}</span>
                                        <span class="font-bold text-amber-600 text-sm shrink-0 ml-2">{{ fmt(p.product_incentive) }}</span>
                                    </div>
                                    <div class="flex justify-between text-xs text-muted-foreground">
                                        <span>Sales: {{ fmt(p.sales_amount) }}</span>
                                        <span v-if="p.contribution_pct">{{ p.contribution_pct }}% {{ result.basis === 'sales' ? 'of owned sales' : 'of pool' }}</span>
                                    </div>
                                    <div v-if="p.owners.length" class="space-y-0.5">
                                        <div v-for="o in p.owners" :key="o.shareholder_id" class="flex justify-between text-xs">
                                            <span class="text-muted-foreground pl-2">→ {{ o.name }} ({{ o.ownership_pct }}%)</span>
                                            <span class="text-blue-600 font-medium">{{ fmt(o.amount) }}</span>
                                        </div>
                                    </div>
                                    <div v-else class="text-xs text-muted-foreground pl-2">→ Company (unowned)</div>
                                </div>
                            </div>

                            <!-- Product breakdown (desktop) -->
                            <div v-if="result.incentive.by_product?.length" class="hidden sm:block overflow-x-auto max-h-80 overflow-y-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase sticky top-0"><tr>
                                        <th class="px-3 py-2 text-left">Product</th>
                                        <th class="px-3 py-2 text-right">Sales</th>
                                        <th class="px-3 py-2 text-right">{{ result.basis === 'sales' ? '% of Owned' : 'Contrib %' }}</th>
                                        <th class="px-3 py-2 text-right">Incentive</th>
                                        <th class="px-3 py-2 text-left">Distribution</th>
                                    </tr></thead>
                                    <tbody class="divide-y">
                                        <tr v-for="p in result.incentive.by_product" :key="p.product_id" class="hover:bg-muted/20">
                                            <td class="px-3 py-2 font-medium max-w-[140px] truncate" :title="p.product_name">{{ p.product_name }}</td>
                                            <td class="px-3 py-2 text-right text-xs">{{ fmt(p.sales_amount) }}</td>
                                            <td class="px-3 py-2 text-right text-xs text-muted-foreground">{{ p.contribution_pct ? p.contribution_pct + '%' : '—' }}</td>
                                            <td class="px-3 py-2 text-right font-bold text-amber-600">{{ fmt(p.product_incentive) }}</td>
                                            <td class="px-3 py-2 text-xs">
                                                <span v-if="!p.owners.length" class="text-muted-foreground italic">Company</span>
                                                <span v-else>{{ p.owners.map((o: any) => o.name + ' ' + fmt(o.amount)).join(', ') }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Shareholder + company summary -->
                            <div v-if="result.incentive.by_shareholder?.length || result.incentive.company_retained > 0" class="border-t p-3 space-y-1.5">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">{{ result.basis === 'sales' ? 'Sales Incentive Summary' : 'Incentive Summary' }}</p>
                                <div v-for="s in result.incentive.by_shareholder" :key="s.shareholder_id" class="flex justify-between text-sm">
                                    <span class="text-muted-foreground">{{ s.name }}</span>
                                    <span class="font-bold text-blue-600">{{ fmt(s.incentive_amount) }}</span>
                                </div>
                                <div v-if="result.incentive.company_retained > 0" class="flex justify-between text-sm">
                                    <span class="text-muted-foreground">Company (unowned products)</span>
                                    <span class="font-bold text-emerald-600">{{ fmt(result.incentive.company_retained) }}</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Combined payout summary -->
                <div v-if="combinedSummary.length" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                    <div class="p-4 border-b">
                        <h3 class="font-bold text-sm">Total Payout per Shareholder</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Ownership dividend + sales incentive combined.</p>
                    </div>
                    <!-- Combined pie chart -->
                    <div v-if="combinedPieData" class="p-4 border-b">
                        <apexchart type="pie" height="260" :options="combinedPieOptions" :series="combinedPieSeries" />
                    </div>
                    <!-- Mobile cards -->
                    <div class="sm:hidden divide-y">
                        <div v-for="m in combinedSummary" :key="m.shareholder_id" class="p-3 space-y-1.5">
                            <div class="font-semibold text-sm">{{ m.name }}</div>
                            <div class="flex justify-between text-xs"><span class="text-muted-foreground">Dividend</span><span class="text-blue-600">{{ fmt(m.amount) }}</span></div>
                            <div class="flex justify-between text-xs"><span class="text-muted-foreground">Incentive</span><span class="text-amber-600">{{ fmt(m.incentive_amount) }}</span></div>
                            <div class="flex justify-between font-bold text-sm"><span>Total</span><span>{{ fmt(m.total_amount) }}</span></div>
                        </div>
                    </div>
                    <!-- Desktop table -->
                    <table class="hidden sm:table w-full text-sm">
                        <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                            <th class="px-4 py-2 text-left">Shareholder</th>
                            <th class="px-4 py-2 text-right">Ownership</th>
                            <th class="px-4 py-2 text-right text-blue-600">Dividend</th>
                            <th class="px-4 py-2 text-right text-amber-600">Incentive</th>
                            <th class="px-4 py-2 text-right">Total Payout</th>
                        </tr></thead>
                        <tbody class="divide-y">
                            <tr v-for="m in combinedSummary" :key="m.shareholder_id" class="hover:bg-muted/20">
                                <td class="px-4 py-2 font-medium">{{ m.name }}</td>
                                <td class="px-4 py-2 text-right text-muted-foreground">{{ m.percentage }}%</td>
                                <td class="px-4 py-2 text-right text-blue-600 font-medium">{{ fmt(m.amount) }}</td>
                                <td class="px-4 py-2 text-right text-amber-600 font-medium">{{ fmt(m.incentive_amount) }}</td>
                                <td class="px-4 py-2 text-right font-bold">{{ fmt(m.total_amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>

        <!-- ── SHAREHOLDERS ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'shareholders'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-bold text-sm">Ownership ({{ totalOwnership }}% allocated · Company keeps {{ companyPct }}%)</h3>
                </div>
                <div class="h-2 rounded-full bg-muted overflow-hidden mb-4 flex">
                    <div class="bg-primary h-full" :style="{ width: totalOwnership + '%' }"></div>
                    <div class="bg-emerald-400 h-full" :style="{ width: companyPct + '%' }"></div>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
                    <div><label class="text-xs text-muted-foreground block mb-1">Name *</label><input v-model="shForm.name" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Email</label><input v-model="shForm.email" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Ownership %</label><input v-model="shForm.ownership_percentage" type="number" step="0.01" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Status</label><select v-model="shForm.status" class="w-full rounded-lg border bg-background px-3 py-2 text-sm"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                    <div class="flex gap-2">
                        <button @click="saveSh" :disabled="shSaving || !shForm.name" class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">{{ shForm.id ? 'Update' : 'Add' }}</button>
                        <button v-if="shForm.id" @click="resetShForm" class="rounded-lg border px-3 py-2"><X class="h-4 w-4" /></button>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <!-- Mobile cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="s in shareholders" :key="s.id" class="p-3 space-y-1.5">
                        <div class="flex justify-between items-start">
                            <span class="font-semibold text-sm">{{ s.name }}</span>
                            <span :class="['rounded-full px-2 py-0.5 text-xs', s.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ s.status }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">{{ s.email ?? '—' }}</div>
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-bold">{{ s.ownership_percentage }}%</span>
                            <div class="flex gap-1">
                                <button @click="editSh(s)" class="p-1.5 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteSh(s)" class="p-1.5 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>
                    <div v-if="!shareholders.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No shareholders yet.</div>
                </div>
                <!-- Desktop table -->
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                        <th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-left">Email</th>
                        <th class="px-4 py-2 text-right">Ownership</th><th class="px-4 py-2 text-center">Status</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="s in shareholders" :key="s.id" class="hover:bg-muted/20">
                            <td class="px-4 py-2 font-medium">{{ s.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ s.email ?? '—' }}</td>
                            <td class="px-4 py-2 text-right font-bold">{{ s.ownership_percentage }}%</td>
                            <td class="px-4 py-2 text-center"><span :class="['rounded-full px-2 py-0.5 text-xs', s.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ s.status }}</span></td>
                            <td class="px-4 py-2 text-right">
                                <button @click="editSh(s)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteSh(s)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!shareholders.length"><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No shareholders yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── INCENTIVES ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'incentives'">
            <!-- Info banner -->
            <div class="rounded-xl border bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800 p-4 text-sm text-amber-900 dark:text-amber-200">
                <strong>How it works:</strong> Set an incentive rate (e.g., 2% of gross sales). Each product's share of the pool is proportional to its sales. That share is then split among the product's assigned owners by their ownership %.
                Unowned products' incentives stay with the company. This is independent of the ownership dividend.
            </div>

            <!-- Section 1: Incentive Rate Rules -->
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-3">{{ iForm.id ? 'Edit' : 'Add' }} Incentive Rate Rule</h3>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div class="lg:col-span-2"><label class="text-xs text-muted-foreground block mb-1">Rule Name *</label><input v-model="iForm.name" placeholder="e.g. Monthly Product Incentive" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Pool Type</label>
                        <select v-model="iForm.pool_type" class="w-full rounded-lg border bg-background px-3 py-2 text-sm">
                            <optgroup label="Sales Mode">
                                <option value="product_sales_pct">% of Each Product's Sales</option>
                            </optgroup>
                            <optgroup label="Profit Mode">
                                <option value="gross_sales_pct">% of Gross Sales</option>
                                <option value="gross_profit_pct">% of Gross Profit</option>
                                <option value="net_profit_pct">% of Net Profit</option>
                                <option value="fixed_amount">Fixed ₱ Amount</option>
                            </optgroup>
                        </select></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Rate ({{ poolTypeUnit(iForm.pool_type) }}) *</label>
                        <input v-model="iForm.rate" type="number" step="0.01" :placeholder="iForm.pool_type === 'fixed_amount' ? '5000' : '2.0'" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Effective Date *</label><input v-model="iForm.effective_date" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Expires (optional)</label><input v-model="iForm.expiration_date" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Notes</label><input v-model="iForm.notes" placeholder="Optional" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div class="flex items-end gap-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <div class="relative"><input type="checkbox" v-model="iForm.is_active" class="sr-only peer" /><div class="w-9 h-5 bg-muted rounded-full peer peer-checked:bg-primary transition"></div><div class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition peer-checked:translate-x-4"></div></div>
                            <span class="text-sm">Active</span>
                        </label>
                    </div>
                    <div class="flex items-end gap-2">
                        <button @click="saveIncentive" :disabled="iSaving || !iForm.name || !iForm.rate" class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">{{ iForm.id ? 'Update' : 'Add Rule' }}</button>
                        <button v-if="iForm.id" @click="resetIForm" class="rounded-lg border px-3 py-2"><X class="h-4 w-4" /></button>
                    </div>
                </div>
            </div>
            <!-- Active rules table -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="sm:hidden divide-y">
                    <div v-for="r in incentiveRules" :key="r.id" :class="['p-3 space-y-1.5', !r.is_active && 'opacity-50']">
                        <div class="flex justify-between items-start">
                            <span class="font-semibold text-sm">{{ r.name }}</span>
                            <span class="text-xs font-bold text-amber-600">{{ r.pool_type === 'fixed_amount' ? fmt(r.rate) : r.rate + '% of ' + poolTypeLabel(r.pool_type).replace('% of ', '') }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">{{ poolTypeLabel(r.pool_type) }}</div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-muted-foreground">{{ r.effective_date?.slice(0,10) }} → {{ r.expiration_date?.slice(0,10) ?? '∞' }}</span>
                            <div class="flex gap-1">
                                <button @click="editIncentive(r)" class="p-1.5 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteIncentive(r)" class="p-1.5 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>
                    <div v-if="!incentiveRules.length" class="px-4 py-6 text-center text-muted-foreground text-sm">No incentive rules yet.</div>
                </div>
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                        <th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-right">Rate</th>
                        <th class="px-4 py-2 text-left">Window</th><th class="px-4 py-2 text-center">Active</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="r in incentiveRules" :key="r.id" :class="['hover:bg-muted/20', !r.is_active && 'opacity-50']">
                            <td class="px-4 py-2 font-medium">{{ r.name }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ poolTypeLabel(r.pool_type) }}</td>
                            <td class="px-4 py-2 text-right font-bold text-amber-600">{{ r.pool_type === 'fixed_amount' ? fmt(r.rate) : r.rate + '%' }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ r.effective_date?.slice(0,10) }} → {{ r.expiration_date?.slice(0,10) ?? '∞' }}</td>
                            <td class="px-4 py-2 text-center"><span :class="['rounded-full px-2 py-0.5 text-xs', r.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ r.is_active ? 'Yes' : 'No' }}</span></td>
                            <td class="px-4 py-2 text-right">
                                <button @click="editIncentive(r)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteIncentive(r)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!incentiveRules.length"><td colspan="6" class="px-4 py-6 text-center text-muted-foreground">No incentive rules yet.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Section 2: Product Ownership -->
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b">
                    <div class="flex items-center gap-2 mb-1">
                        <Package class="h-4 w-4 text-primary" />
                        <h3 class="font-bold text-sm">Product Ownership</h3>
                    </div>
                    <p class="text-xs text-muted-foreground">Assign shareholders as product owners. Ownership % per product must total 100%. Products without owners are company-owned.</p>
                </div>

                <!-- Search -->
                <div class="p-3 border-b">
                    <input v-model="productFilter" placeholder="Search products…" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" />
                </div>

                <!-- Edit panel -->
                <div v-if="editProductId !== null" class="p-4 border-b bg-amber-50 dark:bg-amber-950/20">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="font-semibold text-sm">Editing: <span class="text-primary">{{ editProductName }}</span></h4>
                        <button @click="editProductId = null" class="p-1 rounded hover:bg-muted"><X class="h-4 w-4" /></button>
                    </div>
                    <!-- Owner rows -->
                    <div class="space-y-2 mb-3">
                        <div v-for="(row, i) in editOwnerRows" :key="i" class="flex items-center gap-2">
                            <select v-model="row.shareholder_id" class="flex-1 min-w-0 rounded-lg border bg-background px-3 py-2 text-sm">
                                <option value="">Select shareholder…</option>
                                <option v-for="s in shareholders" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                            <div class="relative w-24 shrink-0">
                                <input v-model="row.ownership_percentage" type="number" step="0.01" min="0.01" max="100" placeholder="%" class="w-full rounded-lg border bg-background px-3 py-2 text-sm text-right pr-6" />
                                <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">%</span>
                            </div>
                            <button @click="removeOwnerRow(i)" class="p-1.5 text-muted-foreground hover:text-red-600 shrink-0"><Trash2 class="h-4 w-4" /></button>
                        </div>
                        <div v-if="!editOwnerRows.length" class="text-sm text-muted-foreground italic">No owners — saving will make this product company-owned.</div>
                    </div>
                    <!-- Footer -->
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <div class="flex items-center gap-3">
                            <button @click="addOwnerRow" class="rounded-lg border px-3 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1.5"><Plus class="h-3 w-3" /> Add Owner</button>
                            <span v-if="editOwnerRows.length" :class="['text-xs font-bold tabular-nums', Math.abs(ownerTotalPct - 100) < 0.01 ? 'text-emerald-600' : 'text-amber-600']">
                                {{ ownerTotalPct.toFixed(1) }}% / 100%
                            </span>
                        </div>
                        <div class="flex gap-2">
                            <button @click="editProductId = null" class="rounded-lg border px-3 py-1.5 text-xs">Cancel</button>
                            <button @click="saveProductOwnership" :disabled="productOwnerSaving || (editOwnerRows.length > 0 && (Math.abs(ownerTotalPct - 100) > 0.01 || editOwnerRows.some(r => !r.shareholder_id)))"
                                class="rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">
                                {{ productOwnerSaving ? 'Saving…' : 'Save' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Mobile product cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="p in filteredProducts" :key="p.product_id" :class="['p-3 space-y-1.5', editProductId === p.product_id && 'bg-muted/30']">
                        <div class="flex justify-between items-start gap-2">
                            <span class="font-semibold text-sm leading-tight">{{ p.product_name }}</span>
                            <div class="flex gap-1 shrink-0">
                                <button @click="startEditProduct(p)" class="p-1.5 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button v-if="p.owners.length" @click="clearProductOwnership(p.product_id, p.product_name)" class="p-1.5 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>
                        <div v-if="p.owners.length" class="space-y-0.5">
                            <div v-for="o in p.owners" :key="o.shareholder_id" class="text-xs text-muted-foreground">
                                {{ o.name }} — {{ o.ownership_percentage }}%
                            </div>
                            <span :class="['text-xs font-bold', p.total_percentage === 100 ? 'text-emerald-600' : 'text-amber-600']">Total: {{ p.total_percentage }}%</span>
                        </div>
                        <div v-else class="text-xs text-muted-foreground italic">Company owned</div>
                    </div>
                    <div v-if="!filteredProducts.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No products found.</div>
                </div>

                <!-- Desktop product table -->
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                        <th class="px-4 py-2 text-left">Product</th>
                        <th class="px-4 py-2 text-left">Owners</th>
                        <th class="px-4 py-2 text-right">Total %</th>
                        <th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="p in filteredProducts" :key="p.product_id" :class="['hover:bg-muted/20', editProductId === p.product_id && 'bg-muted/30']">
                            <td class="px-4 py-2 font-medium">{{ p.product_name }}</td>
                            <td class="px-4 py-2 text-xs">
                                <span v-if="!p.owners.length" class="text-muted-foreground italic">Company owned</span>
                                <span v-else>{{ p.owners.map((o: any) => o.name + ' ' + o.ownership_percentage + '%').join(' · ') }}</span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <span v-if="p.owners.length" :class="['text-xs font-bold', p.total_percentage === 100 ? 'text-emerald-600' : 'text-amber-600']">{{ p.total_percentage }}%</span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <button @click="startEditProduct(p)" class="p-1 text-muted-foreground hover:text-blue-600" title="Edit owners"><Pencil class="h-4 w-4" /></button>
                                <button v-if="p.owners.length" @click="clearProductOwnership(p.product_id, p.product_name)" class="p-1 text-muted-foreground hover:text-red-600" title="Remove all owners"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!filteredProducts.length"><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">No products found.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── TRENDS ───────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'trends'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-2 flex items-center gap-2"><TrendingUp class="h-4 w-4 text-primary" /> Monthly Distribution Trend (this year)</h3>
                <apexchart v-if="trend.length" type="line" height="320" :options="trendOptions" :series="trendSeries" />
                <p v-else class="text-sm text-muted-foreground text-center py-10">No data.</p>
            </div>
        </template>

        <!-- ── HISTORY ──────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'history'">
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b"><h3 class="font-bold text-sm">Distribution Snapshots</h3></div>

                <!-- Mobile cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="s in snapshots" :key="s.id" class="p-3 space-y-1.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-muted-foreground">{{ s.period_start?.slice(0,10) }} → {{ s.period_end?.slice(0,10) }}</span>
                            <span class="text-xs capitalize bg-muted px-2 py-0.5 rounded-full">{{ s.distribution_basis }}</span>
                        </div>
                        <div class="flex justify-between text-sm"><span class="text-muted-foreground">Distributable</span><span class="font-bold">{{ fmt(s.distributable_amount) }}</span></div>
                        <div class="flex justify-between text-xs"><span class="text-muted-foreground">Members</span><span>{{ fmt(s.members_amount) }}</span></div>
                        <div class="flex justify-between text-xs"><span class="text-muted-foreground">Company</span><span class="text-emerald-600 font-medium">{{ fmt(s.company_amount) }}</span></div>
                        <div class="text-xs text-muted-foreground">By: {{ s.creator?.name ?? '—' }}</div>
                        <!-- Payout status -->
                        <div v-if="s.paid_at" class="flex items-center gap-1 text-xs text-emerald-600 font-medium">
                            <CheckCircle2 class="h-3 w-3" /> Paid {{ s.paid_at?.slice(0,10) }} by {{ s.payer?.name ?? '—' }}
                        </div>
                        <button v-else @click="openPayoutModal(s)"
                            class="flex items-center gap-1.5 text-xs font-semibold text-primary border border-primary/40 rounded-lg px-2.5 py-1 hover:bg-primary/5 transition-colors">
                            <Banknote class="h-3 w-3" /> Record Payout
                        </button>
                    </div>
                    <div v-if="!snapshots.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No snapshots saved yet.</div>
                </div>

                <!-- Desktop table -->
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase">
                        <tr>
                            <th class="px-4 py-2 text-left">Period</th>
                            <th class="px-4 py-2 text-left">Basis</th>
                            <th class="px-4 py-2 text-right">Distributable</th>
                            <th class="px-4 py-2 text-right">Members</th>
                            <th class="px-4 py-2 text-right">Company</th>
                            <th class="px-4 py-2 text-left">By</th>
                            <th class="px-4 py-2 text-left">Payout</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="s in snapshots" :key="s.id" class="hover:bg-muted/20">
                            <td class="px-4 py-2 whitespace-nowrap">{{ s.period_start?.slice(0,10) }} → {{ s.period_end?.slice(0,10) }}</td>
                            <td class="px-4 py-2 capitalize">{{ s.distribution_basis }}</td>
                            <td class="px-4 py-2 text-right font-bold">{{ fmt(s.distributable_amount) }}</td>
                            <td class="px-4 py-2 text-right">{{ fmt(s.members_amount) }}</td>
                            <td class="px-4 py-2 text-right text-emerald-600">{{ fmt(s.company_amount) }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ s.creator?.name ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <div v-if="s.paid_at" class="flex items-center gap-1 text-xs text-emerald-600 font-medium whitespace-nowrap">
                                    <CheckCircle2 class="h-3.5 w-3.5 shrink-0" />
                                    {{ s.paid_at?.slice(0,10) }} · {{ s.payer?.name ?? '—' }}
                                </div>
                                <button v-else @click="openPayoutModal(s)"
                                    class="flex items-center gap-1 text-xs font-semibold text-primary border border-primary/40 rounded-lg px-2.5 py-1 hover:bg-primary/5 transition-colors whitespace-nowrap">
                                    <Banknote class="h-3 w-3" /> Record Payout
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!snapshots.length"><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No snapshots saved yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── PAYOUT MODAL ───────────────────────────────────────────────────── -->
        <div v-if="payoutModal.open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="payoutModal.open = false">
            <div class="w-full max-w-md rounded-2xl bg-background shadow-2xl p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="font-bold text-base flex items-center gap-2"><Banknote class="h-4 w-4 text-primary" /> Record Payout</h2>
                    <button @click="payoutModal.open = false" class="text-muted-foreground hover:text-foreground"><X class="h-4 w-4" /></button>
                </div>

                <!-- Snapshot summary -->
                <div class="rounded-xl bg-muted/40 p-3 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-muted-foreground">Period</span><span class="font-medium">{{ payoutModal.snapshot?.period_start?.slice(0,10) }} → {{ payoutModal.snapshot?.period_end?.slice(0,10) }}</span></div>
                    <div class="border-t pt-2 space-y-1.5">
                        <div class="flex justify-between items-start">
                            <span class="text-muted-foreground">Members (to be disbursed)</span>
                            <span class="font-bold text-primary">{{ fmt(payoutModal.snapshot?.members_amount) }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">One cash transaction per shareholder will be recorded.</p>
                    </div>
                    <div class="border-t pt-2 space-y-1.5">
                        <div class="flex justify-between items-start">
                            <span class="text-muted-foreground">Company (retained in business)</span>
                            <span class="font-medium text-emerald-600">{{ fmt(payoutModal.snapshot?.company_amount) }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">No cash disbursed — stays as retained earnings.</p>
                    </div>
                </div>

                <!-- Tender select -->
                <div>
                    <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide block mb-1.5">Payout Tender / Method</label>
                    <select v-model="payoutModal.tenderId"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary">
                        <option value="" disabled>Select tender…</option>
                        <option v-for="t in tenders" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                </div>

                <!-- Notes -->
                <div>
                    <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wide block mb-1.5">Notes <span class="font-normal">(optional)</span></label>
                    <textarea v-model="payoutModal.notes" rows="2"
                        placeholder="e.g. Cash payout during partner meeting"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary resize-none" />
                </div>

                <div class="flex gap-2 pt-1">
                    <button @click="payoutModal.open = false"
                        class="flex-1 rounded-lg border px-4 py-2 text-sm font-semibold hover:bg-muted transition-colors">
                        Cancel
                    </button>
                    <button @click="submitPayout" :disabled="payoutModal.loading || !payoutModal.tenderId"
                        class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50 transition-colors flex items-center justify-center gap-2">
                        <RefreshCw v-if="payoutModal.loading" class="h-3.5 w-3.5 animate-spin" />
                        <Banknote v-else class="h-3.5 w-3.5" />
                        Disburse to Members
                    </button>
                </div>
            </div>
        </div>

        <!-- ── HELP ───────────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'help'">
            <div class="grid lg:grid-cols-2 gap-4">
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3 lg:col-span-2">
                    <h3 class="font-bold text-base flex items-center gap-2"><HelpCircle class="h-5 w-5 text-primary" /> Two Independent Calculations</h3>
                    <p class="text-sm text-muted-foreground leading-relaxed">Ownership dividends and product ownership incentives are completely separate — they never interfere with each other.</p>
                    <div class="grid sm:grid-cols-2 gap-3 mt-2">
                        <div class="rounded-lg bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 p-3">
                            <p class="font-bold text-sm text-blue-700 dark:text-blue-400 mb-1">Ownership Dividend</p>
                            <p class="text-xs text-blue-800/80 dark:text-blue-300/80">Shareholders receive their equity % of the distributable pool. Company gets the remainder.</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 p-3">
                            <p class="font-bold text-sm text-amber-700 dark:text-amber-400 mb-1">Product Ownership Incentive</p>
                            <p class="text-xs text-amber-800/80 dark:text-amber-300/80">A separate pool (e.g. 2% of gross sales). Each product earns a share proportional to its sales. That share is split among the product's assigned owners. Unowned products' share stays with the company.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
                    <h3 class="font-bold text-sm">Worked example</h3>
                    <div class="overflow-x-auto">
                        <table class="text-sm min-w-[360px]">
                            <tbody class="[&_td]:py-1 [&_td]:pr-4">
                                <tr><td class="text-muted-foreground">Total Gross Sales</td><td class="font-bold text-right">₱1,000,000</td></tr>
                                <tr><td class="text-muted-foreground">Incentive Rate</td><td class="font-bold text-right">2%</td></tr>
                                <tr class="border-t"><td class="text-muted-foreground">Incentive Pool</td><td class="font-bold text-right text-amber-600">₱20,000</td></tr>
                                <tr class="border-t"><td colspan="2" class="text-xs font-semibold text-muted-foreground pt-2">Classic Burger — Sales ₱250,000 (25%)</td></tr>
                                <tr><td class="text-muted-foreground pl-2">Product Incentive</td><td class="text-right">₱5,000</td></tr>
                                <tr><td class="pl-4 text-muted-foreground">Shareholder A (60%)</td><td class="text-right text-amber-600">₱3,000</td></tr>
                                <tr><td class="pl-4 text-muted-foreground">Shareholder B (40%)</td><td class="text-right text-amber-600">₱2,000</td></tr>
                                <tr class="border-t"><td colspan="2" class="text-xs font-semibold text-muted-foreground pt-2">Fries — Sales ₱100,000 (10%, no owners)</td></tr>
                                <tr><td class="pl-2 text-muted-foreground">Product Incentive → Company</td><td class="text-right text-emerald-600">₱2,000</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
                    <h3 class="font-bold text-sm">Setup steps</h3>
                    <ol class="text-sm space-y-2 list-decimal list-inside text-muted-foreground">
                        <li><strong class="text-foreground">Add shareholders</strong> — go to Shareholders tab and enter each shareholder's name and equity %.</li>
                        <li><strong class="text-foreground">Set incentive rate</strong> — go to Incentives tab, add a rule (e.g. "2% of Gross Sales").</li>
                        <li><strong class="text-foreground">Assign product owners</strong> — in the same Incentives tab, find each product and click Edit to assign shareholders with ownership percentages (must sum to 100%).</li>
                        <li><strong class="text-foreground">Compute</strong> — go to Distribution tab and click Compute for your chosen period.</li>
                        <li><strong class="text-foreground">Snapshot</strong> — click Snapshot before paying out to create a permanent record.</li>
                    </ol>
                </div>

                <div class="rounded-xl border bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800 p-5 space-y-2 lg:col-span-2">
                    <h3 class="font-bold text-sm text-amber-800 dark:text-amber-300">Tips</h3>
                    <ul class="text-sm space-y-1 text-amber-800/90 dark:text-amber-300/90 list-disc list-inside">
                        <li>Use <strong>This Week</strong> shortcut for weekly settlements, <strong>Month</strong> for monthly.</li>
                        <li>Only <strong>paid</strong> orders count — matching your Financial reports.</li>
                        <li>A product can be owned by multiple shareholders as long as the percentages total exactly 100%.</li>
                        <li>Products with no assigned owners are automatically company-owned (their incentive share is retained).</li>
                        <li>Multiple incentive rules stack — their pools are added together before distribution.</li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>
