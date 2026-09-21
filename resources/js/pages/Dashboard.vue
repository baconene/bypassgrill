<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    BarChart3,
    Check,
    ChefHat,
    ClipboardList,
    Flame,
    Package,
    RefreshCw,
    ShoppingCart,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Auth } from '@/types/auth';

defineOptions({
    layout: { breadcrumbs: [{ title: 'Dashboard', href: '/dashboard' }] },
});
interface PlSummary {
    revenue: number;
    cogs: number;
    gross_profit: number;
    expenses: number;
    net_profit: number;
    net_margin: number;
}
interface ServingTime {
    avg_seconds: number | null;
    completed_today: number;
    fast_minutes: number;
    slow_minutes: number;
}
interface Order {
    id: number;
    queue_number: number | string | null;
    order_type: string;
    status: string;
    total_amount: number;
    payment_status: string;
    items_count: number;
    created_at: string | null;
}
const props = defineProps<{
    stats: Record<string, number>;
    recentOrders: Order[];
    pl: PlSummary | null;
    servingTime: ServingTime | null;
    pendingProductBreakdown: { name: string; qty: number }[];
}>();
const page = usePage<{
    auth: Auth & { roles: string[]; permissions?: string[] };
}>();
const hasRole = (...roles: string[]) =>
    roles.some((role) => page.props.auth.roles.includes(role));
const canSell = computed(() => hasRole('admin', 'cashier'));
const canCook = computed(() => hasRole('admin', 'kitchen'));
const canReport = computed(() => hasRole('admin', 'auditor'));
const canDeposit = computed(() => hasRole('admin', 'cashier', 'auditor'));
const canViewOrders = computed(
    () =>
        hasRole('admin') ||
        (page.props.auth.permissions ?? []).includes('view orders'),
);
const operational = computed(() => canSell.value || canCook.value);
const refreshing = ref(false);
const refreshError = ref('');
const updatedAt = ref<Date | null>(null);
const showAllOrders = ref(false);
const showAllProducts = ref(false);
const money = (amount: number) =>
    new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    }).format(amount ?? 0);
const dateLabel = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    weekday: 'long',
    month: 'short',
    day: 'numeric',
}).format(new Date());
const timeLabel = (date: Date) =>
    new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
