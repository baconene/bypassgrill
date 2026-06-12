<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    PieChart, Users, Percent, History, TrendingUp, RefreshCw, Plus, Trash2, Pencil,
    Download, Save, X, HelpCircle, Gift, ChevronDown,
} from 'lucide-vue-next'

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Profit Sharing', href: '/distribution' }] } })

const props = defineProps<{
    categories: { id: number; name: string }[]
    products: { id: number; name: string; category_id: number }[]
    users: { id: number; name: string }[]
}>()

// ── Shared filters ──────────────────────────────────────────────────────────
const today = new Date().toISOString().split('T')[0]
const monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0]
const basis = ref<'sales' | 'profit' | 'hybrid'>('sales')
const startDate = ref(monthStart)
const endDate = ref(today)
const categoryId = ref<number | ''>('')
const productId = ref<number | ''>('')

const subTab = ref<'distribution' | 'shareholders' | 'incentives' | 'royalties' | 'trends' | 'history' | 'help'>('distribution')

const fmt = (v: number | null | undefined) => '₱' + (v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const pct = (v: number | null | undefined) => ((v ?? 0).toFixed(1)) + '%'

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

// ── Pie chart ─────────────────────────────────────────────────────────────────
const pieSeries = computed(() => (result.value?.chart ?? []).map((c: any) => c.value))
const pieOptions = computed(() => ({
    chart: { type: 'pie' },
    labels: (result.value?.chart ?? []).map((c: any) => c.label),
    legend: { position: 'bottom' },
    colors: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#14b8a6', '#6b7280'],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: { y: { formatter: (val: number) => '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } },
}))

// ── Shareholders ──────────────────────────────────────────────────────────────
const shareholders = ref<any[]>([])
const totalOwnership = ref(0)
const companyPct = ref(100)
const shForm = ref<any>({ id: null, name: '', email: '', user_id: '', ownership_percentage: '', status: 'active', notes: '' })
const shSaving = ref(false)

const loadShareholders = async () => {
    const res = await api.get('/api/v1/shareholders')
    shareholders.value = res.data.shareholders
    totalOwnership.value = res.data.total_ownership
    companyPct.value = res.data.company_percentage
}
const editSh     = (s: any) => { shForm.value = { ...s, user_id: s.user_id ?? '' } }
const resetShForm = () => { shForm.value = { id: null, name: '', email: '', user_id: '', ownership_percentage: '', status: 'active', notes: '' } }
const saveSh = async () => {
    shSaving.value = true
    try {
        const payload = { ...shForm.value, ownership_percentage: parseFloat(shForm.value.ownership_percentage) || 0, user_id: shForm.value.user_id || null }
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

// ── Royalty rules ─────────────────────────────────────────────────────────────
const rules = ref<any[]>([])
const rForm = ref<any>({ id: null, scope: 'product', product_id: '', category_id: '', recipient_name: '', shareholder_id: '', royalty_percentage: '', effective_date: today, expiration_date: '', is_active: true })
const rSaving = ref(false)

const loadRules   = async () => { rules.value = (await api.get('/api/v1/royalty-rules')).data }
const editRule    = (r: any) => { rForm.value = { ...r, product_id: r.product_id ?? '', category_id: r.category_id ?? '', shareholder_id: r.shareholder_id ?? '', expiration_date: r.expiration_date ?? '' } }
const resetRForm  = () => { rForm.value = { id: null, scope: 'product', product_id: '', category_id: '', recipient_name: '', shareholder_id: '', royalty_percentage: '', effective_date: today, expiration_date: '', is_active: true } }
const saveRule = async () => {
    rSaving.value = true
    try {
        const payload = { ...rForm.value, royalty_percentage: parseFloat(rForm.value.royalty_percentage) || 0, product_id: rForm.value.product_id || null, category_id: rForm.value.category_id || null, shareholder_id: rForm.value.shareholder_id || null, expiration_date: rForm.value.expiration_date || null }
        if (rForm.value.id) await api.put(`/api/v1/royalty-rules/${rForm.value.id}`, payload)
        else await api.post('/api/v1/royalty-rules', payload)
        toast.success('Royalty rule saved'); resetRForm(); await loadRules()
    } catch (err: any) {
        toast.error(Object.values(err.response?.data?.errors ?? {}).flat().join(' ') || err.response?.data?.message || 'Failed to save')
    } finally { rSaving.value = false }
}
const deleteRule = async (r: any) => {
    if (!confirm(`Delete royalty rule for ${r.recipient_name}?`)) return
    await api.delete(`/api/v1/royalty-rules/${r.id}`); toast.success('Deleted'); await loadRules()
}

// ── Incentive rules ───────────────────────────────────────────────────────────
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
    gross_sales_pct: '% of Gross Sales', gross_profit_pct: '% of Gross Profit',
    net_profit_pct: '% of Net Profit', fixed_amount: 'Fixed ₱ Amount',
}[t] ?? t)

const poolTypeUnit = (t: string) => t === 'fixed_amount' ? '₱' : '%'

// ── Trends ────────────────────────────────────────────────────────────────────
const trend = ref<any[]>([])
const royaltyAnalytics = ref<any>(null)

const loadTrends = async () => {
    const yearStart = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]
    const [t, r] = await Promise.all([
        api.get('/api/v1/distribution/trend', { params: { basis: basis.value, start_date: yearStart, end_date: today } }),
        api.get('/api/v1/distribution/royalty-analytics', { params: params() }),
    ])
    trend.value = t.data; royaltyAnalytics.value = r.data
}
const trendSeries = computed(() => ([
    { name: 'Dividend', data: trend.value.map((t: any) => t.members) },
    { name: 'Incentive', data: trend.value.map((t: any) => t.incentive ?? 0) },
    { name: 'Company', data: trend.value.map((t: any) => t.company) },
    { name: 'Royalties', data: trend.value.map((t: any) => t.royalty) },
]))
const trendOptions = computed(() => ({
    chart: { type: 'line', toolbar: { show: false } },
    stroke: { width: 2, curve: 'smooth' },
    xaxis: { categories: trend.value.map((t: any) => t.month) },
    colors: ['#3b82f6', '#f59e0b', '#10b981', '#8b5cf6'],
    yaxis: { labels: { formatter: (v: number) => '₱' + (v / 1000).toFixed(0) + 'K' } },
    legend: { position: 'top' },
}))

