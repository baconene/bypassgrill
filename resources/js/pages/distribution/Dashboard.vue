<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import api from '@/utils/api'
import {
    PieChart, Users, Percent, History, TrendingUp, RefreshCw, Plus, Trash2, Pencil,
    Download, Save, X, HelpCircle,
} from 'lucide-vue-next'

defineOptions({ layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }, { title: 'Profit Sharing', href: '/distribution' }] } })

const props = defineProps<{ categories: { id: number; name: string }[]; products: { id: number; name: string; category_id: number }[] }>()

// ── Shared filters ──────────────────────────────────────────────────────────
const today = new Date().toISOString().split('T')[0]
const monthStart = new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().split('T')[0]
const basis = ref<'sales' | 'profit' | 'hybrid'>('sales')
const startDate = ref(monthStart)
const endDate = ref(today)
const categoryId = ref<number | ''>('')
const productId = ref<number | ''>('')
const shareholderId = ref<number | ''>('')

const subTab = ref<'distribution' | 'shareholders' | 'royalties' | 'trends' | 'history' | 'help'>('distribution')

const fmt = (v: number) => '₱' + (v ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// ── Distribution preview ────────────────────────────────────────────────────
const result = ref<any>(null)
const loading = ref(false)

// In hybrid mode only show royalty recipients that are NOT linked to a shareholder
// (linked ones already appear as the Royalties column inside the member table)
const visibleRoyaltyRecipients = computed(() => {
    if (!result.value?.royalty?.by_recipient) return []
    if (result.value.basis === 'hybrid') {
        return result.value.royalty.by_recipient.filter((r: any) => !r.shareholder_id)
    }
    return result.value.royalty.by_recipient
})

const params = () => ({
    basis: basis.value, start_date: startDate.value, end_date: endDate.value,
    category_id: categoryId.value || undefined, product_id: productId.value || undefined,
    shareholder_id: shareholderId.value || undefined,
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

const setMonth = () => { startDate.value = monthStart; endDate.value = today; loadPreview() }
const setQuarter = () => {
    const q = Math.floor(new Date().getMonth() / 3)
    startDate.value = new Date(new Date().getFullYear(), q * 3, 1).toISOString().split('T')[0]
    endDate.value = today; loadPreview()
}
const setYear = () => { startDate.value = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0]; endDate.value = today; loadPreview() }

const exportCsv = () => {
    const qs = new URLSearchParams(params() as any).toString()
    window.open(`/api/v1/distribution/export?${qs}`, '_blank')
}

const saveSnapshot = async () => {
    try {
        await api.post('/api/v1/distribution/snapshots', params())
        toast.success('Snapshot saved to history')
        if (subTab.value === 'history') loadSnapshots()
    } catch (err: any) {
        toast.error(err.response?.data?.message ?? 'Failed to save snapshot')
    }
}

// ── Pie chart ───────────────────────────────────────────────────────────────
const pieSeries = computed(() => (result.value?.chart ?? []).map((c: any) => c.value))
const pieOptions = computed(() => ({
    chart: { type: 'pie' },
    labels: (result.value?.chart ?? []).map((c: any) => c.label),
    legend: { position: 'bottom' },
    colors: ['#3b82f6', '#8b5cf6', '#10b981', '#f59e0b', '#ec4899', '#14b8a6', '#6b7280'],
    dataLabels: { formatter: (val: number) => val.toFixed(1) + '%' },
    tooltip: { y: { formatter: (val: number) => '₱' + val.toLocaleString('en-PH', { minimumFractionDigits: 2 }) } },
}))

// ── Shareholders CRUD ───────────────────────────────────────────────────────
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
const editSh = (s: any) => { shForm.value = { ...s } }
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

// ── Royalty rules CRUD ──────────────────────────────────────────────────────
const rules = ref<any[]>([])
const rForm = ref<any>({ id: null, scope: 'product', product_id: '', category_id: '', recipient_name: '', shareholder_id: '', royalty_percentage: '', effective_date: today, expiration_date: '', is_active: true })
const rSaving = ref(false)

const loadRules = async () => { rules.value = (await api.get('/api/v1/royalty-rules')).data }
const editRule = (r: any) => { rForm.value = { ...r, product_id: r.product_id ?? '', category_id: r.category_id ?? '', shareholder_id: r.shareholder_id ?? '', expiration_date: r.expiration_date ?? '' } }
const resetRForm = () => { rForm.value = { id: null, scope: 'product', product_id: '', category_id: '', recipient_name: '', shareholder_id: '', royalty_percentage: '', effective_date: today, expiration_date: '', is_active: true } }
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

// ── Trends + royalty analytics ──────────────────────────────────────────────
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
    { name: 'Members', data: trend.value.map(t => t.members) },
    { name: 'Company', data: trend.value.map(t => t.company) },
    { name: 'Royalties', data: trend.value.map(t => t.royalty) },
]))
const trendOptions = computed(() => ({
    chart: { type: 'line', toolbar: { show: false } },
    stroke: { width: 2, curve: 'smooth' },
    xaxis: { categories: trend.value.map(t => t.month) },
    colors: ['#3b82f6', '#10b981', '#f59e0b'],
    yaxis: { labels: { formatter: (v: number) => '₱' + (v / 1000).toFixed(0) + 'K' } },
    legend: { position: 'top' },
}))