const servingDuration = computed(() => {
    const seconds = props.servingTime?.avg_seconds;

    if (seconds == null) {
        return '—';
    }

    const rounded = Math.max(0, Math.round(seconds));

    return `${Math.floor(rounded / 60)}m ${rounded % 60}s`;
});
const servingNote = computed(() =>
    props.servingTime?.avg_seconds == null
        ? 'No completed orders yet'
        : `${props.servingTime.completed_today} orders completed today`,
);
const cards = computed(() => {
    if (canSell.value) {
        return [
            {
                label: 'Paid sales today',
                value: money(props.stats.today_revenue),
                note: 'Paid orders created today',
                emphasis: true,
            },
            {
                label: 'Orders today',
                value: props.stats.today_orders ?? 0,
                note: 'All orders created today',
            },
            {
                label: 'In progress',
                value: props.stats.active_orders ?? 0,
                note: 'Pending + preparing, all open orders',
            },
            {
                label: 'Average serving time',
                value: servingDuration.value,
                note: servingNote.value,
            },
        ];
    }

    if (canCook.value) {
        return [
            {
                label: 'Waiting to start',
                value: props.stats.pending_orders ?? 0,
                note: 'Pending orders',
                emphasis: true,
            },
            {
                label: 'On the grill',
                value: props.stats.preparing_orders ?? 0,
                note: 'Orders being prepared',
            },
            {
                label: 'Ready to serve',
                value: props.stats.ready_orders ?? 0,
                note: 'Ready orders',
            },
            {
                label: 'Average serving time',
                value: servingDuration.value,
                note: servingNote.value,
            },
        ];
    }

    if (canReport.value) {
        return [
            {
                label: 'Revenue this month',
                value: props.pl ? money(props.pl.revenue) : '—',
                note: 'Month-to-date net revenue',
                emphasis: true,
            },
            {
                label: 'Net profit this month',
                value: props.pl ? money(props.pl.net_profit) : '—',
                note: 'After costs and expenses',
            },
            {
                label: 'Low-stock ingredients',
                value: props.stats.low_stock_count ?? 0,
                note: 'At or below minimum stock',
            },
            {
                label: 'Active ingredients',
                value: props.stats.total_ingredients ?? 0,
                note: 'In your inventory',
            },
        ];
    }

    return [];
});
const primary = computed(() =>
    canSell.value
        ? { href: '/pos', label: 'Open point of sale', icon: ShoppingCart }
        : canCook.value
          ? { href: '/kitchen', label: 'Open kitchen monitor', icon: ChefHat }
          : canReport.value
            ? { href: '/reports', label: 'Open reports', icon: BarChart3 }
            : null,
);
const pendingTotal = computed(() =>
    props.pendingProductBreakdown.reduce((sum, row) => sum + row.qty, 0),
);
const pendingRows = computed(() =>
    showAllProducts.value
        ? props.pendingProductBreakdown
        : props.pendingProductBreakdown.slice(0, 5),
);
const orderRows = computed(() =>
    showAllOrders.value ? props.recentOrders : props.recentOrders.slice(0, 5),
);
const statusLabels: Record<string, string> = {
    pending: 'Pending',
    preparing: 'Preparing',
    ready: 'Ready',
    completed: 'Completed',
    cancelled: 'Cancelled',
};
const paymentLabels: Record<string, string> = {
    paid: 'Paid',
    unpaid: 'Unpaid',
    partial: 'Part paid',
    refunded: 'Refunded',
};
const orderTypeLabels: Record<string, string> = {
    dine_in: 'Dine in',
    takeout: 'Takeout',
    take_out: 'Takeout',
    delivery: 'Delivery',
};
function refresh() {
    if (refreshing.value) {
        return;
    }

    refreshError.value = '';
    router.reload({
        only: [
            'stats',
            'recentOrders',
            'pl',
            'servingTime',
            'pendingProductBreakdown',
        ],
        onStart: () => {
            refreshing.value = true;
        },
        onSuccess: () => {
            updatedAt.value = new Date();
        },
        onError: () => {
            refreshError.value =
                'Could not refresh the dashboard. Please try again.';
        },
        onFinish: () => {
            refreshing.value = false;
        },
    });
}
</script>

