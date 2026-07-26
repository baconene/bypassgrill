<script setup lang="ts">
import { markRaw } from 'vue'
import { Link, Head } from '@inertiajs/vue3'
import {
    ShoppingCart, ChefHat, DollarSign, BarChart3, Package,
    UtensilsCrossed, PieChart, Users, Settings, Database,
    Archive, Terminal, ShieldCheck, ArrowRight,
} from 'lucide-vue-next'

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Documentation', href: '/documentation' },
        ],
    },
})

const modules = [
    {
        id: 'pos',
        icon: markRaw(ShoppingCart),
        eyebrow: 'Sales',
        title: 'Point of Sale',
        route: '/pos',
        accessText: 'can:create orders',
        accessCls: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
        description: 'The central sales terminal where all revenue originates. Cashiers browse the live menu, build orders with optional add-ons, apply discounts, collect payment across any configured tender, and trigger receipt printing from a single screen. An offline queue ensures no orders are lost when connectivity drops.',
        features: [
            'Browse products by category or keyword with add-on modifiers',
            'Order types: Dine In (table), Takeout, Delivery (with address)',
            'Multi-tender payment with automatic change calculation',
            'Offline queue — orders queue locally and sync on reconnect',
            'Inventory auto-deducted per product recipe on order creation',
        ],
    },
    {
        id: 'kitchen',
        icon: markRaw(ChefHat),
        eyebrow: 'Operations',
        title: 'Kitchen Monitor',
        route: '/kitchen',
        accessText: 'can:update orders',
        accessCls: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
        description: 'A real-time order board for kitchen staff to track and advance orders through the preparation pipeline. Orders appear instantly via polling and staff can update status, edit items inline, or cancel with a reason. Receipt printing fires automatically on completion when enabled.',
        features: [
            'Live polling board: pending → preparing → ready → complete',
            'Portrait and landscape layout modes (saved per browser)',
            'Inline item editing and cancel-with-reason on active orders',
            'Auto-print receipt on completion (configurable in Print Settings)',
            'Completed orders visible below the active queue',
        ],
    },
    {
        id: 'financial',
        icon: markRaw(DollarSign),
        eyebrow: 'Finance',
        title: 'Financial Management',
        route: '/financial',
        accessText: 'can:view reports',
        accessCls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300',
        description: 'The primary cash-flow ledger for the business. Every payment, manual expense, payroll disbursement, and profit-sharing payout flows through here with a chronological running balance. Filters by date range, transaction type, and payment tender allow precise period analysis.',
        features: [
            'Paginated ledger with running balance per transaction',
            'Period summary: totals by type, by tender, net-by-tender breakdown',
            'Create/edit/delete manual entries: expense, income adjustment, asset deduction',
            'Bills tracker for recurring expenses with installment support',
            'Cumulative balance by tender as of any end date',
        ],
    },
    {
        id: 'reports',
        icon: markRaw(BarChart3),
        eyebrow: 'Analytics',
        title: 'Reports & Analytics',
        route: '/reports',
        accessText: 'can:view reports',
        accessCls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300',
        description: 'A multi-tab analytics hub providing a complete view of business performance. Covers daily and monthly sales trends, per-product revenue, inventory valuation, financial transaction summaries, and a granular sales activity heatmap segmented by hour and day.',
        features: [
            'Daily and monthly sales totals with order counts and discounts',
            'Product sales ranking by quantity sold and revenue generated',
            'Profit & Loss: revenue vs. cost-of-goods analysis',
            'Inventory transactions log and stock valuation at cost',
            'Sales heatmap: activity intensity by hour and day of week',
        ],
    },
    {
        id: 'inventory',
        icon: markRaw(Package),
        eyebrow: 'Stock',
        title: 'Inventory',
        route: '/inventory',
        accessText: 'can:view inventory',
        accessCls: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
        description: 'Tracks all stock-level items used in production. Each item is linked to product recipes so that inventory is automatically deducted each time an order is placed. Manual stock adjustments are available with notes, and a full transaction history is maintained per item.',
        features: [
            'Item types: ingredient, tool, equipment, supply — color-coded',
            'Low-stock threshold alerts configurable per item',
            'Manual stock_in / stock_out adjustments with notes',
            'Full transaction history per item with order reference and user',
            'Auto-deduction on order creation via linked product recipes',
        ],
    },
    {
        id: 'products',
        icon: markRaw(UtensilsCrossed),
        eyebrow: 'Catalogue',
        title: 'Products & Menu',
        route: '/products',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        description: 'The full menu catalogue management interface. Admins create products, set prices, attach modifiers (add-ons with individual prices), organize categories, and upload images. Product cost can be calculated automatically from linked recipe ingredients to power the Profit & Loss report.',
        features: [
            'Full CRUD: name, description, price, cost, image, category, status',
            'Modifiers (add-ons) attached per product with individual pricing',
            'Recipe-based automatic cost calculation for P&L accuracy',
            'Add and delete product categories',
            'Bulk price editor at /settings/prices for quick multi-item changes',
        ],
    },
    {
        id: 'distribution',
        icon: markRaw(PieChart),
        eyebrow: 'Profit Sharing',
        title: 'Distribution',
        route: '/distribution',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        description: 'Manages revenue or profit sharing among business shareholders using configurable royalty rules and incentive bonuses. Admins compute a period distribution, review the breakdown per shareholder, freeze it as a snapshot, and record payouts — each of which creates a payout_share financial transaction automatically.',
        features: [
            'Configurable royalty rules and incentive bonuses per shareholder',
            'Basis: total sales or net profit over any date range',
            'Snapshot workflow: compute → freeze → pay out',
            'Each payout auto-creates a payout_share financial transaction',
            'Historical snapshots and trend charts',
        ],
    },
    {
        id: 'hris',
        icon: markRaw(Users),
        eyebrow: 'Human Resources',
        title: 'HRIS & Payroll',
        route: '/hris',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        description: 'Basic HR and payroll management fully integrated with the financial ledger. Employee records capture employment type, salary structure, and hire history. Payroll periods are prepared, reviewed, and approved — marking a payroll record as paid automatically creates an outflow entry in financial transactions.',
        features: [
            'Employee profiles: employment type, salary type, base rate, hire date',
            'Payroll periods: days worked, gross pay, deductions, net pay',
            'Status workflow: pending → approved → paid',
            'Marking paid auto-creates a payroll FinancialTransaction',
        ],
    },
    {
        id: 'settings',
        icon: markRaw(Settings),
        eyebrow: 'Configuration',
        title: 'Settings',
        route: '/settings/profile',
        accessText: 'varies by section',
        accessCls: 'border bg-muted/50 text-muted-foreground',
        description: 'Centralised configuration for all system behaviour. Covers user accounts and role assignments, payment tender setup, receipt printer configuration (Pusher keys, store details, QR behavior), branding (logo, advertisements), kitchen monitor settings, system clock overrides for backdated entries, and factory reset.',
        features: [
            'User management: create, assign roles, and delete accounts',
            'Payment tenders: enable/disable, set display order',
            'Print service: store info, Pusher keys, auto-print toggle, QR mode',
            'System clock override for backdated testing or corrections',
            'Logo, advertisements, and public-facing page content',
        ],
    },
    {
        id: 'tools',
        icon: markRaw(Database),
        eyebrow: 'Developer',
        title: 'SQL Console',
        route: '/tools',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        description: 'A read-only database inspection tool for administrators. Admins browse all tables with row counts and column schemas, write and run arbitrary SELECT queries, and export results as CSV, Excel (.xlsx), or PDF. The backend enforces SELECT-only execution — no writes are ever permitted.',
        features: [
            'Table browser with approximate row counts and column type info',
            'Run any SELECT query — backend blocks non-SELECT statements',
            'Results show row count, elapsed time, and truncation notice at 500 rows',
            'Export results as CSV, PDF (browser print), or Excel (.xlsx)',
        ],
    },
    {
        id: 'parcels',
        icon: markRaw(Archive),
        eyebrow: 'Logistics',
        title: 'Parcels',
        route: '/parcels',
        accessText: 'Any auth (manage: admin/auditor)',
        accessCls: 'border bg-muted/50 text-muted-foreground',
        description: 'Tracks physical packages and bundles assigned to staff members. Each parcel contains individual items each with their own in/out status. Useful for tracking delivery equipment, supply batches, or any physical goods that leave and return to the premises.',
        features: [
            'Parcel lifecycle tracked: in → out → complete with assigned personnel',
            'Item-level status (in/out) within each parcel, with timestamp',
            'Filter by status, search by parcel number or name',
            'Summary tiles: total, in, out, and complete counts',
        ],
    },
    {
        id: 'commands',
        icon: markRaw(Terminal),
        eyebrow: 'CLI',
        title: 'Artisan Commands',
        route: 'CLI only',
        accessText: 'Server access required',
        accessCls: 'border bg-muted/50 text-muted-foreground',
        description: 'A set of artisan commands for maintenance tasks that cannot be performed through the UI. These include correcting financial transaction amounts after orders are edited, clearing the kitchen board at end-of-day, and retroactively applying inventory deductions for historical orders.',
        features: [
            'ft:sync-orders — align FT amounts with updated order totals',
            'kitchen:clear — batch-complete all active kitchen orders',
            'inventory:backfill-deductions — retroactive stock deduction for past orders',
            'All commands support --dry-run to preview changes safely',
        ],
    },
    {
        id: 'roles',
        icon: markRaw(ShieldCheck),
        eyebrow: 'Access Control',
        title: 'Roles & Permissions',
        route: '/settings/users',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        description: 'Access control is managed via Spatie Laravel Permission. Each user is assigned one or more roles, and each role carries a defined set of permissions. The admin role has unrestricted access. Restricted roles like cashier, kitchen, and auditor are scoped to only what their function requires.',
        features: [
            'Roles managed in Settings > Users by admins',
            'admin: full access including destructive and financial operations',
            'auditor: financial ledger, bills, reports, parcel management',
            'cashier: POS only — kitchen: kitchen monitor only',
        ],
    },
]

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
</script>

