<script setup lang="ts">
import { computed, markRaw } from 'vue'
import { Link, Head } from '@inertiajs/vue3'
import {
    ShoppingCart, ChefHat, DollarSign, BarChart3, Package,
    UtensilsCrossed, PieChart, Users, Settings, Database,
    Archive, Terminal, ShieldCheck, ArrowLeft,
} from 'lucide-vue-next'

const props = defineProps<{ module: string }>()

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Documentation', href: '/documentation' },
        ],
    },
})

interface Section {
    title?: string
    type: 'prose' | 'features' | 'steps' | 'table' | 'callout' | 'info-grid' | 'commands' | 'formula'
    content?: string
    items?: string[]
    headers?: string[]
    rows?: string[][]
    label?: string
    cards?: { label: string; value: string }[]
    commands?: { name: string; flags: string[]; desc: string }[]
    formula?: string
    formulaNote?: string
}

interface ModuleDoc {
    eyebrow: string
    title: string
    icon: any
    route: string
    accessText: string
    accessCls: string
    intro: string
    sections: Section[]
}

const allModules: Record<string, ModuleDoc> = {
    pos: {
        eyebrow: 'Module · Sales',
        title: 'Point of Sale & Orders',
        icon: markRaw(ShoppingCart),
        route: '/pos',
        accessText: 'can:create orders',
        accessCls: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
        intro: 'The POS dashboard is the core transaction interface of the system. Every peso of revenue starts here. Cashiers operate the terminal to create orders, collect payment, and print receipts — all without leaving a single screen. The system is designed for high-speed, low-error operation at a busy service counter.',
        sections: [
            {
                title: 'Building an Order',
                type: 'features',
                items: [
                    'Products are displayed in a scrollable grid, grouped by category. A search bar filters across all product names in real time.',
                    'Tapping a product adds it to the cart. Each item can have modifiers (add-ons) selected — for example, extra rice or a sauce upgrade — each with its own price.',
                    'Order type is selected before submission: Dine In requires a table number, Takeout requires nothing extra, Delivery requires customer name, contact, and full address.',
                    'A discount amount (flat peso value) can be applied to the whole order before checkout.',
                    'The cart shows running subtotal, applied discount, and the final total.',
                ],
            },
            {
                title: 'Payment & Submission',
                type: 'features',
                items: [
                    'Payment tender is selected from the configured list (cash, GCash, card, etc.). Multiple tenders can cover a single order.',
                    'Amount tendered is entered and the system calculates change automatically.',
                    'On submit, the order is created, inventory is deducted, a queue number is assigned (dine-in/takeout), and a FinancialTransaction of type payment is recorded.',
                    'A receipt is printed to the configured printer via Pusher. The cashier can also trigger a re-print at any time from the order.',
                ],
            },
            {
                title: 'Order Creation — System Flow',
                type: 'steps',
                items: [
                    'Order record created with status=pending and payment_status=pending.',
                    'Sequential QueueNumber assigned for dine-in and takeout orders.',
                    'For each cart item: ingredient availability checked, stock deducted via recipe, modifiers attached.',
                    'Totals calculated: subtotal − discount_amount. Tax is currently zero.',
                    'FinancialTransaction of type order created, mirroring the total amount.',
                    'Payment recorded: FinancialTransaction of type payment created. Order marked paid when fully covered.',
                ],
            },
            {
                title: 'Offline Mode',
                type: 'prose',
                content: 'The POS operates offline when the network is unavailable. Orders and payments are stored in a local queue (offlineQueue utility) and synced to the server automatically when connectivity returns. The offline banner appears at the top of the page when the device is disconnected. Cashiers can continue processing orders without interruption.',
            },
            {
                title: 'Customer-Facing Order Status',
                type: 'prose',
                content: 'Every order has a unique 32-character hex public token generated at creation time. This token is embedded in a QR code on the printed receipt. Customers can scan the QR to open /public/orders/{token} — a no-auth page showing their order items, totals, payment summary, and current status in real time. The QR mode can be set to order_url, facebook page URL, or none in Print Settings.',
            },
        ],
    },

    kitchen: {
        eyebrow: 'Module · Operations',
        title: 'Kitchen Monitor',
        icon: markRaw(ChefHat),
        route: '/kitchen',
        accessText: 'can:update orders',
        accessCls: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
        intro: 'The Kitchen Monitor is the real-time order board for kitchen staff. It shows all active orders across the current service period, with clear visual grouping by status. Staff advance orders through a status pipeline, edit items, or cancel with a reason. The page refreshes automatically via polling — no manual reload is needed.',
        sections: [
            {
                title: 'Order Status Pipeline',
                type: 'steps',
                items: [
                    'pending — order just created, not yet acknowledged by kitchen.',
                    'preparing — kitchen has started on the order.',
                    'ready — order is plated and waiting for pickup or delivery to table.',
                    'complete — order handed over. Triggers receipt auto-print if enabled. Sets completed_at timestamp.',
                ],
            },
            {
                title: 'What Each Order Card Shows',
                type: 'features',
                items: [
                    'Queue number (large, for easy identification across the counter)',
                    'Order type (Dine In / Takeout / Delivery) and table number or delivery address',
                    'Customer name and contact (for delivery and takeout)',
                    'Full item list with quantities and any modifiers',
                    'Order notes entered by the cashier',
                    'Time elapsed since order creation',
                    'Payment status badge (paid / unpaid)',
                ],
            },
            {
                title: 'Inline Editing',
                type: 'prose',
                content: 'Kitchen staff can edit order items directly from the monitor card without going to a separate edit screen. Items can have their quantity changed or be removed entirely. This handles situations where an item runs out mid-service without requiring the cashier to issue a refund or cancel the whole order.',
            },
            {
                title: 'Cancel with Reason',
                type: 'prose',
                content: 'Orders can be cancelled from the kitchen monitor. A reason must be entered before cancellation is confirmed. The reason is logged with the order and visible in the order detail view for future reference.',
            },
            {
                title: 'Print Pipeline',
                type: 'table',
                headers: ['Channel', 'Mechanism', 'Priority'],
                rows: [
                    ['Pusher Channels', 'Real-time push to print channel. Android printer app listens for the print event.', 'Primary'],
                    ['Pusher Beams (FCM)', 'Push notification to print-jobs interest. Wakes Android device if screen is off.', 'Backup'],
                ],
            },
            {
                title: 'Receipt Content',
                type: 'table',
                headers: ['Section', 'Fields'],
                rows: [
                    ['Store', 'Name, address, phone, footer text (from Print Settings)'],
                    ['Order', 'Number, type, table, date/time, cashier name, customer info'],
                    ['Items', 'Product name, quantity, unit price, line total'],
                    ['Totals', 'Subtotal, discount, tax (₱0.00), total, tender type, change'],
                    ['QR', 'Order status URL, Facebook page, or none (configurable)'],
                ],
            },
            {
                title: 'Layout Modes',
                type: 'prose',
                content: 'The monitor supports portrait and landscape display orientations. The selected orientation is saved in localStorage and persists across page loads and browser sessions. This allows the kitchen display to be set once per device without reconfiguring after refreshes.',
            },
        ],
    },

    financial: {
        eyebrow: 'Module · Finance',
        title: 'Financial Management',
        icon: markRaw(DollarSign),
        route: '/financial',
        accessText: 'can:view reports',
        accessCls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300',
        intro: 'The Financial page is the authoritative cash-flow record for the business. It aggregates every money movement — from customer payments to staff payroll to shareholder distributions — into a single chronological ledger with a running balance. The summary panel breaks down totals by transaction type and by payment tender, giving both a period view and a cumulative balance.',
        sections: [
            {
                title: 'Transaction Types',
                type: 'table',
                headers: ['Type', 'Direction', 'Created by', 'Notes'],
                rows: [
                    ['payment', 'IN', 'Auto — PaymentService', 'Created for every customer payment. One per payment tender used.'],
                    ['income_adjustment', 'IN', 'Manual — admin/auditor', 'Used for cash collected outside normal orders (shortfalls recovered, extra income).'],
                    ['expense', 'OUT', 'Manual — admin/auditor', 'Operational disbursements: supplies, utilities, repairs, etc.'],
                    ['payroll', 'OUT', 'Auto — HRIS when marked paid', 'Created automatically when a payroll record is marked as paid.'],
                    ['asset_deduction', 'OUT', 'Manual — admin/auditor', 'Capital or asset-related cash outflows.'],
                    ['payout_share', 'OUT', 'Auto — Distribution payout', 'Created automatically when a shareholder payout is recorded.'],
                    ['order', 'Excluded', 'Auto — OrderService', 'Mirror of order total. Excluded from ledger display and net calculation.'],
                ],
            },
            {
                title: 'Net Balance Formula',
                type: 'formula',
                formula: 'Net = payments + income_adjustments − expenses − payroll − asset_deductions − payout_shares',
                formulaNote: 'The running balance is computed in chronological order. The opening balance for a filtered period is calculated as the net of all transactions before the start date.',
            },
            {
                title: 'Running Balance',
                type: 'prose',
                content: 'The running balance shown next to each transaction is calculated by iterating through all transactions in chronological order, starting from an opening balance. When a date range filter is applied, the opening balance equals the net of all transactions before the start date. This means the balance shown is always correct for the filtered period, not just the period total.',
            },
            {
                title: 'Ledger Filters',
                type: 'features',
                items: [
                    'Date range: filter to any start and end date. Opening balance is recalculated automatically.',
                    'Transaction type: show only payments, expenses, payroll, income adjustments, etc.',
                    'Payment tender: show only transactions assigned to a specific tender (cash, GCash, etc.).',
                    'Include/exclude asset deductions: toggle whether asset_deduction entries appear in the balance.',
                ],
            },
            {
                title: 'Summary Panel',
                type: 'features',
                items: [
                    'Period totals by type with transaction count',
                    'Payment breakdown by tender (amount and count)',
                    'Net by tender: total_in, total_out, and net per payment method',
                    'Cumulative balance as of end date (all-time, not just the period)',
                    'Balance by tender: running balance per payment method as of end date',
                ],
            },
            {
                title: 'Manual Entry Rules',
                type: 'prose',
                content: 'Only admin and auditor roles can create, edit, or delete manual entries (expense, income_adjustment, asset_deduction). Payment tender is required for all manual entries. Auto-generated entries (payment, payroll, payout_share) cannot be edited or deleted from the Financial page — they are managed via their source modules (POS, HRIS, Distribution).',
            },
            {
                title: 'Bills Tracker',
                type: 'features',
                items: [
                    'Tracks recurring operational expenses: rent, utilities, subscriptions, loans.',
                    'Frequencies: daily, weekly, monthly, quarterly, or yearly.',
                    'Status per bill: overdue, due_today, upcoming, scheduled, or inactive.',
                    'Installment mode: a bill can be split into N payments, each with its own due date and paid status.',
                    'Forecast view: projects upcoming bill payments across a configurable number of future months.',
                ],
            },
        ],
    },

    reports: {
        eyebrow: 'Module · Analytics',
        title: 'Reports & Analytics',
        icon: markRaw(BarChart3),
        route: '/reports',
        accessText: 'can:view reports',
        accessCls: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300',
        intro: 'The Reports page is a multi-tab analytics hub that provides a comprehensive view of business performance. It draws from orders, payments, inventory movements, and financial transactions to give a clear picture of sales trends, product performance, stock levels, and profitability. All tabs support date range filtering.',
        sections: [
            {
                title: 'Report Tabs',
                type: 'table',
                headers: ['Tab', 'Data', 'Key Metrics'],
                rows: [
                    ['Daily Sales', 'One row per calendar day', 'Total orders, total sales, total discount applied'],
                    ['Monthly Sales', 'One row per month', 'Total orders, total sales, total discount applied'],
                    ['Product Sales', 'One row per product', 'Total quantity sold, total revenue generated'],
                    ['Product Daily', 'Per-product per-day breakdown', 'Quantity and revenue per product per day'],
                    ['Profit & Loss', 'Revenue vs. COGS', 'Gross profit, margin per product and overall'],
                    ['Daily Chart', 'Bar/line chart', 'Visual daily sales trend over the selected period'],
                    ['Monthly Chart', 'Bar/line chart', 'Visual monthly sales trend'],
                    ['Sales Heatmap', 'Hour × day grid', 'Order volume by hour of day and day of week'],
                    ['FT Summary', 'Financial transaction totals', 'Same view as the summary panel in Financial page'],
                    ['Inventory Transactions', 'Full stock movement log', 'Type, quantity delta, user, order reference'],
                    ['Inventory Valuation', 'Current stock at cost', 'Current quantity × cost per unit per item'],
                    ['Analytics', 'Advanced analytics sub-component', 'Deeper breakdowns and trend analysis'],
                ],
            },
            {
                title: 'Profit & Loss Methodology',
                type: 'prose',
                content: 'The P&L report compares revenue (total sales) against cost-of-goods (COGS). COGS is calculated from product recipes — each product has a recipe linking it to inventory ingredients with quantities. The cost per product is the sum of (ingredient cost_per_unit × quantity used). If a product has no recipe, its COGS is zero or uses the manually entered product cost field.',
            },
            {
                title: 'Sales Heatmap',
                type: 'prose',
                content: 'The heatmap visualises when the business is busiest. Orders are grouped by hour of day (0–23) and day of week (Mon–Sun). Cells are colour-coded by order volume — lighter cells are quiet periods, darker cells are peak periods. This is useful for optimising staffing schedules and promotions timing.',
            },
        ],
    },

    inventory: {
        eyebrow: 'Module · Stock',
        title: 'Inventory Management',
        icon: markRaw(Package),
        route: '/inventory',
        accessText: 'can:view inventory',
        accessCls: 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
        intro: 'Inventory tracks all stock-level items used in the kitchen and operations. The system is connected to the product catalogue via recipes — every time an order item is sold, the corresponding ingredients are automatically deducted. Admins and staff can also make manual adjustments for deliveries, wastage, or corrections.',
        sections: [
            {
                title: 'Item Types',
                type: 'table',
                headers: ['Type', 'Purpose', 'Colour'],
                rows: [
                    ['ingredient', 'Raw materials used in recipes (e.g. rice, chicken, sauce)', 'Green'],
                    ['tool', 'Kitchen tools that need stock tracking (e.g. disposable containers)', 'Blue'],
                    ['equipment', 'Equipment tracked for maintenance or quantity (e.g. propane tanks)', 'Orange'],
                    ['supply', 'Consumable supplies (e.g. tissue, cleaning materials)', 'Purple'],
                ],
            },
            {
                title: 'Item Fields',
                type: 'features',
                items: [
                    'name and unit (e.g. "Chicken Breast", "kg")',
                    'current_quantity — live stock level updated on every transaction',
                    'min_quantity — threshold below which the item is flagged as low-stock',
                    'cost_per_unit — used for inventory valuation and product cost calculation',
                    'is_low_stock — computed flag, true when current_quantity is below min_quantity',
                ],
            },
            {
                title: 'Automatic Deduction via Recipes',
                type: 'prose',
                content: 'Each product in the catalogue can have a recipe — a list of inventory items with the quantity consumed per unit of that product sold. When an order item is created, the system looks up the product recipe and deducts the corresponding ingredient quantities. If any ingredient is insufficient, the order creation logs a warning (current behaviour does not hard-block the sale).',
            },
            {
                title: 'Manual Adjustments',
                type: 'features',
                items: [
                    'stock_in: increases the current_quantity. Used for supplier deliveries or corrections.',
                    'stock_out: decreases the current_quantity. Used for wastage, spoilage, or manual consumption.',
                    'Notes are required for each manual adjustment and appear in the transaction history.',
                    'Each adjustment is attributed to the logged-in user.',
                ],
            },
            {
                title: 'Transaction History',
                type: 'prose',
                content: 'Every stock movement — whether automatic (via order) or manual — is recorded in the inventory_transactions table. Each record stores the type (stock_in, stock_out, order_deduction), quantity change, old and new quantity, user who made the change, notes, and the reference order ID if applicable. This provides a full audit trail per ingredient.',
            },
            {
                title: 'Low-Stock API',
                type: 'prose',
                content: 'A dedicated endpoint GET /api/v1/inventory/low-stock returns all items where current_quantity is below min_quantity. This is used by dashboard widgets and can be integrated with external alerting systems.',
            },
        ],
    },

    products: {
        eyebrow: 'Module · Catalogue',
        title: 'Products & Menu',
        icon: markRaw(UtensilsCrossed),
        route: '/products',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        intro: 'The Products module is the source of truth for the restaurant menu. Every item that can be ordered by a customer exists here. Admins manage the full product lifecycle: creating items, setting prices, uploading images, attaching modifiers (add-ons), linking recipes for cost calculation, and organising products into categories.',
        sections: [
            {
                title: 'Product Fields',
                type: 'table',
                headers: ['Field', 'Purpose'],
                rows: [
                    ['name', 'Product display name shown on the POS and menu'],
                    ['description', 'Optional description for the menu/public page'],
                    ['price', 'Selling price — what customers pay'],
                    ['cost', 'Cost of production — used in P&L. Can be set manually or calculated from recipe.'],
                    ['image', 'Product photo displayed on POS grid and public menu'],
                    ['category_id', 'Groups products in the POS browsing grid'],
                    ['is_active', 'Inactive products are hidden from POS and public menu'],
                ],
            },
            {
                title: 'Modifiers (Add-ons)',
                type: 'prose',
                content: 'Each product can have any number of modifiers attached. A modifier is an optional extra the customer can add to an order item — for example, extra rice (+₱15), sauce upgrade (+₱10), or a size upgrade. Each modifier has its own name and price. When a cashier adds a product to the cart, they can select any combination of modifiers. The modifier prices are added to the item line total.',
            },
            {
                title: 'Recipe-Based Cost Calculation',
                type: 'prose',
                content: 'A product recipe links inventory ingredients to a product with quantities per unit. For example, one serving of a rice bowl might consume 200g of rice, 150g of chicken, and 10ml of sauce. The system can calculate the product cost automatically from the recipe by summing (ingredient cost_per_unit × quantity used). This keeps the P&L report accurate as ingredient costs change.',
            },
            {
                title: 'Categories',
                type: 'prose',
                content: 'Categories organise products in the POS browsing grid. A cashier can tap a category tab to filter the product grid to that category, making it faster to find items during a rush. Categories are managed from the Products page — admins can add and delete categories. Products must belong to a category.',
            },
            {
                title: 'Bulk Price Editor',
                type: 'prose',
                content: 'The Settings > Prices page provides an inline bulk editor for product prices. All products are listed in a table and prices can be updated without entering the full product edit form. This is useful when doing a periodic price review across the whole menu.',
            },
        ],
    },

    distribution: {
        eyebrow: 'Module · Profit Sharing',
        title: 'Distribution & Profit Sharing',
        icon: markRaw(PieChart),
        route: '/distribution',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        intro: 'The Distribution module handles revenue or profit sharing among the business shareholders. It computes each shareholder\'s portion of a period\'s earnings based on configurable royalty rules, product ownership, and incentive bonuses. Admins preview the computation, freeze it as an immutable snapshot, and record actual payouts against it.',
        sections: [
            {
                title: 'Shareholders',
                type: 'prose',
                content: 'A shareholder is any person or entity that receives a share of the business earnings. Each shareholder record stores a name and any associated configuration. Shareholders are linked to products via ProductOwnership records — this determines which products contribute to their share calculation.',
            },
            {
                title: 'Royalty Rules',
                type: 'prose',
                content: 'Royalty rules define what percentage of a product\'s revenue or profit flows to which shareholder. Rules can be set per product, per category, or globally. The system supports multiple shareholders sharing a single product\'s proceeds at different percentages, as long as the total does not exceed 100%.',
            },
            {
                title: 'Computation Basis',
                type: 'features',
                items: [
                    'Total Sales: each shareholder\'s share is calculated from gross revenue.',
                    'Net Profit: each shareholder\'s share is calculated from revenue minus COGS.',
                    'Date range is configurable: any start and end date, with shortcuts for month, quarter, and year.',
                    'Optional filter by product category or specific product.',
                ],
            },
            {
                title: 'Snapshot Workflow',
                type: 'steps',
                items: [
                    'Admin selects basis (sales/profit), date range, and optional category filter.',
                    'System computes each shareholder\'s share applying royalty rules and incentive bonuses.',
                    'Admin reviews the breakdown in the Distribution tab.',
                    'Admin saves the computation as a DistributionSnapshot — this freezes the figures.',
                    'Admin records a payout against the snapshot. Each payout creates a FinancialTransaction of type payout_share.',
                    'The snapshot and its payouts appear in History and are excluded from future computations.',
                ],
            },
            {
                title: 'Financial Integration',
                type: 'callout',
                label: 'Automatic',
                content: 'Every payout recorded through the Distribution module automatically creates a FinancialTransaction of type payout_share with the payout amount and the payment tender used. These appear in the Financial ledger as outflows and are included in the net balance calculation.',
            },
        ],
    },

    hris: {
        eyebrow: 'Module · Human Resources',
        title: 'HRIS & Payroll',
        icon: markRaw(Users),
        route: '/hris',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        intro: 'The HRIS module provides basic human resources and payroll management integrated directly with the financial ledger. Employee profiles capture employment structure and compensation, while payroll records track each pay period from preparation through approval to payment. Marking a payroll as paid automatically creates an outflow in financial transactions.',
        sections: [
            {
                title: 'Employee Records',
                type: 'table',
                headers: ['Field', 'Values / Notes'],
                rows: [
                    ['name', 'Full employee name'],
                    ['position', 'Job title or role description'],
                    ['employment_type', 'full_time, part_time, or contractual'],
                    ['salary_type', 'monthly, daily, or hourly'],
                    ['base_rate', 'Base compensation rate in the salary_type unit'],
                    ['hired_at', 'Date of employment start'],
                    ['is_active', 'Inactive employees are hidden from payroll lists'],
                    ['notes', 'Any additional HR notes'],
                ],
            },
            {
                title: 'Payroll Workflow',
                type: 'steps',
                items: [
                    'Admin creates a payroll record for an employee and period (start date, end date).',
                    'Fields entered: days_worked, gross_pay, deductions (SSS, PhilHealth, etc.), net_pay.',
                    'Status is set to pending. Admin reviews the record.',
                    'Admin approves the record — status changes to approved.',
                    'Admin marks as paid — status changes to paid and paid_at is recorded.',
                    'On marking paid: a FinancialTransaction of type payroll is automatically created with net_pay as the amount and the payment tender used.',
                ],
            },
            {
                title: 'Financial Integration',
                type: 'callout',
                label: 'Automatic',
                content: 'Payroll entries appear in the Financial ledger as outflows under the payroll type. They are included in the net balance and appear in the summary panel totals. They cannot be deleted from the Financial page — to reverse a payroll entry, the payroll record itself must be managed in the HRIS module.',
            },
            {
                title: 'HRIS Settings',
                type: 'prose',
                content: 'HRIS configuration is available at /settings/hris. This covers pay period defaults and deduction rate configuration (SSS, PhilHealth, Pag-IBIG percentages). These settings feed into payroll calculation suggestions but do not override manually entered deduction amounts.',
            },
        ],
    },

    settings: {
        eyebrow: 'Module · Configuration',
        title: 'System Settings',
        icon: markRaw(Settings),
        route: '/settings/profile',
        accessText: 'varies by section',
        accessCls: 'border bg-muted/50 text-muted-foreground',
        intro: 'Settings is the central configuration hub for all system behaviour. It is divided into sections covering personal account settings (available to all users), operational configuration (payment tenders, printer setup, branding), and administrative controls (user management, system clock override, factory reset). Most sections require the admin role.',
        sections: [
            {
                title: 'Personal Settings (Any Auth)',
                type: 'table',
                headers: ['Page', 'Route', 'Purpose'],
                rows: [
                    ['Profile', '/settings/profile', 'Update name and email address. Delete own account.'],
                    ['Security', '/settings/security', 'Change password.'],
                    ['Appearance', '/settings/appearance', 'Toggle light or dark UI theme.'],
                ],
            },
            {
                title: 'Operational Settings (Admin)',
                type: 'table',
                headers: ['Page', 'Route', 'Purpose'],
                rows: [
                    ['Users', '/settings/users', 'Create, edit, and delete user accounts. Assign Spatie roles.'],
                    ['Payment Tenders', '/settings/payment-tenders', 'Configure payment methods (cash, GCash, card, etc.). Enable/disable and set display order in POS.'],
                    ['Print Service', '/settings/print-service', 'Set Pusher channel key and secret, store name/address/phone/footer, auto-print toggle, and QR code mode.'],
                    ['Logo', '/settings/logo', 'Upload or remove the restaurant logo. Update the brand name shown in the app.'],
                    ['Kitchen', '/settings/kitchen', 'Configure kitchen monitor behaviour.'],
                    ['Advertisements', '/settings/advertisements', 'Upload, manage, and toggle promotional images shown on public/menu pages.'],
                    ['Page Content', '/settings/page-content', 'Edit, reorder, and toggle public-facing landing page sections.'],
                    ['Media Library', '/settings/media', 'Central upload and management for all media files.'],
                    ['Prices', '/settings/prices', 'Bulk price editor for products.'],
                    ['HRIS Settings', '/settings/hris', 'Pay period and deduction rate defaults.'],
                ],
            },
            {
                title: 'System Clock Override',
                type: 'prose',
                content: 'At /settings/clock, an admin can override the system date and time used for all new records. This is useful for backdating corrections — for example, entering a payment that should have been recorded yesterday. While active, a prominent amber banner appears at the top of all pages showing the active override date. The override affects created_at, transacted_at, and similar timestamps. It must be manually disabled after use.',
            },
            {
                title: 'System Reset',
                type: 'callout',
                label: 'Destructive',
                content: 'The /settings/system page contains system reset and factory reset actions. These are irreversible. A system reset clears operational data (orders, transactions, inventory movements). A factory reset returns the system to a blank state. These actions require the admin role and an explicit confirmation step.',
            },
        ],
    },

    tools: {
        eyebrow: 'Module · Developer',
        title: 'SQL Console',
        icon: markRaw(Database),
        route: '/tools',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        intro: 'The SQL Console is a read-only database inspection tool available exclusively to admins. It provides direct query access to the underlying database for debugging, reporting, and data exploration without requiring SSH or a database client. The backend enforces that only SELECT statements can be executed — no writes, updates, or deletes are ever permitted.',
        sections: [
            {
                title: 'Table Browser',
                type: 'features',
                items: [
                    'The left sidebar lists all database tables with their approximate row counts.',
                    'A search box filters the table list in real time by name.',
                    'Clicking the expand arrow next to a table name reveals all columns with data type, nullable flag, and key type (PRI for primary key).',
                    'Clicking a table name auto-populates the query editor with SELECT * FROM {table} LIMIT 100 and runs the query immediately.',
                    'Clicking a column name inserts the column name at the cursor position in the query editor.',
                ],
            },
            {
                title: 'Query Editor & Results',
                type: 'features',
                items: [
                    'A textarea with monospace font for writing SQL queries.',
                    'Ctrl/Cmd+Enter keyboard shortcut to run the query without clicking the Run button.',
                    'Results display in a scrollable table with column headers.',
                    'A metadata row shows: total row count, elapsed time in milliseconds, and a notice if results are capped at 500 rows.',
                    'NULL values are shown in a distinct muted italic style rather than an empty cell.',
                    'Errors from the database are shown in a red error panel with the raw database message.',
                ],
            },
            {
                title: 'Export Options',
                type: 'table',
                headers: ['Format', 'Mechanism', 'Notes'],
                rows: [
                    ['CSV', 'Client-side Blob download', 'All result rows, properly escaped, saved as query-result.csv'],
                    ['Excel (.xlsx)', 'SheetJS (xlsx npm package)', 'Real XLSX binary — opens correctly in Excel and Google Sheets'],
                    ['PDF', 'Browser window.print()', 'Opens a formatted HTML table in a popup window and triggers print. Supports Save as PDF.'],
                ],
            },
            {
                title: 'Security Enforcement',
                type: 'prose',
                content: 'The ToolsController backend parses the submitted SQL and rejects any statement that is not a SELECT. This is enforced server-side, not just in the UI. Attempts to run INSERT, UPDATE, DELETE, DROP, or any DDL statement will return a 422 error. The tool is also restricted to the admin role via route middleware.',
            },
        ],
    },

    parcels: {
        eyebrow: 'Module · Logistics',
        title: 'Parcel Tracking',
        icon: markRaw(Archive),
        route: '/parcels',
        accessText: 'Any auth (manage: admin/auditor)',
        accessCls: 'border bg-muted/50 text-muted-foreground',
        intro: 'The Parcels module tracks physical packages and bundles assigned to staff members. It is designed for businesses that regularly send supplies, equipment, or delivery goods out with personnel and need to track what has left the premises, what has returned, and what is complete.',
        sections: [
            {
                title: 'Parcel Fields',
                type: 'table',
                headers: ['Field', 'Purpose'],
                rows: [
                    ['parcel_number', 'Unique identifier for the parcel, used for searching and reference'],
                    ['name', 'Descriptive name for the parcel'],
                    ['assigned_personnel', 'Name of the staff member responsible for the parcel'],
                    ['status', 'in (present), out (sent out), or complete (closed)'],
                    ['notes', 'Any additional information about the parcel'],
                ],
            },
            {
                title: 'Item Tracking Within a Parcel',
                type: 'prose',
                content: 'Each parcel contains a list of individual items. Items have their own in/out status that can be toggled independently of the parcel status. This allows partial returns — for example, a parcel of five items where three have been returned and two are still out. Each item status change is timestamped.',
            },
            {
                title: 'Parcel Lifecycle',
                type: 'steps',
                items: [
                    'Admin or auditor creates a parcel with a number, name, and assigned personnel.',
                    'Items are added to the parcel with names and quantities.',
                    'Parcel status is set to out when it leaves the premises.',
                    'Staff toggle individual item statuses as items are returned.',
                    'Parcel is marked complete when all items have been accounted for.',
                ],
            },
            {
                title: 'Access Levels',
                type: 'prose',
                content: 'Any authenticated user can view parcels and parcel details. Creating, editing, and deleting parcels is restricted to admin and auditor roles. Toggling individual item statuses is available to all authenticated users. This allows kitchen and cashier staff to update item status without needing access to the full management interface.',
            },
        ],
    },

    commands: {
        eyebrow: 'CLI Reference',
        title: 'Artisan Commands',
        icon: markRaw(Terminal),
        route: 'CLI only',
        accessText: 'Server/SSH access required',
        accessCls: 'border bg-muted/50 text-muted-foreground',
        intro: 'These artisan commands handle data maintenance tasks that cannot be performed through the web UI. They are run from the server command line (SSH) and typically involve batch updates or corrections to historical data. All commands support a --dry-run flag to preview changes without writing to the database.',
        sections: [
            {
                type: 'commands',
                commands: [
                    {
                        name: 'ft:sync-orders',
                        flags: ['--dry-run', '--order={id}'],
                        desc: 'Finds all order-type FinancialTransaction records whose amount no longer matches the linked order total_amount. This can happen when a discount is changed on an order after the original FT was created. The command shows a diff table of affected records (order ID, current FT amount, correct amount), prompts for confirmation, and updates all records in a single database transaction. Use --order to target a single order by ID.',
                    },
                    {
                        name: 'kitchen:clear',
                        flags: ['--except=id1,id2,...', '--dry-run'],
                        desc: 'Batch-completes all active kitchen orders — those with status pending, preparing, or ready. Useful for end-of-day cleanup when orders need to be closed out without individually completing them in the monitor, or after testing when the board is cluttered. The --except flag accepts comma-separated order IDs to skip. --dry-run shows which orders would be affected without making changes.',
                    },
                    {
                        name: 'inventory:backfill-deductions',
                        flags: ['--date=Y-m-d'],
                        desc: 'Retroactively deducts inventory for orders placed before automatic inventory deduction was enabled. This is safe to run multiple times — it checks for existing stock_out InventoryTransaction records with reference order_{id} and skips orders that have already been deducted. Defaults to processing orders from today. Use --date to process a specific date.',
                    },
                ],
            },
            {
                title: 'Running Commands',
                type: 'prose',
                content: 'Commands are run from the application root directory on the server via SSH. All commands require a working database connection. Use --dry-run first to understand what the command will do, then run without the flag to apply changes. Commands that modify data prompt for confirmation before writing unless run with --force (where available).',
            },
            {
                title: 'Example Usage',
                type: 'features',
                items: [
                    'php artisan ft:sync-orders --dry-run',
                    'php artisan ft:sync-orders --order=848',
                    'php artisan kitchen:clear --except=101,102 --dry-run',
                    'php artisan inventory:backfill-deductions --date=2026-07-12',
                ],
            },
        ],
    },

    roles: {
        eyebrow: 'Access Control',
        title: 'Roles & Permissions',
        icon: markRaw(ShieldCheck),
        route: '/settings/users',
        accessText: 'role:admin',
        accessCls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300',
        intro: 'Access control is implemented using Spatie Laravel Permission. Every user is assigned one or more roles. Roles carry permissions that determine which pages and actions are available. The admin role has unrestricted access. All other roles are narrowly scoped to prevent unauthorised access to financial, inventory, or configuration data.',
        sections: [
            {
                title: 'Role Definitions',
                type: 'table',
                headers: ['Role', 'Scope', 'Pages Accessible'],
                rows: [
                    ['admin', 'Full access', 'All pages including products, HRIS, distribution, tools, settings, financial CRUD, and system reset.'],
                    ['auditor', 'Financial + operational', 'Financial ledger (create/edit/delete manual entries), bills, reports, parcels management.'],
                    ['cashier', 'POS only', 'POS terminal, parcels (read-only), own profile settings.'],
                    ['kitchen', 'Kitchen only', 'Kitchen monitor, parcels (read-only), own profile settings.'],
                ],
            },
            {
                title: 'Permission-Based Access',
                type: 'table',
                headers: ['Permission', 'Granted to', 'Controls access to'],
                rows: [
                    ['can:create orders', 'cashier, admin', 'POS terminal (/pos)'],
                    ['can:update orders', 'kitchen, admin', 'Kitchen monitor (/kitchen)'],
                    ['can:view orders', 'cashier, auditor, admin', 'Order detail pages (/orders/{id})'],
                    ['can:view reports', 'auditor, admin', 'Financial, bills, and reports pages'],
                    ['can:view inventory', 'auditor, admin', 'Inventory management (/inventory)'],
                ],
            },
            {
                title: 'Public Access (No Auth)',
                type: 'features',
                items: [
                    '/ — Restaurant landing page (content managed in Settings > Page Content)',
                    '/menu/{id} — Digital menu (content from products catalogue)',
                    '/public/orders/{token} — Customer order status page (QR code from receipt)',
                    '/printing-architecture — Static page documenting the print pipeline',
                    'Standard auth pages: /login, /register, /forgot-password, etc.',
                ],
            },
            {
                title: 'Managing Users & Roles',
                type: 'prose',
                content: 'User accounts and role assignments are managed at /settings/users (admin only). Admins can create new user accounts, assign one or more roles, reset passwords, and delete accounts. A user can hold multiple roles simultaneously — for example, a supervisor who is both admin and cashier. Role assignments take effect immediately without requiring re-login.',
            },
        ],
    },
}