<template>
    <Head title="Dashboard" />
    <div class="grill-dashboard">
        <header class="dashboard-heading">
            <div>
                <p class="eyebrow">
                    <Flame :size="14" aria-hidden="true" /> BYPASS GRILL / DAILY
                    OVERVIEW
                </p>
                <h1>Today at <em>the grill.</em></h1>
                <p class="intro">
                    Welcome back, {{ page.props.auth.user.name }}. Here's what
                    needs your attention.
                </p>
            </div>
            <div class="heading-meta">
                <span>{{ dateLabel }} · Manila</span
                ><button
                    class="refresh-button"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    <RefreshCw
                        :size="14"
                        :class="{ spinning: refreshing }"
                        aria-hidden="true"
                    />{{
                        refreshing ? 'Refreshing…' : 'Refresh overview'
                    }}</button
                ><small v-if="updatedAt" role="status"
                    >Updated {{ timeLabel(updatedAt) }}</small
                >
            </div>
        </header>
        <p v-if="refreshError" class="refresh-error" role="alert">
            {{ refreshError }}
        </p>

        <section v-if="primary" class="work-bar" aria-label="Quick actions">
            <div class="work-bar-copy">
                <strong>{{
                    canSell
                        ? 'Ready for the next order?'
                        : canCook
                          ? 'Keep the orders moving.'
                          : 'Keep the numbers in check.'
                }}</strong
                ><span>{{
                    canSell
                        ? 'Take an order, collect payment, and keep the shift moving.'
                        : canCook
                          ? 'See the queue and update preparation status.'
                          : 'Review balances, inventory, and financial reports.'
                }}</span>
            </div>
            <div class="work-actions">
                <Link :href="primary.href" class="primary-action"
                    ><component
                        :is="primary.icon"
                        :size="17"
                        aria-hidden="true" />{{ primary.label
                    }}<ArrowUpRight :size="17" aria-hidden="true" /></Link
                ><Link
                    v-if="canDeposit"
                    href="/deposit-control"
                    class="secondary-action"
                    ><Wallet :size="17" aria-hidden="true" />Deposit
                    control</Link
                >
            </div>
        </section>

        <section
            v-if="cards.length"
            class="metric-grid"
            aria-label="Key figures"
        >
            <article
                v-for="card in cards"
                :key="card.label"
                class="metric"
                :class="{ 'metric-featured': card.emphasis }"
            >
                <p>{{ card.label }}</p>
                <strong>{{ card.value }}</strong
                ><span>{{ card.note }}</span>
            </article>
        </section>
        <section v-else class="panel empty-state">
            <Flame :size="30" aria-hidden="true" />
            <h2>Your account is ready.</h2>
            <p>
                Ask an administrator to assign your role so your workspace can
                be shown here.
            </p>
            <Link href="/" class="text-link"
                >Back to the website <ArrowUpRight :size="15"
            /></Link>
        </section>

        <div
            v-if="cards.length"
            class="dashboard-columns"
            :class="{ 'single-column': !canDeposit && !canReport }"
        >
            <div class="main-column">
                <section v-if="operational" class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">CURRENT QUEUE</p>
                            <h2>
                                Still to serve
                                <span class="count-badge"
                                    >{{ pendingTotal }} items</span
                                >
                            </h2>
                        </div>
                        <Link v-if="canCook" href="/kitchen" class="text-link"
                            >Kitchen
                            <ArrowUpRight :size="15" aria-hidden="true" /></Link
                        ><Link v-else href="/pos" class="text-link"
                            >POS <ArrowUpRight :size="15" aria-hidden="true"
                        /></Link>
                    </div>
                    <div v-if="pendingRows.length" class="pending-list">
                        <div v-for="row in pendingRows" :key="row.name">
                            <span>{{ row.name }}</span
                            ><strong>× {{ row.qty }}</strong>
                        </div>
                    </div>
                    <div v-else class="empty-state">
                        <Check :size="26" aria-hidden="true" />
                        <h3>All caught up.</h3>
                        <p>
                            No items waiting in pending, preparing, or ready
                            orders.
                        </p>
                    </div>
                    <button
                        v-if="pendingProductBreakdown.length > 5"
                        class="expand-button"
                        :aria-expanded="showAllProducts"
                        @click="showAllProducts = !showAllProducts"
                    >
                        {{
                            showAllProducts
                                ? 'Show fewer items'
                                : `Show all ${pendingProductBreakdown.length} products`
                        }}
                    </button>
                </section>

                <section class="panel orders-panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">LATEST ACTIVITY</p>
                            <h2>Recent orders</h2>
                        </div>
                        <ClipboardList
                            :size="20"
                            class="muted-icon"
                            aria-hidden="true"
                        />
                    </div>
                    <div
                        v-if="recentOrders.length"
                        class="table-scroll"
                        tabindex="0"
                        role="region"
                        aria-label="Recent orders table"
                    >
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Order</th>
                                    <th scope="col">Type</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Payment</th>
                                    <th scope="col" class="amount">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="order in orderRows" :key="order.id">
                                    <td>
                                        <Link
                                            v-if="canViewOrders"
                                            :href="`/orders/${order.id}`"
                                            class="order-link"
                                            >#{{ order.id }}
                                            <ArrowUpRight
                                                :size="12"
                                                aria-hidden="true" /></Link
                                        ><strong v-else>#{{ order.id }}</strong
                                        ><small
                                            v-if="order.queue_number != null"
                                            >Queue
                                            {{ order.queue_number }}</small
                                        >
                                    </td>
                                    <td>
                                        {{
                                            orderTypeLabels[order.order_type] ??
                                            order.order_type
                                        }}
                                    </td>
                                    <td>
                                        <span
                                            class="status"
                                            :class="`status-${order.status}`"
                                            >{{
                                                statusLabels[order.status] ??
                                                order.status
                                            }}</span
                                        >
                                    </td>
                                    <td>
                                        <span
                                            :class="
                                                order.payment_status === 'paid'
                                                    ? 'payment-paid'
                                                    : 'payment-other'
                                            "
                                            >{{
                                                paymentLabels[
                                                    order.payment_status
                                                ] ?? order.payment_status
                                            }}</span
                                        >
                                    </td>
                                    <td class="amount">
                                        {{ money(order.total_amount) }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-else class="empty-state">
                        <ClipboardList :size="26" aria-hidden="true" />
                        <h3>No orders yet.</h3>
                        <p>
                            New orders will appear here once they are recorded.
                        </p>
                    </div>
                    <button
                        v-if="recentOrders.length > 5"
                        class="expand-button"
                        :aria-expanded="showAllOrders"
                        @click="showAllOrders = !showAllOrders"
                    >
                        {{
                            showAllOrders
                                ? 'Show latest 5'
                                : `Show latest ${recentOrders.length} orders`
                        }}
                    </button>
                </section>
            </div>

            <aside
                v-if="canDeposit || canReport"
                class="side-column"
                aria-label="Shift tools and summaries"
            >
                <section v-if="canDeposit" class="panel deposit-panel">
                    <span class="tool-icon"
                        ><Wallet :size="23" aria-hidden="true"
                    /></span>
                    <p class="eyebrow">MAKE EVERY PESO COUNT</p>
                    <h2>
                        {{
                            canSell
                                ? 'Start. Count. Close.'
                                : 'Review the shift.'
                        }}
                    </h2>
                    <p>
                        Save opening and closing snapshots, then compare drawer
                        cash, GCash, and the lockbox against system balances.
                    </p>
                    <Link href="/deposit-control" class="text-link"
                        >{{
                            canSell
                                ? 'Open deposit control'
                                : 'Review deposit control'
                        }}
                        <ArrowUpRight :size="16" aria-hidden="true" /></Link
                    ><Link href="/deposit-control/history" class="history-link"
                        >Previous snapshots</Link
                    >
                </section>
                <section v-if="canCook && canSell" class="panel kitchen-panel">
                    <div class="panel-heading">
                        <h2>Kitchen queue</h2>
                        <ChefHat :size="20" aria-hidden="true" />
                    </div>
                    <dl class="summary-list">
                        <div>
                            <dt>Pending</dt>
                            <dd>{{ stats.pending_orders ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt>Preparing</dt>
                            <dd>{{ stats.preparing_orders ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt>Ready</dt>
                            <dd>{{ stats.ready_orders ?? 0 }}</dd>
                        </div>
                    </dl>
                    <Link href="/kitchen" class="text-link"
                        >Manage the queue
                        <ArrowUpRight :size="16" aria-hidden="true"
                    /></Link>
                </section>
                <section v-if="canReport" class="panel">
                    <div class="panel-heading">
                        <h2>Stock check</h2>
                        <Package :size="20" aria-hidden="true" />
                    </div>
                    <p class="stock-count">
                        {{ stats.low_stock_count ?? 0 }}
                        <span>ingredients low on stock</span>
                    </p>
                    <p class="panel-copy">
                        {{
                            stats.low_stock_count
                                ? 'Review ingredients at or below their minimum quantity.'
                                : 'No active ingredients are at or below minimum stock.'
                        }}
                    </p>
                    <Link href="/inventory" class="text-link"
                        >Review inventory
                        <ArrowUpRight :size="16" aria-hidden="true"
                    /></Link>
                </section>
                <section v-if="canReport && pl" class="panel">
                    <div class="panel-heading">
                        <div>
                            <p class="eyebrow">MONTH TO DATE</p>
                            <h2>Financial summary</h2>
                        </div>
                    </div>
                    <dl class="summary-list">
                        <div>
                            <dt>Net revenue</dt>
                            <dd>{{ money(pl.revenue) }}</dd>
                        </div>
                        <div>
                            <dt>Cost of goods</dt>
                            <dd>{{ money(pl.cogs) }}</dd>
                        </div>
                        <div>
                            <dt>Expenses</dt>
                            <dd>{{ money(pl.expenses) }}</dd>
                        </div>
                        <div class="summary-total">
                            <dt>Net profit</dt>
                            <dd :class="{ negative: pl.net_profit < 0 }">
                                {{ money(pl.net_profit) }}
                            </dd>
                        </div>
                    </dl>
                    <Link href="/reports" class="text-link"
                        >View full reports
                        <ArrowUpRight :size="16" aria-hidden="true"
                    /></Link>
                </section>
            </aside>
        </div>
        <footer class="dashboard-footer">
            <span>BYPASS GRILL · GOOD FOOD. GOOD MOOD.</span
            ><span>Figures update when you open or refresh this page.</span>
        </footer>
    </div>
</template>

<style scoped>
.grill-dashboard {
    background: #f6f2e9;
    color: #24231e;
    min-height: 100%;
    padding: 34px clamp(18px, 3vw, 44px);
    font-family: Arial, Helvetica, sans-serif;
    color-scheme: light;
}
.dashboard-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    margin-bottom: 26px;
}
.eyebrow {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 1.6px;
    color: #ad3b19;
}
.dashboard-heading h1 {
    font-size: clamp(32px, 3.5vw, 48px);
    font-weight: 850;
    letter-spacing: -1.8px;
    line-height: 1.1;
    margin: 12px 0;
}
.dashboard-heading h1 em {
    font-family: Georgia, serif;
    font-weight: 400;
    color: #ad3b19;
}
.intro {
    font-size: 13px;
    line-height: 1.6;
    color: #68665f;
}
.heading-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 10px;
    font-size: 11px;
    color: #68665f;
    white-space: nowrap;
}
.heading-meta small {
    font-size: 10px;
}
.refresh-button {
    display: flex;
    align-items: center;
    gap: 7px;
    border: 1px solid #d4cdbf;
    border-radius: 4px;
    padding: 9px 12px;
    background: #fffcf6;
    font-size: 11px;
    font-weight: 700;
    color: #24231e;
}
.refresh-button:disabled {
    opacity: 0.6;
    cursor: wait;
}
.refresh-error {
    background: #fbe9e4;
    color: #a03015;
    border: 1px solid #edc4b7;
    padding: 12px;
    margin-bottom: 15px;
    font-size: 13px;
}
.work-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    background: #24231e;
    color: #f6f2e9;
    border-radius: 6px;
    padding: 23px 25px;
    margin-bottom: 22px;
}
.work-bar-copy strong {
    font-size: 16px;
    display: block;
}
.work-bar-copy span {
    font-size: 11px;
    color: #c3bfb3;
    display: block;
    line-height: 1.7;
    margin-top: 5px;
}
.work-actions {
    display: flex;
    gap: 10px;
    flex-shrink: 0;
    flex-wrap: wrap;
}
.primary-action,
.secondary-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 800;
    padding: 13px 15px;
}
.primary-action {
    background: #c3441c;
    color: #fff;
}
.primary-action:hover {
    background: #a73513;
}
.secondary-action {
    border: 1px solid #ffffff40;
    color: #f6f2e9;
}
.secondary-action:hover {
    background: #ffffff12;
}
.metric-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 25px;
}
.metric {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    padding: 21px;
    border-radius: 5px;
}
.metric-featured {
    background: #f2e5d8;
    border-color: #e4c4ab;
}
.metric p {
    font-size: 11px;
    font-weight: 700;
    color: #68665f;
}
.metric strong {
    display: block;
    font-size: clamp(22px, 2.4vw, 32px);
    letter-spacing: -1px;
    line-height: 1.3;
    margin: 12px 0 7px;
    overflow-wrap: anywhere;
    font-variant-numeric: tabular-nums;
}
.metric-featured strong {
    color: #ad3b19;
}
.metric > span {
    font-size: 10px;
    line-height: 1.6;
    color: #777268;
    display: block;
}
.dashboard-columns {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 300px;
    gap: 22px;
}
.dashboard-columns.single-column {
    grid-template-columns: minmax(0, 1fr);
}
.main-column,
.side-column {
    display: flex;
    flex-direction: column;
    gap: 22px;
    min-width: 0;
}
.panel {
    background: #fffcf6;
    border: 1px solid #ded7cb;
    border-radius: 6px;
    padding: 23px;
    min-width: 0;
}
.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}
.panel h2 {
    font-size: 18px;
    font-weight: 800;
    letter-spacing: -0.5px;
    line-height: 1.3;
}
.panel-heading .eyebrow {
    margin-bottom: 7px;
    font-size: 8px;
}
.count-badge {
    display: inline-block;
    margin-left: 8px;
    font-size: 10px;
    letter-spacing: 0;
    font-weight: 700;
    background: #f2e5d8;
    color: #ad3b19;
    padding: 4px 8px;
    border-radius: 20px;
    vertical-align: middle;
}
.text-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: 11px;
    font-weight: 700;
    color: #ad3b19;
}
.text-link:hover {
    text-decoration: underline;
    text-underline-offset: 4px;
}
.pending-list > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    padding: 13px 0;
    border-top: 1px solid #ece5da;
    font-size: 13px;
}
.pending-list strong {
    flex-shrink: 0;
    background: #efeadf;
    min-width: 44px;
    padding: 4px 9px;
    text-align: center;
    border-radius: 3px;
    font-size: 12px;
}
.expand-button {
    font-size: 11px;
    font-weight: 700;
    color: #ad3b19;
    border-top: 1px solid #ece5da;
    padding-top: 15px;
    margin-top: 12px;
    width: 100%;
    text-align: left;
}
.orders-panel {
    padding-bottom: 20px;
}
.table-scroll {
    overflow-x: auto;
}
table {
    border-collapse: collapse;
    width: 100%;
    white-space: nowrap;
    font-size: 12px;
}
th {
    text-align: left;
    font-size: 9px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    color: #777268;
    padding: 0 14px 13px 0;
    font-weight: 700;
}
td {
    padding: 15px 14px 15px 0;
    border-top: 1px solid #ece5da;
}
td small {
    display: block;
    color: #777268;
    font-size: 9px;
    margin-top: 4px;
}
.amount {
    text-align: right;
    padding-right: 0;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}
