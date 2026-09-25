export interface FeatureModule {
    title: string;
    area: string;
    path: string;
    audience: string;
    summary: string;
    features: string[];
}
export const modules: FeatureModule[] = [
    {
        title: 'Dashboard',
        area: 'Daily operations',
        path: '/dashboard',
        audience: 'Staff',
        summary: 'Start the day with the current business picture.',
        features: [
            'View sales and operational summaries',
            'Follow the cashier shift checklist',
            'Check the open deposit-control shift',
            'Navigate to the tools available to your role',
        ],
    },
    {
        title: 'Point of Sale',
        area: 'Daily operations',
        path: '/pos',
        audience: 'Cashier / administrator',
        summary: 'Create an order, collect payment, and share its receipt.',
        features: [
            'Search products and filter categories',
            'Choose dine-in, takeout, or delivery',
            'Enter customer and table details',
            'Set quantities and available add-ons',
            'Apply a discount and review totals',
            'Collect Cash, GCash, or another configured tender',
            'Check received amount and change',
            'Hold an order or choose Pay Later',
            'Find, modify, pay, or cancel a pending order',
            'Print a receipt or copy the public order link',
            'Queue orders and payments during an offline interruption',
        ],
    },
    {
        title: 'Deposit Control',
        area: 'Daily operations',
        path: '/deposit-control',
        audience: 'Cashier / auditor / administrator',
        summary: 'Compare the system balance with what you actually count.',
        features: [
            'Open a shift with a financial snapshot',
            'Review payroll release before closing',
            'Close with drawer cash and GCash counted for the shift',
            'Record the overall lockbox and total GCash balances',
            'Review closing breakdowns and variances',
            'Browse previous snapshots in the history table; drawer cash is already included in the lockbox total',
        ],
    },
    {
        title: 'Kitchen Monitor',
        area: 'Daily operations',
        path: '/kitchen',
        audience: 'Kitchen / administrator',
        summary: 'Follow each order from preparation to serving.',
        features: [
            'Watch the order queue',
            'Move orders through preparation, ready, and completion',
            'Review order items and timing',
            'Edit or cancel an order where permitted',
            'Use configured kitchen printing and display options',
        ],
    },
    {
        title: 'Orders & receipts',
        area: 'Daily operations',
        path: '/orders/{order}',
        audience: 'Staff with order access',
        summary: 'Review the details behind an order.',
        features: [
            'View items, discounts, customer details, and payment history',
            'Edit permitted order details',
            'Print an order receipt',
            'Copy the customer-facing order link',
            'Let customers view a shared order using its private link',
        ],
    },
    {
        title: 'Inventory',
        area: 'Stock & menu',
        path: '/inventory',
        audience: 'Auditor / administrator',
        summary: 'Keep prepared Food and raw ingredients separate.',
        features: [
            'Browse Food first, then ingredients, tools, equipment, and supplies',
            'Search items and filter low stock',
            'Add or edit items and Food recipes',
            'Receive stock with a unit cost',
            'Record waste, stock removal, or a physical stock count',
            'Produce Food from ingredients using actual batch yield',
            'Review batch cost and the resulting average unit cost',
            'Undo eligible stock receipts or production runs',
            'Review recent stock movements',
            'Filter the separate inventory cost report by dates, item, and movement',
            'Keep zero-quantity recipes as drafts until their quantities are completed',
        ],
    },
    {
        title: 'Products & menu',
        area: 'Stock & menu',
        path: '/products',
        audience: 'Administrator',
        summary: 'Manage what customers can buy.',
        features: [
            'Create and edit products, categories, prices, images, and active status',
            'Build product recipes from Food or permitted inventory items',
            'Build a combination from existing products',
            'Compare stored costs with recipe estimates',
            'Recalculate costs for complete recipes',
            'Search, filter, and browse the mobile product cards',
        ],
    },
    {
        title: 'Financial',
        area: 'Money & reporting',
        path: '/financial',
        audience: 'Auditor / administrator',
        summary: 'See money in, money out, and the balance brought forward.',
        features: [
            'Choose a preset or custom reporting period',
            'Review opening balance, money in, money out, and closing balance',
            'Record expenses and income adjustments',
            'Review payroll, asset deductions, and payouts',
            'Filter and search the ledger across its records',
            'Break down money by transaction type and tender',
            'Review financial performance',
        ],
    },
    {
        title: 'Bills',
        area: 'Money & reporting',
        path: '/bills',
        audience: 'Auditor / administrator',
        summary: 'Track bills, their schedules, and payments.',
        features: [
            'Create and maintain bills',
            'Review due dates and installment plans',
            'Record payments against bills',
            'Check outstanding and paid amounts',
        ],
    },
    {
        title: 'Reports',
        area: 'Money & reporting',
        path: '/reports',
        audience: 'Auditor / administrator',
        summary: 'Explore performance for a selected period.',
        features: [
            'Find orders and open order details',
            'Review daily and monthly sales',
            'Compare product sales and trends',
            'Review profit and loss, COGS, and inventory losses',
            'Review bills and inventory movements',
            'Explore peak hours and serving times',
            'Use date and product filters where available',
        ],
    },
    {
        title: 'Profit Sharing',
        area: 'Money & reporting',
        path: '/distribution',
        audience: 'Administrator',
        summary: 'Review member entitlements and record distributions.',
        features: [
            'Select a distribution period',
            'Include the balance carried from earlier periods',
            'Review the pie chart and member breakdown',
            'Manage members, ownership, and incentives',
            'Save payout snapshots',
            'Record eligible payouts and export results',
        ],
    },
    {
        title: 'HRIS & payroll',
        area: 'People & management',
        path: '/hris',
        audience: 'Administrator',
        summary: 'Manage employees and understand payroll spending.',
        features: [
            'Add and update employee records',
            'Filter active and inactive employees',
            'Prepare payroll and review deductions',
            'Release payroll and track paid or unpaid records',
            'Filter payroll spending by period',
            'Review payroll distribution per employee',
        ],
    },
    {
        title: 'Parcels',
        area: 'People & management',
        path: '/parcels',
        audience: 'Signed-in staff',
        summary: 'Track physical items and who is responsible for them.',
        features: [
            'Create and review parcels',
            'Manage the items inside a parcel',
            'Track item in/out status',
            'Review parcel details and assignments',
        ],
    },
    {
        title: 'Stall Mapping',
        area: 'People & management',
        path: '/mapping',
        audience: 'Administrator',
        summary: 'Organize stalls, tenants, and scheduled use.',
        features: [
            'Browse the stall map and status legend',
            'Manage tenant and stall details',
            'Navigate dates and schedules',
            'Use calendar and timeline views',
        ],
    },
    {
        title: 'Customer storefront',
        area: 'Customer experience',
        path: '/',
        audience: 'Customers',
        summary: 'Build a request and contact Bypass Grill.',
        features: [
            'Browse menu categories and product images',
            'See low-stock and sold-out banners',
            'Add items to a request, including unavailable items',
            'Review availability before finalizing',
            'Copy the request and message Facebook about availability, advance orders, or reservations',
        ],
    },
    {
        title: 'Settings & account',
        area: 'Administration',
        path: '/settings',
        audience: 'Personal account / administrator settings',
        summary: 'Configure the business and your own account.',
        features: [
            'Update your profile, password, and security',
            'Choose appearance and personal printing preferences',
            'Manage staff accounts and roles',
            'Configure payment tenders and GCash QR',
            'Configure receipt printing and store details',
            'Update the logo and business name',
            'Manage price settings, media, advertisements, and page content',
            'Configure kitchen and payroll settings',
            'Manage system clock overrides and administrator reset tools',
        ],
    },
    {
        title: 'Tools & system reference',
        area: 'Administration',
        path: '/tools',
        audience: 'Administrator',
        summary: 'Inspect system data and read technical documentation.',
        features: [
            'Browse database tables and schemas',
            'Run read-only queries and export results',
            'Read module documentation and access rules',
            'Use documented maintenance commands through an administrator',
            'Review the printing-architecture reference',
        ],
    },
];

