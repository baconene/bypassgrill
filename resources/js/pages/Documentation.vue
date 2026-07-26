<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Documentation', href: '/documentation' },
        ],
    },
})

const activeSection = ref('overview')

const recommendations = [
    { priority: 'HIGH', cls: 'bg-red-900/50 text-red-300', title: 'Cashier Shift Sessions', desc: 'No concept of opening or closing a cash drawer. Cashiers collect across an entire day with no session boundary, making it impossible to isolate who is accountable for a discrepancy.', build: 'A CashierSession model with opening_amount, cashier_id, and opened_at. Closing records expected vs. declared cash and variance. Pair with Start/End Shift flow in POS.' },
    { priority: 'HIGH', cls: 'bg-red-900/50 text-red-300', title: 'Till Count & Closing Report', desc: 'No mechanism for cashiers to declare the cash being handed over at end of shift. Without a till count, shortages and overages go undetected.', build: 'A closing screen where the cashier enters counted cash per denomination. System compares against expected and shows variance. Admin alert when variance exceeds threshold.' },
    { priority: 'HIGH', cls: 'bg-red-900/50 text-red-300', title: 'Cash Deposit Tracking', desc: 'No event in the system for cash deposited to the bank. Collected cash sits in the payment ledger indefinitely with no way to track when it left the premises.', build: 'A deposit transaction type recording: amount, date, bank/reference, and who prepared it. Summary shows Cash on Hand = payments minus deposits.' },
    { priority: 'HIGH', cls: 'bg-red-900/50 text-red-300', title: 'GCash Settlement Reconciliation', desc: 'GCash payments are treated as equivalent to cash, but GCash funds settle to bank on a separate cycle. The running balance overstates accessible cash.', build: 'A GCash Settlement event that marks when GCash funds were received at the bank. Report the pending-settlement amount separately from collected cash.' },
    { priority: 'MED', cls: 'bg-yellow-900/50 text-yellow-300', title: 'Approval Workflow for Manual Entries', desc: 'Any auditor can create an expense or income adjustment of any amount without a second sign-off. Large manual entries have no oversight.', build: 'A configurable threshold (e.g. P500). Entries above it create a pending record for admin approval before appearing in the balance.' },
    { priority: 'MED', cls: 'bg-yellow-900/50 text-yellow-300', title: 'Income Adjustment Sub-categories', desc: 'income_adjustment is a catch-all. Entries for extras collected, refund recovered, and cash advance returned all look the same in reports.', build: 'Add a sub_type column for manual entries. Define: cash_advance_return, overpayment, other_income. Group in the summary report.' },
    { priority: 'MED', cls: 'bg-yellow-900/50 text-yellow-300', title: 'Collections by Cashier Report', desc: 'No report shows how much each cashier collected per shift. If cash is short, there is no fast way to determine which cashier is accountable.', build: 'A report tab showing payments grouped by user_id and date range: total collected, tender breakdown, count. Ties into Shift Sessions.' },
    { priority: 'LOW', cls: 'bg-green-900/50 text-green-300', title: 'Daily Variance Alert', desc: 'The only way to detect a cash discrepancy today is a manual comparison. There is no automated alert when the system total and actual count diverge.', build: 'After till count submission, send admin notification when variance exceeds threshold. Log each variance in shift_variances for trend analysis.' },
]

const sections = [
    { id: 'overview',      label: 'Overview' },
    { id: 'pos',           label: 'POS & Orders' },
    { id: 'kitchen',       label: 'Kitchen Operations' },
    { id: 'financials',    label: 'Financials' },
    { id: 'reports',       label: 'Reports' },
    { id: 'inventory',     label: 'Inventory' },
    { id: 'products',      label: 'Products' },
    { id: 'distribution',  label: 'Distribution' },
    { id: 'hris',          label: 'HRIS & Payroll' },
    { id: 'settings',      label: 'Settings' },
    { id: 'tools',         label: 'Tools' },
    { id: 'parcels',       label: 'Parcels' },
    { id: 'commands',      label: 'Artisan Commands' },
    { id: 'roles',         label: 'Roles & Access' },
    { id: 'recommendations', label: 'Recommendations' },
]