.order-link {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-weight: 700;
}
.order-link:hover {
    color: #ad3b19;
}
.status {
    font-size: 10px;
    display: inline-block;
    padding: 4px 7px;
    border-radius: 3px;
    background: #efeadf;
    color: #625b4c;
}
.status-pending {
    background: #f7eccf;
    color: #7b5815;
}
.status-preparing {
    background: #f8e2d7;
    color: #9d401d;
}
.status-ready {
    background: #e4edde;
    color: #376229;
}
.status-cancelled {
    background: #f7e1df;
    color: #9c3028;
}
.payment-paid {
    color: #376229;
    font-size: 11px;
}
.payment-other {
    color: #8b481e;
    font-size: 11px;
}
.muted-icon {
    color: #93897b;
}
.empty-state {
    padding: 26px 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 10px;
    color: #777268;
}
.empty-state > svg {
    color: #ad3b19;
}
.empty-state h3,
.empty-state h2 {
    color: #24231e;
    font-size: 15px;
    font-weight: 700;
}
.empty-state p {
    font-size: 12px;
    line-height: 1.7;
    max-width: 350px;
}
.deposit-panel {
    background: #ebe8d3;
}
.tool-icon {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border: 1px solid #bbb793;
    border-radius: 50%;
    margin-bottom: 20px;
}
.deposit-panel .eyebrow {
    font-size: 8px;
    color: #6d6243;
}
.deposit-panel h2 {
    margin: 10px 0;
}
.deposit-panel > p:not(.eyebrow) {
    font-size: 12px;
    line-height: 1.8;
    color: #69644e;
    margin-bottom: 20px;
}
.history-link {
    display: block;
    font-size: 10px;
    color: #68634b;
    text-decoration: underline;
    text-underline-offset: 3px;
    margin-top: 13px;
}
.summary-list {
    font-size: 12px;
    margin-bottom: 20px;
}
.summary-list > div {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #ece5da;
}
.summary-list dt {
    color: #68665f;
}
.summary-list dd {
    font-weight: 700;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.summary-total dt,
.summary-total dd {
    font-weight: 800;
    color: #24231e;
}
.summary-total .negative {
    color: #a03015;
}
.stock-count {
    font-size: 30px;
    font-weight: 800;
    line-height: 1.3;
    letter-spacing: -1px;
}
.stock-count span {
    font-size: 11px;
    letter-spacing: 0;
    font-weight: 400;
    color: #68665f;
}
.panel-copy {
    font-size: 11px;
    line-height: 1.8;
    color: #68665f;
    margin: 10px 0 18px;
}
.dashboard-footer {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin-top: 30px;
    padding-top: 18px;
    border-top: 1px solid #ded7cb;
    font-size: 9px;
    color: #777268;
}
.dashboard-footer > span:first-child {
    letter-spacing: 1px;
    font-weight: 700;
}
.grill-dashboard :focus-visible {
    outline: 2px solid #ad3b19;
    outline-offset: 4px;
}
.grill-dashboard button {
    cursor: pointer;
}
.spinning {
    animation: spin 1s linear infinite;
}
@keyframes spin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 1150px) {
    .work-bar {
        align-items: flex-start;
        flex-direction: column;
    }
    .dashboard-columns {
        grid-template-columns: minmax(0, 1fr) 270px;
    }
    .metric {
        padding: 17px;
    }
    .panel {
        padding: 19px;
    }
}
@media (max-width: 900px) {
    .dashboard-columns {
        grid-template-columns: 1fr;
    }
    .side-column {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        align-items: start;
    }
    .metric-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .dashboard-heading {
        flex-wrap: wrap;
    }
    .heading-meta {
        align-items: flex-start;
        flex-direction: row;
        flex-wrap: wrap;
        align-items: center;
    }
    .metric strong {
        font-size: 28px;
    }
}
@media (max-width: 540px) {
    .grill-dashboard {
        padding: 24px 16px;
    }
    .dashboard-heading h1 {
        font-size: 34px;
    }
    .intro {
        font-size: 12px;
    }
    .metric-grid {
        gap: 10px;
    }
    .metric {
        padding: 15px;
    }
    .metric strong {
        font-size: 24px;
    }
    .work-bar {
        padding: 20px;
    }
    .work-actions {
        width: 100%;
        flex-direction: column;
    }
    .primary-action,
    .secondary-action {
        width: 100%;
    }
    .side-column {
        display: flex;
    }
    .count-badge {
        display: block;
        width: max-content;
        margin: 7px 0 0;
    }
    .panel h2 {
        font-size: 17px;
    }
    .panel-heading .text-link {
        white-space: nowrap;
    }
    .dashboard-footer {
        flex-direction: column;
        line-height: 1.6;
    }
}
@media (prefers-reduced-motion: reduce) {
    .spinning {
        animation: none;
    }
}
</style>