// ── Snapshots history ─────────────────────────────────────────────────────────
const snapshots = ref<any[]>([])
const loadSnapshots = async () => { snapshots.value = (await api.get('/api/v1/distribution/snapshots')).data }

// ── Tab activation ────────────────────────────────────────────────────────────
watch(subTab, (t) => {
    if (t === 'shareholders') loadShareholders()
    else if (t === 'royalties') { loadRules(); loadShareholders() }
    else if (t === 'incentives') { loadIncentiveRules(); loadShareholders() }
    else if (t === 'trends') loadTrends()
    else if (t === 'history') loadSnapshots()
})

onMounted(loadPreview)

const tabs = [
    { key: 'distribution', label: 'Distribution', icon: PieChart },
    { key: 'shareholders', label: 'Shareholders', icon: Users },
    { key: 'incentives',   label: 'Incentives',   icon: Gift },
    { key: 'royalties',    label: 'Royalties',     icon: Percent },
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

        <!-- ── DISTRIBUTION ─────────────────────────────────────────────── -->
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
                                <button @click="basis = 'hybrid'; loadPreview()" :class="['px-3 py-2 text-sm font-semibold', basis === 'hybrid' ? 'bg-primary text-primary-foreground' : 'hover:bg-muted']">Hybrid</button>
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
                        <span v-if="result.basis === 'hybrid'" class="rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400 px-2.5 py-0.5 text-xs font-semibold">Hybrid</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                        <div class="space-y-0.5"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Gross Sales</p><p class="text-base font-bold">{{ fmt(result.financial_summary.gross_sales) }}</p></div>
                        <div class="space-y-0.5"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Refunds</p><p class="text-base font-bold text-red-500">−{{ fmt(result.financial_summary.refunds) }}</p></div>
                        <div class="space-y-0.5"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Net Sales</p><p class="text-base font-bold text-blue-600">{{ fmt(result.financial_summary.net_sales) }}</p></div>
                        <div class="space-y-0.5"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">COGS</p><p class="text-base font-bold text-orange-500">−{{ fmt(result.financial_summary.cogs) }}</p></div>
                        <div class="space-y-0.5"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Net Profit</p><p class="text-base font-bold" :class="result.financial_summary.net_profit >= 0 ? 'text-emerald-600' : 'text-red-500'">{{ fmt(result.financial_summary.net_profit) }}</p></div>
                        <div class="space-y-0.5 border-l pl-3"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ result.basis === 'profit' ? 'Profit Margin' : 'Sales Base' }}</p><p class="text-base font-bold text-primary">{{ result.basis === 'profit' && result.financial_summary.gross_sales > 0 ? ((result.financial_summary.net_profit / result.financial_summary.gross_sales) * 100).toFixed(1) + '%' : fmt(result.financial_summary.sales_base) }}</p></div>
                    </div>
                </div>

                <!-- Flow KPIs -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ result.base_label }}</p><p class="text-xl font-black mt-1">{{ fmt(result.base_amount) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Royalties</p><p class="text-xl font-black mt-1 text-amber-600">−{{ fmt(result.royalty.total) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Distributable</p><p class="text-xl font-black mt-1 text-primary">{{ fmt(result.distributable) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Company ({{ result.company_percentage }}%)</p><p class="text-xl font-black mt-1 text-emerald-600">{{ fmt(result.company_amount) }}</p></div>
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
                            <p class="text-xs text-muted-foreground">Based on ownership %. Does not affect company retention.</p>
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
                                <div v-if="result.basis === 'hybrid'" class="flex justify-between text-xs text-muted-foreground"><span>Profit share</span><span>{{ fmt(m.profit_share) }}</span></div>
                                <div v-if="result.basis === 'hybrid'" class="flex justify-between text-xs"><span class="text-muted-foreground">Royalties</span><span class="text-amber-600">{{ m.royalty_amount > 0 ? fmt(m.royalty_amount) : '—' }}</span></div>
                                <div class="flex justify-between font-bold text-sm"><span>Dividend</span><span class="text-blue-600">{{ fmt(m.amount) }}</span></div>
                            </div>
                            <div class="p-3 bg-muted/30 flex justify-between font-bold text-sm"><span>Members total</span><span>{{ fmt(result.members_total) }}</span></div>
                            <div class="p-3 bg-emerald-50 dark:bg-emerald-950/20 flex justify-between font-bold text-sm text-emerald-700 dark:text-emerald-400"><span>Company ({{ result.company_percentage }}%)</span><span>{{ fmt(result.company_amount) }}</span></div>
                        </div>
                        <!-- Desktop table -->
                        <table class="hidden sm:table w-full text-sm">
                            <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                                <th class="px-4 py-2 text-left">Member</th><th class="px-4 py-2 text-right">%</th>
                                <th v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right">Profit Share</th>
                                <th v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right text-amber-600">Royalties</th>
                                <th class="px-4 py-2 text-right">Dividend</th>
                            </tr></thead>
                            <tbody class="divide-y">
                                <tr v-for="m in result.members" :key="m.shareholder_id" class="hover:bg-muted/20">
                                    <td class="px-4 py-2 font-medium">{{ m.name }}</td>
                                    <td class="px-4 py-2 text-right">{{ m.percentage }}%</td>
                                    <td v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right text-muted-foreground">{{ fmt(m.profit_share) }}</td>
                                    <td v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right text-amber-600">{{ m.royalty_amount > 0 ? fmt(m.royalty_amount) : '—' }}</td>
                                    <td class="px-4 py-2 text-right font-bold text-blue-600">{{ fmt(m.amount) }}</td>
                                </tr>
                                <tr class="bg-muted/30 font-bold"><td class="px-4 py-2">Members total</td><td class="px-4 py-2 text-right">{{ result.members_percentage }}%</td><td v-if="result.basis === 'hybrid'" colspan="2"></td><td class="px-4 py-2 text-right">{{ fmt(result.members_total) }}</td></tr>
                                <tr class="bg-emerald-50 dark:bg-emerald-950/20 font-bold text-emerald-700 dark:text-emerald-400"><td class="px-4 py-2">Company retained</td><td class="px-4 py-2 text-right">{{ result.company_percentage }}%</td><td v-if="result.basis === 'hybrid'" colspan="2"></td><td class="px-4 py-2 text-right">{{ fmt(result.company_amount) }}</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- ── Sales Incentive Pool ── -->
                    <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                        <div class="p-4 border-b">
                            <div class="flex items-center justify-between mb-0.5">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-bold text-sm">Sales Incentive Pool</h3>
                                    <span class="rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 px-2 py-0.5 text-xs font-semibold">{{ fmt(result.incentive?.total ?? 0) }}</span>
                                </div>
                                <button @click="subTab = 'incentives'" class="text-xs text-muted-foreground hover:text-foreground underline">Manage rules</button>
                            </div>
                            <p class="text-xs text-muted-foreground">Separate from dividend — distributed by individual sales contribution.</p>
                        </div>
                        <!-- No incentive rules -->
                        <div v-if="!result.incentive?.rules?.length" class="p-6 text-center">
                            <Gift class="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                            <p class="text-sm text-muted-foreground">No active incentive rules.</p>
                            <button @click="subTab = 'incentives'" class="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90">Set up Incentive Rules</button>
                        </div>
                        <template v-else>
                            <!-- Incentive rule breakdown -->
                            <div class="p-4 border-b space-y-2">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wide">Active Rules</p>
                                <div v-for="r in result.incentive.rules" :key="r.id" class="flex items-center justify-between text-sm">
                                    <div>
                                        <span class="font-medium">{{ r.name }}</span>
                                        <span class="ml-2 text-xs text-muted-foreground">({{ r.pool_type === 'fixed_amount' ? '₱' + r.rate : r.rate + '% of ' + poolTypeLabel(r.pool_type).replace('% of ', '') }})</span>
                                    </div>
                                    <span class="font-bold text-amber-600">{{ fmt(r.pool_amount) }}</span>
                                </div>
                            </div>
                            <!-- No linked shareholders -->
                            <div v-if="!result.incentive.by_shareholder?.length" class="p-6 text-center">
                                <p class="text-sm text-muted-foreground">No shareholders have a linked POS user yet.</p>
                                <p class="text-xs text-muted-foreground mt-1">Link shareholders to their POS accounts in the <button @click="subTab = 'shareholders'" class="underline">Shareholders</button> tab.</p>
                            </div>
                            <!-- Per-shareholder incentive (mobile cards) -->
                            <div v-else class="sm:hidden divide-y">
                                <div v-for="s in result.incentive.by_shareholder" :key="s.shareholder_id" class="p-3 space-y-1">
                                    <div class="flex justify-between"><span class="font-semibold text-sm">{{ s.name }}</span><span class="font-bold text-amber-600">{{ fmt(s.incentive_amount) }}</span></div>
                                    <div class="flex justify-between text-xs text-muted-foreground"><span>Sales: {{ fmt(s.sales_amount) }}</span><span>{{ s.sales_pct }}% of tracked</span></div>
                                </div>
                                <div class="p-3 bg-muted/30 flex justify-between font-bold text-sm"><span>Incentive total</span><span class="text-amber-600">{{ fmt(result.incentive.total) }}</span></div>
                            </div>
                            <!-- Desktop table -->
                            <table v-if="result.incentive.by_shareholder?.length" class="hidden sm:table w-full text-sm">
                                <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                                    <th class="px-4 py-2 text-left">Shareholder</th>
                                    <th class="px-4 py-2 text-right">Sales</th>
                                    <th class="px-4 py-2 text-right">Share %</th>
                                    <th class="px-4 py-2 text-right">Incentive</th>
                                </tr></thead>
                                <tbody class="divide-y">
                                    <tr v-for="s in result.incentive.by_shareholder" :key="s.shareholder_id" class="hover:bg-muted/20">
                                        <td class="px-4 py-2">
                                            <div class="font-medium">{{ s.name }}</div>
                                            <div class="text-xs text-muted-foreground">{{ s.user_name }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-right">{{ fmt(s.sales_amount) }}</td>
                                        <td class="px-4 py-2 text-right text-muted-foreground">{{ s.sales_pct }}%</td>
                                        <td class="px-4 py-2 text-right font-bold text-amber-600">{{ fmt(s.incentive_amount) }}</td>
                                    </tr>
                                    <tr class="bg-muted/30 font-bold">
                                        <td class="px-4 py-2">Pool total</td>
                                        <td class="px-4 py-2 text-right text-muted-foreground text-xs">{{ fmt(result.incentive.total_linked_sales) }} tracked</td>
                                        <td></td>
                                        <td class="px-4 py-2 text-right text-amber-600">{{ fmt(result.incentive.total) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </div>

                <!-- Combined payout summary -->
                <div v-if="combinedSummary.length" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                    <div class="p-4 border-b">
                        <h3 class="font-bold text-sm">Total Payout per Shareholder</h3>
                        <p class="text-xs text-muted-foreground mt-0.5">Ownership dividend + sales incentive combined.</p>
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

                <!-- Royalty recipients -->
                <div v-if="result.royalty?.by_recipient?.length" class="rounded-xl border bg-card shadow-sm p-4">
                    <h3 class="font-bold text-sm mb-2">Royalty Recipients</h3>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="r in result.royalty.by_recipient" :key="r.recipient_name" class="rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 px-3 py-1 text-sm font-medium">{{ r.recipient_name }}: {{ fmt(r.amount) }}</span>
                    </div>
                </div>
            </template>
        </template>

        <!-- ── SHAREHOLDERS ─────────────────────────────────────────────── -->
        <template v-if="subTab === 'shareholders'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="font-bold text-sm">Ownership ({{ totalOwnership }}% allocated · Company keeps {{ companyPct }}%)</h3>
                </div>
                <div class="h-2 rounded-full bg-muted overflow-hidden mb-4 flex">
                    <div class="bg-primary h-full" :style="{ width: totalOwnership + '%' }"></div>
                    <div class="bg-emerald-400 h-full" :style="{ width: companyPct + '%' }"></div>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                    <div><label class="text-xs text-muted-foreground block mb-1">Name *</label><input v-model="shForm.name" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Email</label><input v-model="shForm.email" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Ownership %</label><input v-model="shForm.ownership_percentage" type="number" step="0.01" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">POS User (for incentives)</label>
                        <select v-model="shForm.user_id" class="w-full rounded-lg border bg-background px-3 py-2 text-sm">
                            <option value="">None</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select></div>
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
                        <div class="text-xs text-muted-foreground" v-if="s.user">POS: {{ s.user.name }}</div>
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
                        <th class="px-4 py-2 text-left">POS User</th>
                        <th class="px-4 py-2 text-right">Ownership</th><th class="px-4 py-2 text-center">Status</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="s in shareholders" :key="s.id" class="hover:bg-muted/20">
                            <td class="px-4 py-2 font-medium">{{ s.name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ s.email ?? '—' }}</td>
                            <td class="px-4 py-2 text-muted-foreground">
                                <span v-if="s.user" class="rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-2 py-0.5 text-xs">{{ s.user.name }}</span>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td class="px-4 py-2 text-right font-bold">{{ s.ownership_percentage }}%</td>
                            <td class="px-4 py-2 text-center"><span :class="['rounded-full px-2 py-0.5 text-xs', s.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ s.status }}</span></td>
                            <td class="px-4 py-2 text-right">
                                <button @click="editSh(s)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteSh(s)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!shareholders.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No shareholders yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── INCENTIVES ───────────────────────────────────────────────── -->
        <template v-if="subTab === 'incentives'">
            <div class="rounded-xl border bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800 p-4 text-sm text-amber-900 dark:text-amber-200">
                <strong>What is the incentive pool?</strong> A separate pool (e.g. 2% of weekly gross sales) that is distributed to shareholders based on their individual sales contribution. It does not reduce the company's ownership share — it is recorded as a business expense.
            </div>
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-3">{{ iForm.id ? 'Edit' : 'Add' }} Incentive Rule</h3>
                <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div class="lg:col-span-2"><label class="text-xs text-muted-foreground block mb-1">Rule Name *</label><input v-model="iForm.name" placeholder="e.g. Weekly Sales Incentive" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Pool Type</label>
                        <select v-model="iForm.pool_type" class="w-full rounded-lg border bg-background px-3 py-2 text-sm">
                            <option value="gross_sales_pct">% of Gross Sales</option>
                            <option value="gross_profit_pct">% of Gross Profit</option>
                            <option value="net_profit_pct">% of Net Profit</option>
                            <option value="fixed_amount">Fixed ₱ Amount</option>
                        </select></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Rate ({{ poolTypeUnit(iForm.pool_type) }}) *</label>
                        <input v-model="iForm.rate" type="number" step="0.01" :placeholder="iForm.pool_type === 'fixed_amount' ? '5000' : '2.0'" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Distribution Method</label>
                        <select v-model="iForm.distribution_method" class="w-full rounded-lg border bg-background px-3 py-2 text-sm">
                            <option value="by_sales">By Individual Sales</option>
                            <option value="equal">Equal Split</option>
                        </select></div>
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
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <!-- Mobile cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="r in incentiveRules" :key="r.id" :class="['p-3 space-y-1.5', !r.is_active && 'opacity-50']">
                        <div class="flex justify-between items-start">
                            <span class="font-semibold text-sm">{{ r.name }}</span>
                            <span class="text-xs font-bold text-amber-600">{{ r.pool_type === 'fixed_amount' ? fmt(r.rate) : r.rate + '% of ' + poolTypeLabel(r.pool_type).replace('% of ', '') }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">{{ poolTypeLabel(r.pool_type) }} · {{ r.distribution_method === 'by_sales' ? 'By sales' : 'Equal split' }}</div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-muted-foreground">{{ r.effective_date?.slice(0,10) }} → {{ r.expiration_date?.slice(0,10) ?? '∞' }}</span>
                            <div class="flex gap-1">
                                <button @click="editIncentive(r)" class="p-1.5 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteIncentive(r)" class="p-1.5 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>
                    <div v-if="!incentiveRules.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No incentive rules yet.</div>
                </div>
                <!-- Desktop table -->
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr>
                        <th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-left">Distribution</th>
                        <th class="px-4 py-2 text-left">Window</th><th class="px-4 py-2 text-center">Active</th><th class="px-4 py-2"></th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="r in incentiveRules" :key="r.id" :class="['hover:bg-muted/20', !r.is_active && 'opacity-50']">
                            <td class="px-4 py-2 font-medium">{{ r.name }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ poolTypeLabel(r.pool_type) }}</td>
                            <td class="px-4 py-2 text-right font-bold text-amber-600">{{ r.pool_type === 'fixed_amount' ? fmt(r.rate) : r.rate + '%' }}</td>
                            <td class="px-4 py-2 text-xs">{{ r.distribution_method === 'by_sales' ? 'By Sales' : 'Equal Split' }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ r.effective_date?.slice(0,10) }} → {{ r.expiration_date?.slice(0,10) ?? '∞' }}</td>
                            <td class="px-4 py-2 text-center"><span :class="['rounded-full px-2 py-0.5 text-xs', r.is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ r.is_active ? 'Yes' : 'No' }}</span></td>
                            <td class="px-4 py-2 text-right">
                                <button @click="editIncentive(r)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteIncentive(r)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!incentiveRules.length"><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No incentive rules yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── ROYALTIES ────────────────────────────────────────────────── -->
        <template v-if="subTab === 'royalties'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-3">{{ rForm.id ? 'Edit' : 'Add' }} Royalty Rule</h3>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div><label class="text-xs text-muted-foreground block mb-1">Scope</label><select v-model="rForm.scope" class="w-full rounded-lg border bg-background px-3 py-2 text-sm"><option value="product">Product</option><option value="category">Category</option></select></div>
                    <div v-if="rForm.scope === 'product'"><label class="text-xs text-muted-foreground block mb-1">Product *</label><select v-model="rForm.product_id" class="w-full rounded-lg border bg-background px-3 py-2 text-sm"><option value="">Select…</option><option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option></select></div>
                    <div v-else><label class="text-xs text-muted-foreground block mb-1">Category *</label><select v-model="rForm.category_id" class="w-full rounded-lg border bg-background px-3 py-2 text-sm"><option value="">Select…</option><option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Recipient *</label><input v-model="rForm.recipient_name" placeholder="e.g. Brand Owner" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Link to member</label><select v-model="rForm.shareholder_id" class="w-full rounded-lg border bg-background px-3 py-2 text-sm"><option value="">None</option><option v-for="s in shareholders" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Royalty %</label><input v-model="rForm.royalty_percentage" type="number" step="0.01" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Effective</label><input v-model="rForm.effective_date" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div><label class="text-xs text-muted-foreground block mb-1">Expires (optional)</label><input v-model="rForm.expiration_date" type="date" class="w-full rounded-lg border bg-background px-3 py-2 text-sm" /></div>
                    <div class="flex items-end gap-2">
                        <button @click="saveRule" :disabled="rSaving || !rForm.recipient_name" class="flex-1 rounded-lg bg-primary px-3 py-2 text-sm font-bold text-primary-foreground hover:bg-primary/90 disabled:opacity-50">{{ rForm.id ? 'Update' : 'Add Rule' }}</button>
                        <button v-if="rForm.id" @click="resetRForm" class="rounded-lg border px-3 py-2"><X class="h-4 w-4" /></button>
                    </div>
                </div>
            </div>
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <!-- Mobile cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="r in rules" :key="r.id" :class="['p-3 space-y-1.5', !r.is_active && 'opacity-50']">
                        <div class="flex justify-between items-start">
                            <div><span class="font-semibold text-sm">{{ r.recipient_name }}</span><span v-if="r.shareholder" class="text-xs text-muted-foreground"> ({{ r.shareholder.name }})</span></div>
                            <span class="text-sm font-bold text-amber-600">{{ r.royalty_percentage }}%</span>
                        </div>
                        <div class="text-xs text-muted-foreground capitalize">{{ r.scope }}: {{ r.product?.name ?? r.category?.name ?? '—' }}</div>
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-muted-foreground">{{ r.effective_date?.slice(0,10) }} → {{ r.expiration_date?.slice(0,10) ?? '∞' }}</span>
                            <div class="flex gap-1">
                                <button @click="editRule(r)" class="p-1.5 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteRule(r)" class="p-1.5 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </div>
                        </div>
                    </div>
                    <div v-if="!rules.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No royalty rules yet.</div>
                </div>
                <!-- Desktop table -->
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr><th class="px-4 py-2 text-left">Scope</th><th class="px-4 py-2 text-left">Target</th><th class="px-4 py-2 text-left">Recipient</th><th class="px-4 py-2 text-right">%</th><th class="px-4 py-2 text-left">Window</th><th class="px-4 py-2"></th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="r in rules" :key="r.id" :class="['hover:bg-muted/20', !r.is_active && 'opacity-50']">
                            <td class="px-4 py-2 capitalize">{{ r.scope }}</td>
                            <td class="px-4 py-2">{{ r.product?.name ?? r.category?.name ?? '—' }}</td>
                            <td class="px-4 py-2">{{ r.recipient_name }}<span v-if="r.shareholder" class="text-xs text-muted-foreground"> ({{ r.shareholder.name }})</span></td>
                            <td class="px-4 py-2 text-right font-bold">{{ r.royalty_percentage }}%</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ r.effective_date?.slice(0,10) }} → {{ r.expiration_date?.slice(0,10) ?? '∞' }}</td>
                            <td class="px-4 py-2 text-right">
                                <button @click="editRule(r)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button>
                                <button @click="deleteRule(r)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                        <tr v-if="!rules.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No royalty rules yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── TRENDS ───────────────────────────────────────────────────── -->
        <template v-if="subTab === 'trends'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-2 flex items-center gap-2"><TrendingUp class="h-4 w-4 text-primary" /> Monthly Distribution Trend (this year)</h3>
                <apexchart v-if="trend.length" type="line" height="320" :options="trendOptions" :series="trendSeries" />
                <p v-else class="text-sm text-muted-foreground text-center py-10">No data.</p>
            </div>
            <div v-if="royaltyAnalytics" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between"><h3 class="font-bold text-sm">Top Royalty Products</h3><span class="text-sm font-bold text-amber-600">Total: {{ fmt(royaltyAnalytics.total) }}</span></div>
                <div class="sm:hidden divide-y">
                    <div v-for="p in royaltyAnalytics.by_product" :key="p.name" class="p-3 space-y-1">
                        <div class="font-semibold text-sm">{{ p.name }}</div>
                        <div class="flex justify-between text-xs text-muted-foreground"><span>Net Sales</span><span>{{ fmt(p.net_sales) }}</span></div>
                        <div class="flex justify-between text-sm"><span class="text-muted-foreground">Rate</span><span class="font-bold text-amber-600">{{ p.rate }}% → {{ fmt(p.royalty) }}</span></div>
                    </div>
                    <div v-if="!royaltyAnalytics.by_product.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No royalties in range.</div>
                </div>
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr><th class="px-4 py-2 text-left">Product</th><th class="px-4 py-2 text-right">Net Sales</th><th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-right">Royalty</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="p in royaltyAnalytics.by_product" :key="p.name" class="hover:bg-muted/20"><td class="px-4 py-2 font-medium">{{ p.name }}</td><td class="px-4 py-2 text-right">{{ fmt(p.net_sales) }}</td><td class="px-4 py-2 text-right">{{ p.rate }}%</td><td class="px-4 py-2 text-right font-bold text-amber-600">{{ fmt(p.royalty) }}</td></tr>
                        <tr v-if="!royaltyAnalytics.by_product.length"><td colspan="4" class="px-4 py-8 text-center text-muted-foreground">No royalties in range.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── HISTORY ──────────────────────────────────────────────────── -->
        <template v-if="subTab === 'history'">
            <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b"><h3 class="font-bold text-sm">Distribution Snapshots</h3></div>
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
                    </div>
                    <div v-if="!snapshots.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No snapshots saved yet.</div>
                </div>
                <table class="hidden sm:table w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr><th class="px-4 py-2 text-left">Period</th><th class="px-4 py-2 text-left">Basis</th><th class="px-4 py-2 text-right">Distributable</th><th class="px-4 py-2 text-right">Members</th><th class="px-4 py-2 text-right">Company</th><th class="px-4 py-2 text-left">By</th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="s in snapshots" :key="s.id" class="hover:bg-muted/20">
                            <td class="px-4 py-2 whitespace-nowrap">{{ s.period_start?.slice(0,10) }} → {{ s.period_end?.slice(0,10) }}</td>
                            <td class="px-4 py-2 capitalize">{{ s.distribution_basis }}</td>
                            <td class="px-4 py-2 text-right font-bold">{{ fmt(s.distributable_amount) }}</td>
                            <td class="px-4 py-2 text-right">{{ fmt(s.members_amount) }}</td>
                            <td class="px-4 py-2 text-right text-emerald-600">{{ fmt(s.company_amount) }}</td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">{{ s.creator?.name ?? '—' }}</td>
                        </tr>
                        <tr v-if="!snapshots.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No snapshots saved yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── HELP ─────────────────────────────────────────────────────── -->
        <template v-if="subTab === 'help'">
            <div class="grid lg:grid-cols-2 gap-4">
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3 lg:col-span-2">
                    <h3 class="font-bold text-base flex items-center gap-2"><HelpCircle class="h-5 w-5 text-primary" /> Three Independent Calculations</h3>
                    <p class="text-sm text-muted-foreground leading-relaxed">The profit sharing system separates <strong>ownership dividends</strong>, <strong>sales incentives</strong>, and <strong>company retention</strong> so they never interfere with each other. Nothing here changes your accounting — it only reads existing sales and financial records.</p>
                    <div class="grid sm:grid-cols-3 gap-3 mt-2">
                        <div class="rounded-lg bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 p-3">
                            <p class="font-bold text-sm text-blue-700 dark:text-blue-400 mb-1">Ownership Dividend</p>
                            <p class="text-xs text-blue-800/80 dark:text-blue-300/80">Shareholders receive their ownership % of the distributable pool (base amount minus royalties). Company gets the remaining %.</p>
                        </div>
                        <div class="rounded-lg bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800 p-3">
                            <p class="font-bold text-sm text-amber-700 dark:text-amber-400 mb-1">Sales Incentive Pool</p>
                            <p class="text-xs text-amber-800/80 dark:text-amber-300/80">A configurable pool (e.g. 2% of sales) distributed by each shareholder's actual sales. Separate from the dividend — recorded as a business expense.</p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800 p-3">
                            <p class="font-bold text-sm text-emerald-700 dark:text-emerald-400 mb-1">Company Retention</p>
                            <p class="text-xs text-emerald-800/80 dark:text-emerald-300/80">Whatever ownership % is not allocated to shareholders stays with the company as retained earnings. E.g. 4 × 10% shareholders → 60% company.</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
                    <h3 class="font-bold text-sm">Worked example (weekly)</h3>
                    <div class="overflow-x-auto">
                        <table class="text-sm min-w-[380px]">
                            <tbody class="[&_td]:py-1 [&_td]:pr-6">
                                <tr><td class="text-muted-foreground">Gross Sales</td><td class="font-bold text-right">₱500,000</td></tr>
                                <tr><td class="text-muted-foreground">Incentive Pool (2%)</td><td class="font-bold text-right text-amber-600">₱10,000 (expense)</td></tr>
                                <tr><td class="text-muted-foreground">Net Profit (after all expenses)</td><td class="font-bold text-right">₱290,000</td></tr>
                                <tr class="border-t"><td class="text-muted-foreground">Distributable (profit basis)</td><td class="font-bold text-right text-primary">₱290,000</td></tr>
                                <tr><td class="pl-4">Member A — 10%</td><td class="text-right text-blue-600">₱29,000</td></tr>
                                <tr><td class="pl-4">Member B — 10%</td><td class="text-right text-blue-600">₱29,000</td></tr>
                                <tr><td class="pl-4">Member C — 10%</td><td class="text-right text-blue-600">₱29,000</td></tr>
                                <tr><td class="pl-4">Member D — 10%</td><td class="text-right text-blue-600">₱29,000</td></tr>
                                <tr class="border-t"><td class="text-emerald-600">Company retained (60%)</td><td class="font-bold text-right text-emerald-600">₱174,000</td></tr>
                                <tr class="border-t pt-2"><td class="text-muted-foreground">Incentive Pool</td><td class="font-bold text-right text-amber-600">₱10,000</td></tr>
                                <tr><td class="pl-4">A (sold 40%)</td><td class="text-right text-amber-600">₱4,000</td></tr>
                                <tr><td class="pl-4">B (sold 30%)</td><td class="text-right text-amber-600">₱3,000</td></tr>
                                <tr><td class="pl-4">C (sold 20%)</td><td class="text-right text-amber-600">₱2,000</td></tr>
                                <tr><td class="pl-4">D (sold 10%)</td><td class="text-right text-amber-600">₱1,000</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
                    <h3 class="font-bold text-sm">Setting up incentives</h3>
                    <ol class="text-sm space-y-2 list-decimal list-inside text-muted-foreground">
                        <li><strong class="text-foreground">Link shareholders to POS users</strong> — go to Shareholders tab, select each shareholder's POS account. Their sales will be tracked automatically.</li>
                        <li><strong class="text-foreground">Create incentive rules</strong> — go to Incentives tab. Choose a pool type (% of gross sales is most common for a restaurant) and set the rate.</li>
                        <li><strong class="text-foreground">Compute the distribution</strong> — come back to the Distribution tab and click Compute. You'll see both the dividend and incentive side by side.</li>
                        <li><strong class="text-foreground">Save a snapshot</strong> — click Snapshot before paying out. This stores a permanent record for accounting.</li>
                    </ol>
                </div>

                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-2">
                    <h3 class="font-bold text-sm">Incentive pool types</h3>
                    <div class="space-y-2 text-sm">
                        <p><span class="font-semibold text-amber-600">% of Gross Sales</span> — pool = gross sales × rate. Simple and predictable. Best for most food businesses.</p>
                        <p><span class="font-semibold text-amber-600">% of Gross Profit</span> — pool = (net sales − COGS) × rate. Rewards selling high-margin items.</p>
                        <p><span class="font-semibold text-amber-600">% of Net Profit</span> — pool = net profit × rate. Only pays out when the business is profitable.</p>
                        <p><span class="font-semibold text-amber-600">Fixed Amount</span> — pool = ₱X per period. Simple and does not fluctuate with sales volume.</p>
                    </div>
                </div>

                <div class="rounded-xl border bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800 p-5 space-y-2 lg:col-span-2">
                    <h3 class="font-bold text-sm text-amber-800 dark:text-amber-300">Tips</h3>
                    <ul class="text-sm space-y-1 text-amber-800/90 dark:text-amber-300/90 list-disc list-inside">
                        <li>Use <strong>This Week</strong> shortcut for weekly payouts, <strong>Month</strong> for monthly.</li>
                        <li>Only <strong>paid</strong> orders count toward sales — matching your Financial reports.</li>
                        <li>Incentives are attributed to whichever POS user processed the order. Link each shareholder to their POS account in Shareholders.</li>
                        <li>Multiple incentive rules stack — the pools are added together then distributed.</li>
                        <li>Use the <strong>Basis</strong> toggle to decide whether dividends come from total sales or net profit.</li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>