const currentModule = computed<ModuleDoc | null>(() => allModules[props.module] ?? null)
</script>

<template>
    <Head :title="currentModule ? currentModule.title + ' — Documentation' : 'Documentation'" />

    <div class="max-w-3xl px-6 py-8 mx-auto">

        <!-- Back link -->
        <Link href="/documentation" class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground mb-6 group">
            <ArrowLeft class="h-4 w-4 group-hover:-translate-x-0.5 transition-transform" />
            Back to Documentation
        </Link>

        <!-- Not found -->
        <div v-if="!currentModule" class="border rounded-xl p-12 text-center">
            <p class="text-2xl mb-2">—</p>
            <h1 class="font-bold text-lg mb-1">Module not found</h1>
            <p class="text-muted-foreground text-sm">No documentation exists for <code class="bg-muted px-1 rounded">{{ module }}</code>.</p>
        </div>

        <!-- Module content -->
        <template v-else>

            <!-- Module header -->
            <div class="mb-8">
                <p class="text-[10px] font-bold tracking-widest uppercase text-orange-500 mb-1">{{ currentModule.eyebrow }}</p>
                <h1 class="text-2xl font-bold mb-3">{{ currentModule.title }}</h1>
                <div class="flex gap-2 flex-wrap mb-4">
                    <span :class="['text-xs font-semibold px-2 py-0.5 rounded', currentModule.accessCls]">
                        {{ currentModule.accessText }}
                    </span>
                    <code v-if="currentModule.route !== 'CLI only'"
                        class="text-xs font-mono px-2 py-0.5 rounded border bg-muted/50 text-muted-foreground">
                        {{ currentModule.route }}
                    </code>
                    <span v-else class="text-xs font-mono px-2 py-0.5 rounded border bg-muted/50 text-muted-foreground">CLI only</span>
                </div>
                <p class="text-muted-foreground leading-relaxed">{{ currentModule.intro }}</p>
            </div>

            <!-- Sections -->
            <div class="space-y-8">
                <div v-for="(section, i) in currentModule.sections" :key="i">

                    <h2 v-if="section.title" class="font-semibold text-base mb-3 pb-2 border-b">
                        {{ section.title }}
                    </h2>

                    <!-- Prose -->
                    <p v-if="section.type === 'prose'" class="text-sm text-muted-foreground leading-relaxed">
                        {{ section.content }}
                    </p>

                    <!-- Features list -->
                    <ul v-else-if="section.type === 'features'" class="space-y-2">
                        <li v-for="item in section.items" :key="item"
                            class="flex items-start gap-2 text-sm text-muted-foreground">
                            <span class="text-orange-500 shrink-0 mt-0.5 text-xs font-bold">→</span>
                            <span>{{ item }}</span>
                        </li>
                    </ul>

                    <!-- Numbered steps -->
                    <ol v-else-if="section.type === 'steps'" class="space-y-2">
                        <li v-for="(item, idx) in section.items" :key="item"
                            class="flex items-start gap-3 text-sm text-muted-foreground">
                            <span class="flex-shrink-0 w-5 h-5 rounded-full bg-orange-500 text-white text-[11px] font-bold flex items-center justify-center mt-0.5">
                                {{ idx + 1 }}
                            </span>
                            <span>{{ item }}</span>
                        </li>
                    </ol>

                    <!-- Table -->
                    <div v-else-if="section.type === 'table'" class="overflow-x-auto rounded-lg border">
                        <table class="text-sm w-full">
                            <thead class="bg-muted/50 text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th v-for="h in section.headers" :key="h"
                                        class="px-3 py-2 text-left font-semibold whitespace-nowrap">{{ h }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="(row, ri) in section.rows" :key="ri" class="hover:bg-muted/20">
                                    <td v-for="(cell, ci) in row" :key="ci"
                                        :class="['px-3 py-2', ci === 0 ? 'font-mono text-xs font-semibold whitespace-nowrap' : 'text-muted-foreground']">
                                        {{ cell }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Callout -->
                    <div v-else-if="section.type === 'callout'"
                        class="border-l-4 border-orange-400 bg-orange-50 dark:bg-orange-950/20 px-4 py-3 rounded-r text-sm">
                        <span class="font-bold text-orange-600 dark:text-orange-400 mr-1">{{ section.label }}:</span>
                        <span class="text-muted-foreground">{{ section.content }}</span>
                    </div>

                    <!-- Formula -->
                    <div v-else-if="section.type === 'formula'" class="space-y-2">
                        <div class="bg-muted/50 border rounded-lg px-4 py-3 font-mono text-sm font-semibold">
                            {{ section.formula }}
                        </div>
                        <p v-if="section.formulaNote" class="text-xs text-muted-foreground">{{ section.formulaNote }}</p>
                    </div>

                    <!-- Commands -->
                    <div v-else-if="section.type === 'commands'" class="space-y-4">
                        <div v-for="cmd in section.commands" :key="cmd.name"
                            class="border rounded-lg p-4 bg-card">
                            <div class="flex items-center gap-2 flex-wrap mb-2">
                                <code class="text-sm font-mono font-bold text-orange-600 dark:text-orange-400">{{ cmd.name }}</code>
                                <code v-for="f in cmd.flags" :key="f"
                                    class="text-xs font-mono bg-muted border px-1.5 py-0.5 rounded text-muted-foreground">{{ f }}</code>
                            </div>
                            <p class="text-sm text-muted-foreground leading-relaxed">{{ cmd.desc }}</p>
                        </div>
                    </div>

                </div>
            </div>

        </template>
    </div>
</template>