let observer: IntersectionObserver | null = null

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach(e => {
                if (e.isIntersecting) activeSection.value = e.target.id
            })
        },
        { rootMargin: '-20% 0px -70% 0px', threshold: 0 }
    )
    sections.forEach(s => {
        const el = document.getElementById(s.id)
        if (el) observer!.observe(el)
    })
})

onUnmounted(() => observer?.disconnect())
</script>

<template>
    <Head title="Documentation" />

    <div class="flex gap-0 min-h-[calc(100vh-4rem)]">

        <!-- ── Sidebar nav ── -->
        <aside class="hidden lg:flex flex-col w-52 shrink-0 border-r border-border sticky top-0 h-screen overflow-y-auto py-5">
            <p class="px-4 text-[10px] font-bold tracking-widest uppercase text-muted-foreground mb-1">System</p>
            <nav class="flex flex-col text-sm">
                <a v-for="s in sections.slice(0, 7)" :key="s.id" :href="'#' + s.id"
                    :class="[
                        'px-4 py-1.5 border-l-2 transition-colors',
                        activeSection === s.id
                            ? 'border-orange-500 text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/30 font-semibold'
                            : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                    ]">
                    {{ s.label }}
                </a>
                <p class="px-4 pt-4 pb-1 text-[10px] font-bold tracking-widest uppercase text-muted-foreground">Management</p>
                <a v-for="s in sections.slice(7, 12)" :key="s.id" :href="'#' + s.id"
                    :class="[
                        'px-4 py-1.5 border-l-2 transition-colors',
                        activeSection === s.id
                            ? 'border-orange-500 text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/30 font-semibold'
                            : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                    ]">
                    {{ s.label }}
                </a>
                <p class="px-4 pt-4 pb-1 text-[10px] font-bold tracking-widest uppercase text-muted-foreground">Reference</p>
                <a v-for="s in sections.slice(12, 14)" :key="s.id" :href="'#' + s.id"
                    :class="[
                        'px-4 py-1.5 border-l-2 transition-colors',
                        activeSection === s.id
                            ? 'border-orange-500 text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/30 font-semibold'
                            : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-muted/40'
                    ]">
                    {{ s.label }}
                </a>
                <p class="px-4 pt-4 pb-1 text-[10px] font-bold tracking-widest uppercase text-muted-foreground">Analysis</p>
                <a href="#recommendations"
                    :class="[
                        'px-4 py-1.5 border-l-2 transition-colors font-semibold',
                        activeSection === 'recommendations'
                            ? 'border-orange-500 text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-950/30'
                            : 'border-transparent text-orange-500 dark:text-orange-400 hover:bg-orange-50/50 dark:hover:bg-orange-950/20'
                    ]">
                    Recommendations
                </a>
            </nav>
        </aside>

        <!-- ── Main content ── -->
        <main class="flex-1 min-w-0 px-6 lg:px-10 py-8 max-w-3xl scroll-pt-24" style="scroll-behavior:smooth;">

            <!-- ══ OVERVIEW ══ -->
            <section id="overview" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">System Overview</p>
                <h1 class="text-2xl font-bold mb-1">Bypass Grill POS Platform</h1>
                <p class="text-muted-foreground mb-5 leading-relaxed">Full-stack restaurant management covering point-of-sale, kitchen, financials, inventory, HR, and distribution. Built on Laravel 11 + Vue 3 (TypeScript) via Inertia.js.</p>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
                    <div v-for="item in [
                        { label: 'Backend', value: 'Laravel 11 + Inertia.js' },
                        { label: 'Frontend', value: 'Vue 3 + TypeScript' },
                        { label: 'Styling', value: 'Tailwind CSS' },
                        { label: 'Real-time', value: 'Pusher Channels + Beams' },
                        { label: 'Auth & Roles', value: 'Spatie Permissions' },
                        { label: 'Timezone', value: 'Asia/Manila (UTC+8)' },
                    ]" :key="item.label" class="border rounded-lg p-3 bg-card">
                        <div class="text-[10px] font-bold tracking-wider uppercase text-muted-foreground mb-1">{{ item.label }}</div>
                        <div class="text-sm font-semibold">{{ item.value }}</div>
                    </div>
                </div>

                <h3 class="font-semibold text-sm mb-2 pb-2 border-b">Financial Transaction Types</h3>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="text-sm w-full">
                        <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Type</th>
                                <th class="px-3 py-2 text-left font-semibold">Direction</th>
                                <th class="px-3 py-2 text-left font-semibold">Created by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="row in [
                                { type:'payment',          dir:'IN',  cls:'text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-950/30', dcls:'text-green-700 dark:text-green-400 font-semibold', by:'Auto — PaymentService on each payment' },
                                { type:'income_adjustment',dir:'IN',  cls:'text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-950/30', dcls:'text-green-700 dark:text-green-400 font-semibold', by:'Manual — admin or auditor' },
                                { type:'expense',          dir:'OUT', cls:'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/30', dcls:'text-red-700 dark:text-red-400 font-semibold', by:'Manual — admin or auditor' },
                                { type:'payroll',          dir:'OUT', cls:'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/30', dcls:'text-red-700 dark:text-red-400 font-semibold', by:'Auto — when payroll is marked paid in HRIS' },
                                { type:'asset_deduction',  dir:'OUT', cls:'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/30', dcls:'text-red-700 dark:text-red-400 font-semibold', by:'Manual — admin or auditor' },
                                { type:'payout_share',     dir:'OUT', cls:'text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-950/30', dcls:'text-red-700 dark:text-red-400 font-semibold', by:'Auto — distribution snapshot payout' },
                                { type:'order',            dir:'—',   cls:'text-muted-foreground bg-muted/40', dcls:'text-muted-foreground', by:'Auto — excluded from ledger; mirrors order total' },
                            ]" :key="row.type" class="hover:bg-muted/20">
                                <td class="px-3 py-2"><code :class="['text-xs font-mono px-1.5 py-0.5 rounded font-semibold', row.cls]">{{ row.type }}</code></td>
                                <td :class="['px-3 py-2 text-sm', row.dcls]">{{ row.dir }}</td>
                                <td class="px-3 py-2 text-muted-foreground">{{ row.by }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 border-l-4 border-orange-400 bg-orange-50 dark:bg-orange-950/20 px-4 py-2.5 rounded-r text-sm text-muted-foreground">
                    <strong class="text-foreground">Net formula:</strong> payments + income_adjustments − expenses − payroll − asset_deductions − payout_shares
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ POS & ORDERS ══ -->
            <section id="pos" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Sales</p>
                <h2 class="text-xl font-bold mb-4">Point of Sale &amp; Orders</h2>

                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Cashier Dashboard <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/pos</code></h3>
                <div class="flex gap-2 flex-wrap my-2">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300">can:create orders</span>
                    <span class="text-xs font-mono px-2 py-0.5 rounded border bg-muted/50">CashierDashboard.vue</span>
                </div>
                <p class="text-sm text-muted-foreground mb-3 leading-relaxed">Main sales terminal. Cashiers browse products, build a cart with modifiers, set order type, apply discounts, choose payment tender, and submit.</p>
                <ul class="text-sm space-y-1.5 mb-5">
                    <li v-for="f in [
                        'Browse products by category or keyword search',
                        'Add product modifiers (add-ons) with individual pricing',
                        'Order types: Dine In (table number), Takeout, Delivery (address required)',
                        'Apply discount amount before checkout',
                        'Select payment tender; auto-compute change from amount tendered',
                        'Offline queue: orders saved locally and synced on reconnect',
                        'Manual receipt print trigger at any time',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>

                <h3 class="font-semibold text-sm mb-2 pb-2 border-b">Order Creation Flow</h3>
                <ol class="text-sm space-y-2 mb-5 counter-reset-step">
                    <li v-for="(step, i) in [
                        'Create Order record (status=pending). Assign sequential QueueNumber for dine-in/takeout.',
                        'For each item: check ingredient availability, deduct stock via recipe, attach modifiers.',
                        'Calculate totals: subtotal − discount_amount; tax is currently zero.',
                        'Create FinancialTransaction of type order mirroring the total.',
                        'On payment: create Payment + FinancialTransaction of type payment. Mark order paid when fully covered.',
                    ]" :key="i" class="flex items-start gap-3 text-muted-foreground">
                        <span class="flex-shrink-0 w-5 h-5 rounded-full bg-orange-500 text-white text-[11px] font-bold flex items-center justify-center mt-0.5">{{ i+1 }}</span>
                        {{ step }}
                    </li>
                </ol>

                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Public Order Status <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/public/orders/{token}</code></h3>
                <p class="text-sm text-muted-foreground leading-relaxed">Customer-facing status page via QR code on the receipt. No login required. Shows items, totals, payment info, status, cashier name, and order date. Token is a 32-character hex string auto-generated on order creation.</p>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ KITCHEN ══ -->
            <section id="kitchen" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Operations</p>
                <h2 class="text-xl font-bold mb-4">Kitchen Operations</h2>

                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Kitchen Monitor <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/kitchen</code></h3>
                <div class="flex gap-2 flex-wrap my-2">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300">can:update orders</span>
                </div>
                <ul class="text-sm space-y-1.5 mb-5">
                    <li v-for="f in [
                        'Real-time polling order board: pending / preparing / ready',
                        'Queue number, type, table, customer, elapsed time per card',
                        'Portrait / landscape toggle (persisted in localStorage)',
                        'Inline item editing on active orders',
                        'Cancel with reason (logged)',
                        'On completion: sets completed_at; auto-triggers receipt print if enabled',
                        'Completed orders shown below the active queue',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>

                <h3 class="font-semibold text-sm mb-2 pb-2 border-b">Print Pipeline</h3>
                <p class="text-sm text-muted-foreground mb-3">Delivered via Pusher Channels (primary) and Pusher Beams / FCM (backup). An Android printer app receives and processes jobs.</p>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="text-sm w-full">
                        <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-3 py-2 text-left font-semibold">Field</th><th class="px-3 py-2 text-left font-semibold">Content</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in [
                                ['Store info','Name, address, phone, footer (from PrintServiceSetting)'],
                                ['Order info','Number, type, table, date, cashier, customer'],
                                ['Line items','Name, quantity, unit price, line total'],
                                ['Totals','Subtotal, discount, tax (₱0), total, tender, change'],
                                ['QR code','Modes: order_url · facebook · none'],
                            ]" :key="r[0]" class="hover:bg-muted/20">
                                <td class="px-3 py-2 font-medium">{{ r[0] }}</td>
                                <td class="px-3 py-2 text-muted-foreground">{{ r[1] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ FINANCIALS ══ -->
            <section id="financials" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Finance</p>
                <h2 class="text-xl font-bold mb-4">Financial Management</h2>

                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Financial Ledger <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/financial</code></h3>
                <div class="flex gap-2 flex-wrap my-2">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300">can:view reports</span>
                </div>
                <ul class="text-sm space-y-1.5 mb-4">
                    <li v-for="f in [
                        'Paginated transaction log with running balance per entry',
                        'Filter by date range, transaction type, and payment tender',
                        'Toggle to include or exclude asset deductions',
                        'Summary: totals by type, payments by tender, net by tender (in/out/net)',
                        'Cumulative balance as-of end date; balance breakdown per tender',
                        'Create / edit / delete manual entries: expense, income_adjustment, asset_deduction',
                        'Tender is a required field when creating manual entries',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
                <div class="border-l-4 border-orange-400 bg-orange-50 dark:bg-orange-950/20 px-4 py-2.5 rounded-r text-sm text-muted-foreground mb-5">
                    <strong class="text-foreground">Access note:</strong> Only admin and auditor roles can create, edit, or delete manual transactions. Auto-generated <code class="text-xs bg-muted px-1 rounded">order</code> records cannot be edited.
                </div>

                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Bills <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/bills</code></h3>
                <ul class="text-sm space-y-1.5">
                    <li v-for="f in [
                        'Tracks recurring expenses: rent, utilities, subscriptions, loans',
                        'Frequencies: daily, weekly, monthly, quarterly, yearly',
                        'Status: overdue, due_today, upcoming, scheduled, inactive',
                        'Installment mode: split a bill into N payments each with its own due date',
                        'Multi-month forecast view grouped by month',
                        'One-click pay; pay individual installments',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ REPORTS ══ -->
            <section id="reports" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Analytics</p>
                <h2 class="text-xl font-bold mb-2">Reports &amp; Analytics <code class="text-sm font-mono bg-muted px-2 py-0.5 rounded ml-2">/reports</code></h2>
                <div class="flex gap-2 flex-wrap mb-4">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300">can:view reports</span>
                </div>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="text-sm w-full">
                        <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-3 py-2 text-left font-semibold">Tab</th><th class="px-3 py-2 text-left font-semibold">Data</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in [
                                ['Daily Sales','Date, total orders, total sales, total discount'],
                                ['Monthly Sales','Month, total orders, total sales, total discount'],
                                ['Product Sales','Product name, quantity sold, total revenue'],
                                ['Product Daily','Per-product daily breakdown'],
                                ['Profit & Loss','Revenue vs. cost-of-goods analysis'],
                                ['Daily Chart','Bar/line chart of daily sales'],
                                ['Monthly Chart','Bar/line chart of monthly sales'],
                                ['Sales Heatmap','Hour × day activity heatmap'],
                                ['FT Summary','Financial transaction totals for a period'],
                                ['Inventory Transactions','Full stock movement log'],
                                ['Inventory Valuation','Current stock value at cost'],
                                ['Analytics','Deeper analytics sub-component'],
                            ]" :key="r[0]" class="hover:bg-muted/20">
                                <td class="px-3 py-2 font-medium whitespace-nowrap">{{ r[0] }}</td>
                                <td class="px-3 py-2 text-muted-foreground">{{ r[1] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ INVENTORY ══ -->
            <section id="inventory" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Stock</p>
                <h2 class="text-xl font-bold mb-2">Inventory Management <code class="text-sm font-mono bg-muted px-2 py-0.5 rounded ml-2">/inventory</code></h2>
                <div class="flex gap-2 flex-wrap mb-4">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300">can:view inventory</span>
                </div>
                <ul class="text-sm space-y-1.5 mb-3">
                    <li v-for="f in [
                        'Item types: ingredient · tool · equipment · supply (color-coded)',
                        'Fields: name, unit, current quantity, min quantity (low-stock threshold), cost per unit',
                        'Filter by type; toggle to show only low-stock items',
                        'Manual adjustments: stock_in or stock_out with notes',
                        'Full transaction history per ingredient (type, delta, old/new qty, user, order ref)',
                        'Low-stock quick-list at GET /api/v1/inventory/low-stock',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
                <div class="border-l-4 border-orange-400 bg-orange-50 dark:bg-orange-950/20 px-4 py-2.5 rounded-r text-sm text-muted-foreground">
                    <strong class="text-foreground">Recipe integration:</strong> Each product has a recipe mapping ingredients to quantities consumed per unit sold. When an order item is created, stock is auto-deducted.
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ PRODUCTS ══ -->
            <section id="products" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Catalogue</p>
                <h2 class="text-xl font-bold mb-4">Products &amp; Menu</h2>
                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Product Management <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/products</code></h3>
                <div class="flex gap-2 mb-3"><span class="text-xs font-semibold px-2 py-0.5 rounded bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300">role:admin</span></div>
                <ul class="text-sm space-y-1.5 mb-5">
                    <li v-for="f in [
                        'Full CRUD: name, description, price, cost, image, category, active status',
                        'Assign modifiers (add-ons) with individual prices',
                        'Calculate cost from linked recipe ingredients',
                        'Add / delete product categories',
                        'Bulk product search',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
                <h3 class="font-semibold text-sm mb-1 pb-2 border-b">Price Management <code class="text-xs font-mono bg-muted px-1.5 py-0.5 rounded ml-1">/settings/prices</code></h3>
                <p class="text-sm text-muted-foreground">Inline bulk-edit of product prices without entering the full product editor. Useful for quick price adjustments across many items at once.</p>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ DISTRIBUTION ══ -->
            <section id="distribution" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Profit Sharing</p>
                <h2 class="text-xl font-bold mb-2">Distribution &amp; Profit Sharing <code class="text-sm font-mono bg-muted px-2 py-0.5 rounded ml-2">/distribution</code></h2>
                <div class="flex gap-2 mb-4"><span class="text-xs font-semibold px-2 py-0.5 rounded bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300">role:admin</span></div>
                <div class="overflow-x-auto rounded-lg border mb-5">
                    <table class="text-sm w-full">
                        <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-3 py-2 text-left font-semibold">Tab</th><th class="px-3 py-2 text-left font-semibold">Purpose</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in [
                                ['Distribution','Preview current-period computed share per shareholder'],
                                ['Shareholders','CRUD for shareholders (people or entities)'],
                                ['Incentives','Manage incentive/bonus calculation rules'],
                                ['Trends','Historical trend charts of distributions'],
                                ['History','Frozen snapshots of past distributions'],
                            ]" :key="r[0]" class="hover:bg-muted/20">
                                <td class="px-3 py-2 font-medium">{{ r[0] }}</td>
                                <td class="px-3 py-2 text-muted-foreground">{{ r[1] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h3 class="font-semibold text-sm mb-2 pb-2 border-b">Snapshot Workflow</h3>
                <ol class="text-sm space-y-2">
                    <li v-for="(step, i) in [
                        'Admin selects basis (sales vs. profit), date range, and filters.',
                        'System computes share per shareholder using royalty rules and product ownership.',
                        'Admin saves a DistributionSnapshot to freeze the computation.',
                        'Record payouts against the snapshot. Each payout auto-creates a FinancialTransaction of type payout_share.',
                    ]" :key="i" class="flex items-start gap-3 text-muted-foreground">
                        <span class="flex-shrink-0 w-5 h-5 rounded-full bg-orange-500 text-white text-[11px] font-bold flex items-center justify-center mt-0.5">{{ i+1 }}</span>
                        {{ step }}
                    </li>
                </ol>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ HRIS ══ -->
            <section id="hris" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Human Resources</p>
                <h2 class="text-xl font-bold mb-2">HRIS &amp; Payroll <code class="text-sm font-mono bg-muted px-2 py-0.5 rounded ml-2">/hris</code></h2>
                <div class="flex gap-2 mb-4"><span class="text-xs font-semibold px-2 py-0.5 rounded bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300">role:admin</span></div>
                <h3 class="font-semibold text-sm mb-2">Employees</h3>
                <ul class="text-sm space-y-1.5 mb-5">
                    <li v-for="f in [
                        'Fields: name, position, employment type (full-time / part-time / contractual)',
                        'Salary types: monthly · daily · hourly with base rate',
                        'Track hire date, active status, and notes',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
                <h3 class="font-semibold text-sm mb-2">Payroll</h3>
                <ul class="text-sm space-y-1.5 mb-4">
                    <li v-for="f in [
                        'Fields: employee, period start/end, days worked, gross pay, deductions, net pay',
                        'Status: pending → approved → paid',
                        'Marking a payroll record as paid auto-creates a FinancialTransaction of type payroll',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
                <div class="border-l-4 border-orange-400 bg-orange-50 dark:bg-orange-950/20 px-4 py-2.5 rounded-r text-sm text-muted-foreground">
                    <strong class="text-foreground">Integration:</strong> Payroll entries appear automatically in the Financial Ledger as outflow under the <code class="text-xs bg-muted px-1 rounded">payroll</code> type.
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ SETTINGS ══ -->
            <section id="settings" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Configuration</p>
                <h2 class="text-xl font-bold mb-4">Settings</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="text-sm w-full">
                        <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Page</th>
                                <th class="px-3 py-2 text-left font-semibold">Route</th>
                                <th class="px-3 py-2 text-left font-semibold">Access</th>
                                <th class="px-3 py-2 text-left font-semibold">Purpose</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in [
                                ['Profile','/settings/profile','Any auth','Name, email, delete account'],
                                ['Security','/settings/security','Any auth','Change password'],
                                ['Appearance','/settings/appearance','Any auth','Light / dark UI theme'],
                                ['Users','/settings/users','admin','Create/edit/delete users; assign roles'],
                                ['Payment Tenders','/settings/payment-tenders','admin','Configure payment methods; enable/disable, display order'],
                                ['Print Service','/settings/print-service','admin','Pusher keys, store info, auto-print, QR type, channel'],
                                ['Logo','/settings/logo','admin','Upload/remove restaurant logo; update brand name'],
                                ['Kitchen','/settings/kitchen','admin','Kitchen monitor configuration'],
                                ['System Clock','/settings/clock','admin','Override date/time for backdated entries'],
                                ['Advertisements','/settings/advertisements','admin','Upload and manage promotional images'],
                                ['Page Content','/settings/page-content','admin','Edit, reorder, toggle public-facing page sections'],
                                ['Media Library','/settings/media','admin','Upload, view, and delete media files'],
                                ['System Reset','/settings/system','admin','System and factory reset actions'],
                                ['Prices','/settings/prices','admin','Bulk product price editor'],
                                ['HRIS Settings','/settings/hris','admin','Pay period defaults, deduction rates'],
                            ]" :key="r[0]" class="hover:bg-muted/20">
                                <td class="px-3 py-2 font-medium whitespace-nowrap">{{ r[0] }}</td>
                                <td class="px-3 py-2"><code class="text-xs font-mono">{{ r[1] }}</code></td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span :class="r[2] === 'admin' ? 'text-xs font-semibold px-2 py-0.5 rounded bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300' : 'text-xs text-muted-foreground'">{{ r[2] }}</span>
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">{{ r[3] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ TOOLS ══ -->
            <section id="tools" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Developer</p>
                <h2 class="text-xl font-bold mb-2">Tools — SQL Console <code class="text-sm font-mono bg-muted px-2 py-0.5 rounded ml-2">/tools</code></h2>
                <div class="flex gap-2 mb-4"><span class="text-xs font-semibold px-2 py-0.5 rounded bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300">role:admin</span></div>
                <p class="text-sm text-muted-foreground mb-3 leading-relaxed">Read-only database console for inspection and debugging. Backend enforces SELECT-only queries.</p>
                <ul class="text-sm space-y-1.5">
                    <li v-for="f in [
                        'Sidebar: all tables with approximate row counts, filterable by name',
                        'Expand any table to see columns (name, type, nullable, key)',
                        'Click a table name to auto-load a SELECT * LIMIT 100 query',
                        'Run arbitrary SQL (read-only); results show row count and elapsed time',
                        'Export results as CSV, PDF (browser print popup), or Excel (.xlsx via SheetJS)',
                        'Keyboard shortcut: Ctrl/⌘+Enter to run query',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ PARCELS ══ -->
            <section id="parcels" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Module · Logistics</p>
                <h2 class="text-xl font-bold mb-2">Parcel Tracking <code class="text-sm font-mono bg-muted px-2 py-0.5 rounded ml-2">/parcels</code></h2>
                <div class="flex gap-2 mb-4"><span class="text-xs px-2 py-0.5 rounded border bg-muted/50 text-muted-foreground">Any auth (manage: admin/auditor)</span></div>
                <ul class="text-sm space-y-1.5">
                    <li v-for="f in [
                        'Tracks physical parcels assigned to personnel — delivery equipment, supply bundles',
                        'Fields: parcel number, name, assigned personnel, status (in/out/complete), notes',
                        'Items within a parcel: item name, quantity, status, status_updated_at',
                        'Create/edit/delete parcels (admin/auditor); toggle individual item in/out',
                        'Filter by status, search by name or number',
                        'Summary tiles: total, in, out, complete counts',
                    ]" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 mt-0.5 shrink-0">→</span> {{ f }}
                    </li>
                </ul>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ COMMANDS ══ -->
            <section id="commands" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">CLI Reference</p>
                <h2 class="text-xl font-bold mb-4">Artisan Commands</h2>

                <div v-for="cmd in [
                    {
                        name: 'ft:sync-orders',
                        flags: ['--dry-run', '--order={id}'],
                        desc: 'Corrects order-type FT records whose amount no longer matches the linked order total (e.g. after a post-creation discount change). Shows a diff table, prompts for confirmation, updates in a single DB transaction.',
                    },
                    {
                        name: 'kitchen:clear',
                        flags: ['--except=id1,id2', '--dry-run'],
                        desc: 'Batch-completes all active kitchen orders (pending/preparing/ready). Useful for end-of-day cleanup or resetting after testing.',
                    },
                    {
                        name: 'inventory:backfill-deductions',
                        flags: ['--date=Y-m-d'],
                        desc: 'Retroactively deducts inventory for orders placed before automatic deduction was enabled. Safe to re-run — skips orders that already have a stock_out transaction.',
                    },
                ]" :key="cmd.name" class="mb-5 border rounded-lg p-4 bg-card">
                    <div class="flex items-center gap-2 flex-wrap mb-2">
                        <code class="text-sm font-mono font-bold text-orange-600 dark:text-orange-400">{{ cmd.name }}</code>
                        <code v-for="f in cmd.flags" :key="f" class="text-xs font-mono bg-muted border px-1.5 py-0.5 rounded text-muted-foreground">{{ f }}</code>
                    </div>
                    <p class="text-sm text-muted-foreground leading-relaxed">{{ cmd.desc }}</p>
                </div>
            </section>

            <hr class="my-10 border-border">

            <!-- ══ ROLES ══ -->
            <section id="roles" class="mb-16">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">Access Control</p>
                <h2 class="text-xl font-bold mb-4">Roles &amp; Access Matrix</h2>
                <div class="overflow-x-auto rounded-lg border">
                    <table class="text-sm w-full">
                        <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr><th class="px-3 py-2 text-left font-semibold">Role / Permission</th><th class="px-3 py-2 text-left font-semibold">Pages &amp; Capabilities</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in [
                                ['admin','All pages. Create/edit/delete users, products, settings, manual FTs, distributions, payroll. System reset.'],
                                ['auditor','Financial ledger (create/edit/delete manual FTs), bills, reports, parcels management.'],
                                ['can:create orders','POS cashier terminal.'],
                                ['can:update orders','Kitchen monitor — advance order status.'],
                                ['can:view reports','Financial ledger, bills, all report tabs.'],
                                ['can:view inventory','Inventory management page.'],
                                ['Any auth','Dashboard, parcels (read), parcel detail, profile/security/appearance settings.'],
                                ['Public','Welcome, menu, public order status, printing architecture, auth pages.'],
                            ]" :key="r[0]" class="hover:bg-muted/20">
                                <td class="px-3 py-2 font-mono text-xs font-semibold whitespace-nowrap">{{ r[0] }}</td>
                                <td class="px-3 py-2 text-muted-foreground">{{ r[1] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ══ RECOMMENDATIONS ══ -->
            <section id="recommendations" class="mb-0">
                <div class="rounded-xl bg-slate-900 dark:bg-slate-950 p-8 -mx-2">
                    <p class="text-[10px] font-bold tracking-widest uppercase text-orange-400 mb-1">Financial Controls</p>
                    <h2 class="text-xl font-bold text-slate-100 mb-2">Cashiering &amp; Deposit Improvement Plan</h2>
                    <p class="text-sm text-slate-400 mb-6 leading-relaxed max-w-lg">The system records sales and payments accurately but lacks controls to hold individual cashiers accountable and to reconcile physical cash with the bank — the root cause of observed daily variances.</p>

                    <div class="grid sm:grid-cols-2 gap-4">
                        <div v-for="rec in recommendations" :key="rec.title" class="bg-slate-800/60 border border-slate-700/50 rounded-lg p-5">
                            <div class="flex items-start gap-2 mb-2">
                                <span :class="['text-[10px] font-bold px-2 py-0.5 rounded tracking-wider', rec.cls]">{{ rec.priority }}</span>
                                <h3 class="text-sm font-bold text-slate-200 leading-snug">{{ rec.title }}</h3>
                            </div>
                            <p class="text-xs text-slate-400 leading-relaxed mb-3">{{ rec.desc }}</p>
                            <div class="border-t border-slate-700/50 pt-2.5">
                                <p class="text-[11px] font-bold text-orange-400 mb-1">What to build</p>
                                <p class="text-xs text-slate-500 leading-relaxed">{{ rec.build }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        </main>
    </div>
</template>