// ── Snapshots history ───────────────────────────────────────────────────────
const snapshots = ref<any[]>([])
const loadSnapshots = async () => { snapshots.value = (await api.get('/api/v1/distribution/snapshots')).data }

// ── Tab activation ──────────────────────────────────────────────────────────
watch(subTab, (t) => {
    if (t === 'shareholders') loadShareholders()
    else if (t === 'royalties') { loadRules(); loadShareholders() }
    else if (t === 'trends') loadTrends()
    else if (t === 'history') loadSnapshots()
})

onMounted(loadPreview)

const tabs = [
    { key: 'distribution', label: 'Distribution', icon: PieChart },
    { key: 'shareholders', label: 'Shareholders', icon: Users },
    { key: 'royalties', label: 'Royalties', icon: Percent },
    { key: 'trends', label: 'Trends', icon: TrendingUp },
    { key: 'history', label: 'History', icon: History },
    { key: 'help', label: 'Help', icon: HelpCircle },
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
            <button @click="subTab = 'help'"
                class="flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium text-muted-foreground hover:bg-muted transition">
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
                        <button @click="setMonth" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">Month</button>
                        <button @click="setQuarter" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">Quarter</button>
                        <button @click="setYear" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted">Year</button>
                        <button @click="exportCsv" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"><Download class="h-3 w-3" /> CSV</button>
                        <button @click="saveSnapshot" class="rounded-lg border px-2.5 py-1.5 text-xs font-medium hover:bg-muted flex items-center gap-1"><Save class="h-3 w-3" /> Snapshot</button>
                    </div>
                </div>
            </div>

            <template v-if="result">
                <!-- Financial Summary as-of card -->
                <div v-if="result.financial_summary" class="rounded-xl border bg-card shadow-sm p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Financial Summary — as of {{ result.financial_summary.period_end }}</p>
                        <span v-if="result.basis === 'hybrid'" class="rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400 px-2.5 py-0.5 text-xs font-semibold">Hybrid: Sales + Profit</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
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
                        <div class="space-y-0.5">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">COGS</p>
                            <p class="text-base font-bold text-orange-500">−{{ fmt(result.financial_summary.cogs) }}</p>
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Net Profit</p>
                            <p class="text-base font-bold" :class="result.financial_summary.net_profit >= 0 ? 'text-emerald-600' : 'text-red-500'">{{ fmt(result.financial_summary.net_profit) }}</p>
                        </div>
                        <div v-if="result.basis === 'hybrid'" class="space-y-0.5 border-l pl-3">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">Profit Base</p>
                            <p class="text-base font-bold text-violet-600">{{ fmt(result.financial_summary.net_profit) }}</p>
                        </div>
                        <div v-else class="space-y-0.5 border-l pl-3">
                            <p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ result.basis === 'profit' ? 'Profit Margin' : 'Sales Basis' }}</p>
                            <p class="text-base font-bold text-primary">{{ result.basis === 'profit' ? (result.financial_summary.gross_sales > 0 ? ((result.financial_summary.net_profit / result.financial_summary.gross_sales) * 100).toFixed(1) + '%' : '—') : fmt(result.financial_summary.sales_base) }}</p>
                        </div>
                    </div>
                    <!-- Hybrid breakdown note -->
                    <div v-if="result.basis === 'hybrid'" class="mt-3 rounded-lg bg-violet-50 dark:bg-violet-950/20 border border-violet-200 dark:border-violet-800 p-2.5 text-xs text-violet-800 dark:text-violet-300">
                        Hybrid adds each member's <strong>profit share</strong> (ownership % × distributable profit) to their <strong>directly linked royalties</strong> (from royalty rules with their account linked). Members with no linked royalty rules receive only their profit share.
                    </div>
                </div>

                <!-- Flow KPIs -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">{{ result.base_label }}</p><p class="text-xl font-black mt-1">{{ fmt(result.base_amount) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Royalties</p><p class="text-xl font-black mt-1 text-amber-600">−{{ fmt(result.royalty.total) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Distributable</p><p class="text-xl font-black mt-1 text-primary">{{ fmt(result.distributable) }}</p></div>
                    <div class="rounded-xl border bg-card p-4 shadow-sm"><p class="text-[10px] uppercase tracking-wide text-muted-foreground">Company ({{ result.company_percentage }}%)</p><p class="text-xl font-black mt-1 text-emerald-600">{{ fmt(result.company_amount) }}</p></div>
                </div>

                <div class="grid lg:grid-cols-2 gap-4">
                    <!-- Pie -->
                    <div class="rounded-xl border bg-card shadow-sm p-4">
                        <h3 class="font-bold text-sm mb-2">Distribution Breakdown</h3>
                        <apexchart v-if="pieSeries.length" type="pie" height="300" :options="pieOptions" :series="pieSeries" />
                        <p v-else class="text-sm text-muted-foreground text-center py-10">No distributable amount in range.</p>
                    </div>
                    <!-- Member table -->
                    <div class="rounded-xl border bg-card shadow-sm overflow-hidden">
                        <div class="p-4 border-b flex items-center gap-2">
                            <h3 class="font-bold text-sm">Member Shares</h3>
                            <span v-if="result.basis === 'hybrid'" class="rounded-full bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-400 px-2 py-0.5 text-xs font-semibold">Profit Share + Royalties</span>
                        </div>
                        <!-- Mobile cards -->
                        <div class="sm:hidden divide-y">
                            <div v-for="m in result.members" :key="m.shareholder_id" class="p-3 space-y-1.5">
                                <div class="flex justify-between items-center">
                                    <span class="font-semibold text-sm">{{ m.name }}</span>
                                    <span class="text-xs text-muted-foreground">{{ m.percentage }}%</span>
                                </div>
                                <div v-if="result.basis === 'hybrid'" class="flex justify-between text-xs">
                                    <span class="text-muted-foreground">Profit Share</span>
                                    <span>{{ fmt(m.profit_share) }}</span>
                                </div>
                                <div v-if="result.basis === 'hybrid'" class="flex justify-between text-xs">
                                    <span class="text-muted-foreground">Royalties</span>
                                    <span class="text-amber-600">{{ m.royalty_amount > 0 ? fmt(m.royalty_amount) : '—' }}</span>
                                </div>
                                <div class="flex justify-between text-sm font-bold">
                                    <span>Total</span>
                                    <span>{{ fmt(m.amount) }}</span>
                                </div>
                            </div>
                            <div class="p-3 bg-muted/30 flex justify-between font-bold text-sm">
                                <span>Members total ({{ result.members_percentage }}%)</span>
                                <span>{{ fmt(result.members_total) }}</span>
                            </div>
                            <div class="p-3 bg-emerald-50 dark:bg-emerald-950/20 flex justify-between font-bold text-sm text-emerald-700 dark:text-emerald-400">
                                <span>Company ({{ result.company_percentage }}%)</span>
                                <span>{{ fmt(result.company_amount) }}</span>
                            </div>
                        </div>
                        <!-- Desktop table -->
                        <table class="hidden sm:table w-full text-sm">
                            <thead class="bg-muted/50 text-muted-foreground text-xs uppercase">
                                <tr>
                                    <th class="px-4 py-2 text-left">Member</th>
                                    <th class="px-4 py-2 text-right">%</th>
                                    <th v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right">Profit Share</th>
                                    <th v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right text-amber-600">Royalties</th>
                                    <th class="px-4 py-2 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="m in result.members" :key="m.shareholder_id" class="hover:bg-muted/20">
                                    <td class="px-4 py-2 font-medium">{{ m.name }}</td>
                                    <td class="px-4 py-2 text-right">{{ m.percentage }}%</td>
                                    <td v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right text-muted-foreground">{{ fmt(m.profit_share) }}</td>
                                    <td v-if="result.basis === 'hybrid'" class="px-4 py-2 text-right text-amber-600">{{ m.royalty_amount > 0 ? fmt(m.royalty_amount) : '—' }}</td>
                                    <td class="px-4 py-2 text-right font-bold">{{ fmt(m.amount) }}</td>
                                </tr>
                                <tr class="bg-muted/30 font-bold">
                                    <td class="px-4 py-2">Members total</td>
                                    <td class="px-4 py-2 text-right">{{ result.members_percentage }}%</td>
                                    <td v-if="result.basis === 'hybrid'" colspan="2"></td>
                                    <td class="px-4 py-2 text-right">{{ fmt(result.members_total) }}</td>
                                </tr>
                                <tr class="bg-emerald-50 dark:bg-emerald-950/20 font-bold text-emerald-700 dark:text-emerald-400">
                                    <td class="px-4 py-2">Company retained</td>
                                    <td class="px-4 py-2 text-right">{{ result.company_percentage }}%</td>
                                    <td v-if="result.basis === 'hybrid'" colspan="2"></td>
                                    <td class="px-4 py-2 text-right">{{ fmt(result.company_amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Royalty recipients: in hybrid, only unlinked recipients shown here;
                     linked recipients' royalties are already in the member table -->
                <div v-if="visibleRoyaltyRecipients.length" class="rounded-xl border bg-card shadow-sm p-4">
                    <h3 class="font-bold text-sm mb-2">Royalty Recipients</h3>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="r in visibleRoyaltyRecipients" :key="r.recipient_name" class="rounded-full bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400 px-3 py-1 text-sm font-medium">{{ r.recipient_name }}: {{ fmt(r.amount) }}</span>
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
                            <span class="text-sm font-bold">{{ s.ownership_percentage }}% ownership</span>
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
                    <thead class="bg-muted/50 text-muted-foreground text-xs uppercase"><tr><th class="px-4 py-2 text-left">Name</th><th class="px-4 py-2 text-left">Email</th><th class="px-4 py-2 text-right">Ownership</th><th class="px-4 py-2 text-center">Status</th><th class="px-4 py-2"></th></tr></thead>
                    <tbody class="divide-y">
                        <tr v-for="s in shareholders" :key="s.id" class="hover:bg-muted/20">
                            <td class="px-4 py-2 font-medium">{{ s.name }}</td><td class="px-4 py-2 text-muted-foreground">{{ s.email ?? '—' }}</td>
                            <td class="px-4 py-2 text-right font-bold">{{ s.ownership_percentage }}%</td>
                            <td class="px-4 py-2 text-center"><span :class="['rounded-full px-2 py-0.5 text-xs', s.status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500']">{{ s.status }}</span></td>
                            <td class="px-4 py-2 text-right"><button @click="editSh(s)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button><button @click="deleteSh(s)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                        <tr v-if="!shareholders.length"><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No shareholders yet.</td></tr>
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
                            <div>
                                <span class="font-semibold text-sm">{{ r.recipient_name }}</span>
                                <span v-if="r.shareholder" class="text-xs text-muted-foreground"> ({{ r.shareholder.name }})</span>
                            </div>
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
                            <td class="px-4 py-2 text-right"><button @click="editRule(r)" class="p-1 text-muted-foreground hover:text-blue-600"><Pencil class="h-4 w-4" /></button><button @click="deleteRule(r)" class="p-1 text-muted-foreground hover:text-red-600"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                        <tr v-if="!rules.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No royalty rules yet.</td></tr>
                    </tbody>
                </table>
            </div>
        </template>

        <!-- ── TRENDS ───────────────────────────────────────────────────── -->
        <template v-if="subTab === 'trends'">
            <div class="rounded-xl border bg-card shadow-sm p-4">
                <h3 class="font-bold text-sm mb-2 flex items-center gap-2"><TrendingUp class="h-4 w-4 text-primary" /> Monthly Distribution Trend (this year, {{ basis === 'hybrid' ? 'hybrid (sales + profit)' : basis }} basis)</h3>
                <apexchart v-if="trend.length" type="line" height="320" :options="trendOptions" :series="trendSeries" />
                <p v-else class="text-sm text-muted-foreground text-center py-10">No data.</p>
            </div>
            <div v-if="royaltyAnalytics" class="rounded-xl border bg-card shadow-sm overflow-hidden">
                <div class="p-4 border-b flex items-center justify-between"><h3 class="font-bold text-sm">Top Royalty Products</h3><span class="text-sm font-bold text-amber-600">Total: {{ fmt(royaltyAnalytics.total) }}</span></div>
                <!-- Mobile cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="p in royaltyAnalytics.by_product" :key="p.name" class="p-3 space-y-1">
                        <div class="font-semibold text-sm">{{ p.name }}</div>
                        <div class="flex justify-between text-xs text-muted-foreground">
                            <span>Net Sales</span><span>{{ fmt(p.net_sales) }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Rate</span>
                            <span class="font-bold text-amber-600">{{ p.rate }}% → {{ fmt(p.royalty) }}</span>
                        </div>
                    </div>
                    <div v-if="!royaltyAnalytics.by_product.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No royalties in range.</div>
                </div>
                <!-- Desktop table -->
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
                <!-- Mobile cards -->
                <div class="sm:hidden divide-y">
                    <div v-for="s in snapshots" :key="s.id" class="p-3 space-y-1.5">
                        <div class="flex justify-between items-center">
                            <span class="text-xs text-muted-foreground">{{ s.period_start?.slice(0,10) }} → {{ s.period_end?.slice(0,10) }}</span>
                            <span class="text-xs capitalize bg-muted px-2 py-0.5 rounded-full">{{ s.distribution_basis }}</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-muted-foreground">Distributable</span>
                            <span class="font-bold">{{ fmt(s.distributable_amount) }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-muted-foreground">Members</span>
                            <span>{{ fmt(s.members_amount) }}</span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-muted-foreground">Company</span>
                            <span class="text-emerald-600 font-medium">{{ fmt(s.company_amount) }}</span>
                        </div>
                        <div class="text-xs text-muted-foreground">By: {{ s.creator?.name ?? '—' }}</div>
                    </div>
                    <div v-if="!snapshots.length" class="px-4 py-8 text-center text-muted-foreground text-sm">No snapshots saved yet.</div>
                </div>
                <!-- Desktop table -->
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
                <!-- Overview -->
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3 lg:col-span-2">
                    <h3 class="font-bold text-base flex items-center gap-2"><HelpCircle class="h-5 w-5 text-primary" /> What is Profit Sharing?</h3>
                    <p class="text-sm text-muted-foreground leading-relaxed">
                        This tool automatically computes how much each <strong>shareholder</strong> receives, how much goes to
                        <strong>royalty recipients</strong>, and how much the company keeps as <strong>retained earnings</strong> —
                        calculated directly from your real sales and financial records. Nothing here changes your accounting;
                        it only reads existing data. Saving a <strong>Snapshot</strong> stores a historical copy for the record.
                    </p>
                </div>

                <!-- Basis -->
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
                    <h3 class="font-bold text-sm">Sales vs Profit vs Hybrid basis</h3>
                    <div class="space-y-2 text-sm">
                        <p><span class="font-semibold text-primary">Sales basis</span> — shares are computed from
                            <code class="bg-muted px-1 rounded">Net Sales − Refunds</code>. Use when partners are paid on revenue.</p>
                        <p><span class="font-semibold text-primary">Profit basis</span> — shares are computed from
                            <code class="bg-muted px-1 rounded">Net Profit</code> (revenue − COGS − operating expenses), reusing the
                            same figures as your P&amp;L report. Use when partners are paid on actual profit.</p>
                        <p><span class="font-semibold text-violet-600">Hybrid basis</span> — each member's payout is
                            <code class="bg-muted px-1 rounded">Profit Share + their linked Royalties</code>. Profit share is the same
                            as Profit basis (ownership % × distributable). Royalties are added on top only for members who have royalty
                            rules directly linked to their shareholder account. Members with no linked rules receive only their profit share.</p>
                        <p class="text-xs text-muted-foreground">When you filter Profit/Hybrid basis by a single product or category,
                            the profit component becomes that scope's gross profit (net sales − COGS), since operating expenses can't be split per product.</p>
                    </div>
                </div>

                <!-- Calculation flow -->
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-3">
                    <h3 class="font-bold text-sm">The calculation, step by step</h3>
                    <ol class="text-sm space-y-1.5 list-decimal list-inside text-muted-foreground">
                        <li><strong class="text-foreground">Base amount</strong> — Net Sales (Sales basis) or Net Profit (Profit basis).</li>
                        <li><strong class="text-foreground">− Royalties</strong> — product/category royalties are deducted first.</li>
                        <li><strong class="text-foreground">= Distributable</strong> — what's left to split.</li>
                        <li><strong class="text-foreground">Member shares</strong> — each member gets Distributable × ownership %.</li>
                        <li><strong class="text-foreground">Company retained</strong> — the remaining percentage stays with the company.</li>
                    </ol>
                </div>

                <!-- Worked example -->
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-2 lg:col-span-2">
                    <h3 class="font-bold text-sm">Worked example</h3>
                    <div class="overflow-x-auto">
                        <table class="text-sm min-w-[420px]">
                            <tbody class="[&_td]:py-1 [&_td]:pr-6">
                                <tr><td class="text-muted-foreground">Net Sales</td><td class="font-bold text-right">₱1,000,000</td></tr>
                                <tr><td class="text-muted-foreground">− Royalties</td><td class="font-bold text-right text-amber-600">₱50,000</td></tr>
                                <tr class="border-t"><td class="text-muted-foreground">= Distributable</td><td class="font-bold text-right text-primary">₱950,000</td></tr>
                                <tr><td class="pl-4">Member 1 — 10%</td><td class="text-right">₱95,000</td></tr>
                                <tr><td class="pl-4">Member 2 — 20%</td><td class="text-right">₱190,000</td></tr>
                                <tr><td class="pl-4">Member 3 — 10%</td><td class="text-right">₱95,000</td></tr>
                                <tr class="border-t"><td>Members total (40%)</td><td class="font-bold text-right">₱380,000</td></tr>
                                <tr><td class="text-emerald-600">Company retained (60%)</td><td class="font-bold text-right text-emerald-600">₱570,000</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Shareholders -->
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-2">
                    <h3 class="font-bold text-sm flex items-center gap-2"><Users class="h-4 w-4" /> Shareholders</h3>
                    <ul class="text-sm space-y-1 text-muted-foreground list-disc list-inside">
                        <li>Add members and set each one's <strong class="text-foreground">ownership %</strong>.</li>
                        <li>Total active ownership <strong class="text-foreground">cannot exceed 100%</strong> — the tool blocks it.</li>
                        <li>Whatever isn't allocated automatically becomes the <strong class="text-foreground">company share</strong>.</li>
                        <li>Set a member to <strong class="text-foreground">inactive</strong> to exclude them without deleting history.</li>
                        <li>Every change is recorded in the audit log.</li>
                    </ul>
                </div>

                <!-- Royalties -->
                <div class="rounded-xl border bg-card shadow-sm p-5 space-y-2">
                    <h3 class="font-bold text-sm flex items-center gap-2"><Percent class="h-4 w-4" /> Royalties</h3>
                    <ul class="text-sm space-y-1 text-muted-foreground list-disc list-inside">
                        <li>A rule pays a recipient a % of a product's or category's net sales.</li>
                        <li><code class="bg-muted px-1 rounded">Royalty = Product Net Sales × Royalty %</code> (e.g. ₱500,000 × 5% = ₱25,000).</li>
                        <li>Recipients are <strong class="text-foreground">dynamic</strong> — any name, optionally linked to a member.</li>
                        <li>Each rule has an <strong class="text-foreground">effective date</strong> and optional <strong class="text-foreground">expiration</strong>.</li>
                        <li>Royalties are deducted before member shares are calculated.</li>
                    </ul>
                </div>

                <!-- Tips -->
                <div class="rounded-xl border bg-amber-50 dark:bg-amber-950/20 border-amber-200 dark:border-amber-800 p-5 space-y-2 lg:col-span-2">
                    <h3 class="font-bold text-sm text-amber-800 dark:text-amber-300">Tips</h3>
                    <ul class="text-sm space-y-1 text-amber-800/90 dark:text-amber-300/90 list-disc list-inside">
                        <li>Use the <strong>Month / Quarter / Year</strong> shortcuts for quick periods, or pick a custom range.</li>
                        <li>Only <strong>paid</strong> orders count toward sales — matching your Financial reports.</li>
                        <li>Click <strong>Snapshot</strong> to freeze a period's result for audit and historical comparison.</li>
                        <li>Use <strong>CSV</strong> to export the current breakdown for payouts or accounting.</li>
                        <li>The <strong>Trends</strong> tab shows month-over-month member, company, and royalty earnings.</li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>