export interface GuideStep {
    title: string;
    action: string;
    expected: string;
    image: string;
    note?: string;
    prompt?: string;
}
export interface Guide {
    key: string;
    title: string;
    summary: string;
    duration: string;
    before: string[];
    done: string;
    steps: GuideStep[];
}
const step = (
    title: string,
    action: string,
    expected: string,
    image: string,
    note?: string,
    prompt?: string,
): GuideStep => ({
    title,
    action,
    expected,
    image: `/images/demo/pos/${image}.jpg`,
    note,
    prompt,
});
export const guides: Guide[] = [
    {
        key: 'POS',
        title: 'Create an order & take cash payment',
        summary:
            'Follow one dine-in order from an empty cart to a paid receipt.',
        duration: '4 minutes',
        before: [
            'Sign in with Point of Sale access, then choose Point of Sale in the sidebar.',
            'Check the shift checklist and deposit-control session for your shift.',
            'Use an available product and a configured Cash tender. The example is 1 Pork Monster Ribs at PHP 185.',
        ],
        done: 'Payment Complete appears with PHP 185 total and PHP 315 change from PHP 500.',
        steps: [
            step(
                'Open Point of Sale',
                'Choose Point of Sale from the sidebar. Locate the menu, product search, and Current order panel.',
                'The menu appears beside an empty order.',
                '01-overview',
                'Screenshots show the actual POS with sample data. This guide does not create live orders.',
            ),
            step(
                'Choose how the customer will order',
                'In Order Details, choose Dine In. Enter Table 3 and the customer name Demo Guest.',
                'The order has an order type and customer name. For Takeout or Delivery, use the matching option and fields.',
                '02-details',
                'A customer name and order type are required before Place Order.',
            ),
            step(
                'Choose the product',
                'Click the Pork Monster Ribs product card. Use search or the category tabs when needed.',
                'The product window opens so you can review its quantity and any add-ons.',
                '03-product',
                'This example has an optional add-on, so a product window opens. A card without add-ons adds one item directly; skip the next step. The staff POS blocks sold-out products. The public storefront can still accept availability requests.',
            ),
            step(
                'Add it to the cart',
                'Keep quantity at 1 and click Add to Cart. Select add-ons only when requested.',
                'Pork Monster Ribs appears in Current order.',
                '04-add',
            ),
            step(
                'Review and place the order',
                'Check the customer, item, quantity, discount, and PHP 185 total. Click Place Order.',
                'The order is saved and Collect Payment opens with a queue/order number.',
                '05-place',
                'On a phone, tap the bottom cart button to open Current order first. Do not place a second order if the first is already saved.',
            ),
            step(
                'Choose Cash',
                'Click Cash under Payment Method.',
                'Cash is selected. Amount Due remains PHP 185.',
                '06-cash',
            ),
            step(
                'Enter the amount received',
                'Enter 500 in Amount received (PHP), or use the PHP 500 shortcut. Check the change.',
                'The screen shows PHP 315 change. Confirm Payment is enabled.',
                '07-amount',
                'Use the actual amount handed to you. The amount must cover the total.',
            ),
            step(
                'Confirm payment',
                'After receiving the money, click Confirm Payment once and wait for the result.',
                'The POS replaces the collection form with the payment confirmation.',
                '08-confirm',
            ),
            step(
                'Finish with the receipt',
                'Check the paid confirmation and change. Use Print Receipt or Copy Receipt Link as needed, then Done.',
                'The customer has their receipt or order link and you can start the next order.',
                '09-paid',
                'If payment returns an error, verify its status before collecting or recording it again.',
            ),
        ],
    },
    {
        key: 'pendingPayment',
        title: 'Collect a pending payment',
        summary:
            'Find an already-saved order and collect payment without creating a duplicate.',
        duration: '2 minutes',
        before: [
            'Open Point of Sale.',
            'Have the customer name or order number ready.',
        ],
        done: 'The saved order is paid and no longer needs collection from Pending Payments.',
        steps: [
            step(
                'Open Pending payments',
                'Click Pending payments at the top of Point of Sale.',
                'A list of saved unpaid orders appears.',
                '10-pending-button',
            ),
            step(
                'Find the customer and choose Pay Now',
                'Use the search box. Check the customer, items, order number, and total, then click Pay Now.',
                'Collect Payment opens for that existing order.',
                '11-pending-pay',
                'Hold and Skip — Pay Later leave the saved order unpaid; neither records a payment.',
            ),
            step(
                'Collect and confirm',
                'Select the actual payment method, enter the amount received, and click Confirm Payment.',
                'A paid confirmation appears for the same order.',
                '08-confirm',
            ),
            step(
                'Check the result',
                'Review the receipt and use Done when finished.',
                'The completed payment details are visible.',
                '09-paid',
            ),
        ],
    },
    {
        key: 'cancelOrder',
        title: 'Cancel an unpaid order',
        summary:
            'Cancel a saved pending order, rather than merely clearing the cart.',
        duration: '2 minutes',
        before: [
            'Open Point of Sale with permission to cancel orders.',
            'Verify with the customer or supervisor that the unpaid order should be cancelled.',
        ],
        done: 'The order disappears from Pending Payments after the cancellation succeeds.',
        steps: [
            step(
                'Find the saved order',
                'Click Pending payments and locate the customer or order number.',
                'The list shows the unpaid order and its items.',
                '11-pending-pay',
                'Clear Cart only clears your working cart. It does not cancel a saved order.',
            ),
            step(
                'Click Cancel on the correct row',
                'Check Demo Guest and the order number, then click Cancel on that row.',
                'Your browser asks for confirmation.',
                '12-cancel',
            ),
            step(
                'Confirm the cancellation',
                'Read the browser confirmation. Choose OK to cancel, or Cancel to leave the order unchanged.',
                'After OK, wait for the success message and refreshed pending list.',
                '12-cancel',
                'The image shows where the prompt starts. The confirmation is a native browser prompt; its appearance varies by device.',
                'Cancel order #1042? This cannot be undone.',
            ),
            step(
                'Verify it is no longer pending',
                'Check the refreshed Pending Payments list.',
                'The cancelled order is removed from this list.',
                '13-cancelled',
                'This guide covers an unpaid order. Cancelling is not the same as issuing a refund for a paid order. A just-placed unpaid order also has Cancel Order in Collect Payment.',
            ),
        ],
    },
    {
        key: 'modifyOrder',
        title: 'Modify a pending order',
        summary: 'Change the saved order instead of creating another copy.',
        duration: '2 minutes',
        before: [
            'Open Point of Sale and Pending payments.',
            'Confirm the requested item changes before editing.',
        ],
        done: 'Update Order saves the revised items on the original order and opens its payment form.',
        steps: [
            step(
                'Choose Modify',
                'Locate the unpaid order, check the customer and items, and click Modify.',
                'The cart loads the existing order for editing.',
                '14-modify',
            ),
            step(
                'Change the items',
                'Review the Editing order heading. Use the quantity controls, remove an item, or add another available product.',
                'The cart total updates. The example changes ribs from 1 to 2.',
                '15-edit',
            ),
            step(
                'Save with Update Order',
                'Check the revised total and click Update Order.',
                'The original order is updated; Collect Payment opens for the new total.',
                '16-update',
                'Do not use a new blank order to make this change. Re-check the amount due before taking payment.',
            ),
        ],
    },
    {
        key: 'gcash',
        title: 'Record a GCash payment',
        summary:
            'Use the configured GCash tender and keep the payment reference.',
        duration: '2 minutes',
        before: [
            'Place an order or open Pay Now on a pending order.',
            'GCash must be configured as an active payment method.',
        ],
        done: 'Payment Complete appears for the correct order and total after recording the GCash payment.',
        steps: [
            step(
                'Select GCash',
                'In Collect Payment, choose GCash.',
                'The GCash payment method is selected.',
                '17-gcash',
            ),
            step(
                'Verify receipt of funds',
                'Confirm the transfer in your business GCash account. Enter the amount actually received and add the transaction reference.',
                'The amount covers the order total and the reference is visible.',
                '18-reference',
                'Selecting GCash does not verify a transfer automatically. Use the actual transaction reference, not the sample shown.',
            ),
            step(
                'Confirm and review',
                'Click Confirm Payment once, then check the paid confirmation.',
                'Payment Complete appears with the correct total. The receipt includes the selected tender.',
                '19-gcash-paid',
            ),
        ],
    },
    {
        key: 'receipt',
        title: 'Print or share the receipt',
        summary: 'Give the customer a receipt and prepare for the next order.',
        duration: '1 minute',
        before: [
            'Complete the payment and stay on its confirmation screen.',
            'For printing, use the printer configured for your account or station.',
        ],
        done: 'The customer has the receipt or link, and a fresh cart is ready.',
        steps: [
            step(
                'Review before sharing',
                'Check the paid status, items, total, tender, and change.',
                'You are sharing the correct order.',
                '09-paid',
            ),
            step(
                'Print or copy the link',
                'Choose Print Receipt for paper, or Copy Receipt Link to share the customer-facing order page.',
                'Printing is requested, or the link is copied for you to send.',
                '20-receipt',
                'Copy Receipt Link needs an online order with a public link. A print request is not proof that paper was printed; check the printer.',
            ),
            step(
                'Start the next order',
                'Click Done after finishing with this customer.',
                'The cart is cleared for the next customer.',
                '21-new-order',
            ),
        ],
    },
];
export const guideUrl = (key: string) =>
    key === 'POS'
        ? '/demo/functionality/POS'
        : `/demo/functionality/POS/${key}`;
