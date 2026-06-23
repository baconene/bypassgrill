<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ShoppingCart, ChefHat, Package, BarChart3, ClipboardList, TrendingUp, TrendingDown, AlertTriangle, CheckCircle, Users, Timer, Flame } from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }],
    },
})

interface PlSummary {
    revenue: number
    cogs: number
    gross_profit: number
    expenses: number
    net_profit: number
    net_margin: number
}

interface ServingTime {
    avg_seconds: number | null
    completed_today: number
    peak_hours: { hour: number; order_count: number; avg_seconds: number }[]
}

const props = defineProps<{
    stats: Record<string, number>
    recentOrders: any[]
    pl: PlSummary | null
    servingTime: ServingTime | null
}>()

const page = usePage()
const user = computed(() => page.props.auth?.user)
const roles = computed<string[]>(() => page.props.auth?.roles ?? [])

const hasRole = (role: string) => roles.value.includes(role)

const statusColor: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    preparing: 'bg-blue-100 text-blue-800',
    ready: 'bg-green-100 text-green-800',
    completed: 'bg-gray-100 text-gray-700',
    cancelled: 'bg-red-100 text-red-700',
}

const formatCurrency = (val: number) =>
    '₱' + (val ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const greeting = computed(() => {
    const h = new Date().getHours()
    if (h < 12) return 'Good morning'
    if (h < 17) return 'Good afternoon'
    return 'Good evening'
})

const fmtSeconds = (s: number | null | undefined): string => {
    if (s == null) return '—'
    const m = Math.floor(s / 60)
    const sec = s % 60
    return m > 0 ? `${m}m ${sec}s` : `${sec}s`
}

const fmtHour = (h: number): string => {
    const d = new Date()
    d.setHours(h, 0, 0, 0)
    return d.toLocaleTimeString('en-PH', { hour: 'numeric', hour12: true })
}

const servingSpeedClass = computed(() => {
    const s = props.servingTime?.avg_seconds
    if (s == null) return 'text-muted-foreground'
    if (s < 300) return 'text-emerald-600'
    if (s < 600) return 'text-yellow-600'
    return 'text-red-500'
})

const servingSpeedLabel = computed(() => {
    const s = props.servingTime?.avg_seconds
    if (s == null) return 'No data yet'
    if (s < 300) return 'Fast'
    if (s < 600) return 'Moderate'
    return 'Slow'
})
</script>

<template>
    <Head title="Dashboard" />

    <div class="space-y-6 p-4">
        <!-- Greeting -->
        <div>
            <h1 class="text-2xl font-bold text-foreground">
                {{ greeting }}, {{ user?.name ?? 'User' }}
            </h1>
            <p class="text-muted-foreground text-sm capitalize">
                {{ roles.join(', ') || 'No role assigned' }}
            </p>
        </div>

        <!-- Serving Time Card -->
        <div v-if="servingTime" class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="flex items-center gap-2 px-4 pt-4 pb-2">
                <Timer class="h-4 w-4 text-primary shrink-0" />
                <h2 class="font-semibold text-sm">Avg Serving Time — Today</h2>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x">
                <!-- Main metric -->
                <div class="px-4 py-3 sm:col-span-1">
                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground mb-0.5">Average</p>
                    <p class="text-3xl font-black" :class="servingSpeedClass">
                        {{ fmtSeconds(servingTime.avg_seconds) }}
                    </p>
                    <p class="text-xs mt-0.5" :class="servingSpeedClass">{{ servingSpeedLabel }}</p>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ servingTime.completed_today }} orders completed</p>
                </div>

                <!-- Peak hours -->
                <div class="px-4 py-3 col-span-2 sm:col-span-2">
                    <div class="flex items-center gap-1.5 mb-2">
                        <Flame class="h-3.5 w-3.5 text-orange-500 shrink-0" />
                        <p class="text-[10px] uppercase tracking-wide text-muted-foreground font-semibold">Busiest Hours</p>
                    </div>
                    <div v-if="servingTime.peak_hours.length" class="space-y-1.5">
                        <div v-for="(ph, i) in servingTime.peak_hours" :key="ph.hour"
                            class="flex items-center justify-between text-sm">
                            <div class="flex items-center gap-2">
                                <span class="w-4 text-xs font-bold text-muted-foreground/60">{{ i + 1 }}.</span>
                                <span class="font-semibold">{{ fmtHour(ph.hour) }}</span>
                                <span class="rounded-full bg-orange-100 dark:bg-orange-950/30 text-orange-700 dark:text-orange-400 text-[10px] font-bold px-1.5 py-0.5">
                                    {{ ph.order_count }} orders
                                </span>
                            </div>
                            <span class="text-xs text-muted-foreground font-medium">avg {{ fmtSeconds(ph.avg_seconds) }}</span>
                        </div>
                    </div>
                    <p v-else class="text-xs text-muted-foreground italic">No completed orders yet today.</p>
                </div>
            </div>
        </div>

        <!-- Quick Nav Cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Link
                v-if="hasRole('cashier') || hasRole('admin')"
                href="/pos"
                class="flex flex-col items-center gap-2 rounded-xl border bg-card p-4 text-center shadow-sm transition hover:shadow-md hover:border-primary"
            >
                <ShoppingCart class="h-8 w-8 text-primary" />
                <span class="text-sm font-semibold">Point of Sale</span>
            </Link>
            <Link
                v-if="hasRole('kitchen') || hasRole('admin')"
                href="/kitchen"
                class="flex flex-col items-center gap-2 rounded-xl border bg-card p-4 text-center shadow-sm transition hover:shadow-md hover:border-primary"
            >
                <ChefHat class="h-8 w-8 text-orange-500" />
                <span class="text-sm font-semibold">Kitchen Monitor</span>
            </Link>
            <Link
                v-if="hasRole('auditor') || hasRole('admin')"
                href="/inventory"
                class="flex flex-col items-center gap-2 rounded-xl border bg-card p-4 text-center shadow-sm transition hover:shadow-md hover:border-primary"
            >
                <Package class="h-8 w-8 text-green-600" />
                <span class="text-sm font-semibold">Inventory</span>
            </Link>
            <Link
                v-if="hasRole('auditor') || hasRole('admin')"
                href="/reports"
                class="flex flex-col items-center gap-2 rounded-xl border bg-card p-4 text-center shadow-sm transition hover:shadow-md hover:border-primary"
            >
                <BarChart3 class="h-8 w-8 text-purple-600" />
                <span class="text-sm font-semibold">Reports</span>
            </Link>
            <Link
                v-if="hasRole('admin')"
                href="/hris"
                class="flex flex-col items-center gap-2 rounded-xl border bg-card p-4 text-center shadow-sm transition hover:shadow-md hover:border-primary"
            >
                <Users class="h-8 w-8 text-indigo-600" />
                <span class="text-sm font-semibold">HRIS</span>
            </Link>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <!-- Cashier / Admin stats -->
            <template v-if="hasRole('cashier') || hasRole('admin')">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm text-muted-foreground">Today's Orders</p>
                        <ClipboardList class="h-4 w-4 text-muted-foreground" />
                    </div>
                    <p class="text-3xl font-bold">{{ stats.today_orders ?? 0 }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm text-muted-foreground">Today's Revenue</p>
                        <TrendingUp class="h-4 w-4 text-green-500" />
                    </div>
                    <p class="text-2xl font-bold text-green-600">{{ formatCurrency(stats.today_revenue ?? 0) }}</p>
                </div>
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm text-muted-foreground">Active Orders</p>
                        <ShoppingCart class="h-4 w-4 text-blue-500" />
                    </div>
                    <p class="text-3xl font-bold text-blue-600">{{ stats.active_orders ?? 0 }}</p>
                </div>
            </template>

            <!-- Kitchen stats -->
            <template v-if="hasRole('kitchen') || hasRole('admin')">
                <div class="rounded-xl border bg-yellow-50 dark:bg-yellow-950/20 p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground mb-2">Pending</p>
                    <p class="text-3xl font-bold text-yellow-600">{{ stats.pending_orders ?? 0 }}</p>
                </div>
                <div class="rounded-xl border bg-blue-50 dark:bg-blue-950/20 p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground mb-2">Preparing</p>
                    <p class="text-3xl font-bold text-blue-600">{{ stats.preparing_orders ?? 0 }}</p>
                </div>
                <div class="rounded-xl border bg-green-50 dark:bg-green-950/20 p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground mb-2">Ready</p>
                    <p class="text-3xl font-bold text-green-600">{{ stats.ready_orders ?? 0 }}</p>
                </div>
            </template>

            <!-- Auditor stats -->
            <template v-if="hasRole('auditor') || hasRole('admin')">
                <div
                    class="rounded-xl border p-5 shadow-sm"
                    :class="(stats.low_stock_count ?? 0) > 0 ? 'bg-red-50 dark:bg-red-950/20 border-red-200' : 'bg-card'"
                >
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm text-muted-foreground">Low Stock Items</p>
                        <AlertTriangle
                            class="h-4 w-4"
                            :class="(stats.low_stock_count ?? 0) > 0 ? 'text-red-500' : 'text-muted-foreground'"
                        />
                    </div>
                    <p
                        class="text-3xl font-bold"
                        :class="(stats.low_stock_count ?? 0) > 0 ? 'text-red-600' : 'text-foreground'"
                    >
                        {{ stats.low_stock_count ?? 0 }}
                    </p>
                    <p class="text-xs text-muted-foreground mt-1">of {{ stats.total_ingredients ?? 0 }} ingredients</p>
                </div>
            </template>
        </div>

        <!-- P&L Summary (admin/auditor) -->
        <div v-if="pl && (hasRole('admin') || hasRole('auditor'))" class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="flex items-center justify-between p-4 border-b">
                <h2 class="font-semibold text-base">P&amp;L — This Month</h2>
                <Link href="/reports" class="text-xs text-muted-foreground hover:text-primary">View full report →</Link>
            </div>
            <div class="grid grid-cols-2 gap-0 sm:grid-cols-5 divide-y sm:divide-y-0 sm:divide-x">
                <div class="p-4">
                    <p class="text-xs text-muted-foreground mb-1">Revenue</p>
                    <p class="text-lg font-bold text-green-600">₱{{ formatCurrency(pl.revenue) }}</p>
                </div>
                <div class="p-4">
                    <p class="text-xs text-muted-foreground mb-1">COGS</p>
                    <p class="text-lg font-bold">₱{{ formatCurrency(pl.cogs) }}</p>
                </div>
                <div class="p-4">
                    <p class="text-xs text-muted-foreground mb-1">Gross Profit</p>
                    <p class="text-lg font-bold" :class="pl.gross_profit >= 0 ? 'text-green-600' : 'text-red-600'">
                        ₱{{ formatCurrency(pl.gross_profit) }}
                    </p>
                </div>
                <div class="p-4">
                    <p class="text-xs text-muted-foreground mb-1">Expenses</p>
                    <p class="text-lg font-bold text-orange-600">₱{{ formatCurrency(pl.expenses) }}</p>
                </div>
                <div class="p-4" :class="pl.net_profit >= 0 ? 'bg-green-50 dark:bg-green-950/20' : 'bg-red-50 dark:bg-red-950/20'">
                    <p class="text-xs text-muted-foreground mb-1">Net Profit</p>
                    <p class="text-lg font-bold" :class="pl.net_profit >= 0 ? 'text-green-700' : 'text-red-600'">
                        ₱{{ formatCurrency(pl.net_profit) }}
                    </p>
                    <p class="text-xs text-muted-foreground">{{ pl.net_margin }}% margin</p>
                </div>
            </div>
        </div>

        <!-- Recent Orders -->
        <div v-if="recentOrders.length > 0" class="rounded-xl border bg-card shadow-sm overflow-hidden">
            <div class="p-4 border-b">
                <h2 class="font-semibold text-base">Recent Orders</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Queue #</th>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Type</th>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Items</th>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Total</th>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Status</th>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Payment</th>
                            <th class="px-4 py-2 text-left font-medium text-muted-foreground">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="order in recentOrders" :key="order.id" class="hover:bg-muted/30">
                            <td class="px-4 py-2 font-bold">
                                {{ order.queue_number ? '#' + order.queue_number : '—' }}
                            </td>
                            <td class="px-4 py-2 capitalize">{{ order.order_type?.replace('_', ' ') }}</td>
                            <td class="px-4 py-2">{{ order.items_count }}</td>
                            <td class="px-4 py-2 font-semibold">{{ formatCurrency(order.total_amount) }}</td>
                            <td class="px-4 py-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium capitalize"
                                    :class="statusColor[order.status] ?? 'bg-gray-100 text-gray-700'"
                                >
                                    {{ order.status }}
                                </span>
                            </td>
                            <td class="px-4 py-2 capitalize">{{ order.payment_status }}</td>
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ order.created_at ? new Date(order.created_at).toLocaleTimeString() : '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Empty state for new accounts -->
        <div v-else class="rounded-xl border bg-card p-10 text-center shadow-sm">
            <CheckCircle class="h-10 w-10 text-green-500 mx-auto mb-3" />
            <p class="font-semibold">No orders yet today.</p>
            <p class="text-sm text-muted-foreground mt-1">Orders will appear here once created.</p>
        </div>
    </div>
</template>