<template>
    <Head title="Documentation" />

    <div class="max-w-5xl px-6 py-8 mx-auto">

        <!-- Page header -->
        <div class="mb-8">
            <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">System Reference</p>
            <h1 class="text-2xl font-bold mb-2">Bypass Grill POS — Documentation</h1>
            <p class="text-muted-foreground text-sm leading-relaxed max-w-2xl">
                Complete reference for all system modules. Each card below describes a module's purpose and key capabilities. Click <strong>View Full Details</strong> to open the dedicated page for that module.
            </p>
        </div>

        <!-- Stack info strip -->
        <div class="flex flex-wrap gap-3 mb-8 pb-8 border-b">
            <div v-for="item in [
                { label: 'Backend', value: 'Laravel 11 + Inertia.js' },
                { label: 'Frontend', value: 'Vue 3 TypeScript' },
                { label: 'Auth', value: 'Spatie Permissions' },
                { label: 'Real-time', value: 'Pusher Channels' },
                { label: 'Timezone', value: 'Asia/Manila' },
            ]" :key="item.label" class="border rounded-lg px-4 py-2 bg-card text-sm">
                <span class="text-muted-foreground text-xs">{{ item.label }}: </span>
                <span class="font-semibold">{{ item.value }}</span>
            </div>
        </div>

        <!-- Module cards -->
        <div class="grid sm:grid-cols-2 gap-5 mb-12">
            <div v-for="mod in modules" :key="mod.id"
                class="border rounded-xl bg-card p-5 flex flex-col hover:border-orange-300 dark:hover:border-orange-700 transition-colors">

                <!-- Card header -->
                <div class="flex items-start gap-3 mb-3">
                    <div class="w-10 h-10 rounded-lg bg-orange-50 dark:bg-orange-950/30 flex items-center justify-center shrink-0">
                        <component :is="mod.icon" class="h-5 w-5 text-orange-500" />
                    </div>
                    <div>
                        <p class="text-[10px] font-bold tracking-wider uppercase text-muted-foreground leading-none mb-0.5">{{ mod.eyebrow }}</p>
                        <h2 class="font-bold text-base leading-tight">{{ mod.title }}</h2>
                    </div>
                </div>

                <!-- Access + route -->
                <div class="flex gap-2 flex-wrap mb-3">
                    <span :class="['text-xs font-semibold px-2 py-0.5 rounded', mod.accessCls]">{{ mod.accessText }}</span>
                    <span v-if="mod.route !== 'CLI only'" class="text-xs font-mono px-2 py-0.5 rounded border bg-muted/50 text-muted-foreground">{{ mod.route }}</span>
                </div>

                <!-- Description -->
                <p class="text-sm text-muted-foreground leading-relaxed mb-3">{{ mod.description }}</p>

                <!-- Features -->
                <ul class="text-sm space-y-1.5 mb-4 flex-1">
                    <li v-for="f in mod.features" :key="f" class="flex items-start gap-2 text-muted-foreground">
                        <span class="text-orange-500 shrink-0 mt-0.5 text-xs font-bold">→</span>
                        <span>{{ f }}</span>
                    </li>
                </ul>

                <!-- Detail link -->
                <Link :href="'/documentation/' + mod.id"
                    class="mt-auto flex items-center gap-1.5 text-sm font-semibold text-orange-600 dark:text-orange-400 hover:underline">
                    View Full Details
                    <ArrowRight class="h-3.5 w-3.5" />
                </Link>
            </div>
        </div>

        <!-- Recommendations -->
        <div class="rounded-xl bg-slate-900 dark:bg-slate-950 p-8 -mx-2">
            <p class="text-[10px] font-bold tracking-widest uppercase text-orange-400 mb-1">Financial Controls</p>
            <h2 class="text-xl font-bold text-slate-100 mb-2">Cashiering &amp; Deposit Improvement Plan</h2>
            <p class="text-sm text-slate-400 mb-6 leading-relaxed max-w-xl">
                The system records sales and payments accurately but lacks the controls to hold individual cashiers accountable and to reconcile physical cash with the bank — the root cause of observed daily variances.
            </p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div v-for="rec in recommendations" :key="rec.title"
                    class="bg-slate-800/60 border border-slate-700/50 rounded-lg p-5">
                    <div class="flex items-start gap-2 mb-2">
                        <span :class="['text-[10px] font-bold px-2 py-0.5 rounded tracking-wider shrink-0', rec.cls]">{{ rec.priority }}</span>
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

    </div>
</template>
