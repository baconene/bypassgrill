import { createPinia } from 'pinia';
import { createApp, h } from 'vue';
import { Toaster } from 'vue-sonner';
import POS from '../../resources/js/pages/CashierDashboard.vue';
import Dashboard from '../../resources/js/pages/Dashboard.vue';
import Demo from '../../resources/js/pages/DemoFunctionality.vue';
import DepositControl from '../../resources/js/pages/DepositControlPage.vue';
import Financial from '../../resources/js/pages/FinancialPage.vue';
import OrderDetail from '../../resources/js/pages/OrderDetail.vue';
import Reports from '../../resources/js/pages/ReportsPage.vue';
import { dashboard } from './dashboard';
import { order } from './orders';
import { dailyReport, productSales as reportProducts } from './reports';
import './style.css';
const categories = [
    { id: 1, name: 'Meals' },
    { id: 2, name: 'Ala Carte' },
    { id: 3, name: 'Extras' },
];
const products = [
    {
        id: 34,
        name: 'Pork Monster Ribs',
        description: 'Grilled ribs with rice',
        price: 185,
        image: '/images/welcome/ribs-plate.jpg',
        category_id: 1,
        category: categories[0],
        modifiers: [{ id: 1, name: 'Extra sauce', price: 15 }],
        soldOut: false,
        lowStock: false,
    },
    {
        id: 35,
        name: 'Chicken Jerk',
        description: 'Grilled chicken with rice',
        price: 165,
        image: null,
        category_id: 1,
        category: categories[0],
        modifiers: [],
        soldOut: false,
        lowStock: false,
    },
    {
        id: 30,
        name: 'Rice',
        description: 'Extra rice',
        price: 25,
        image: null,
        category_id: 3,
        category: categories[2],
        modifiers: [],
        soldOut: false,
        lowStock: false,
    },
];
const query = new URLSearchParams(location.search);
const mode = query.get('preview');
// Mirrors the Laravel routes: the directory has no guide, a report page is
// reports-<type>, and everything else is named by its last path segment.
const resolveGuide = () => {
    if (!location.pathname.startsWith('/demo/')) {
        return query.get('guide') || undefined;
    }

    const parts = location.pathname.split('/').filter(Boolean);
    const last = parts.at(-1);

    if (last === 'functionality') {
        return undefined;
    }

    return parts.at(-2) === 'reports' ? `reports-${last}` : last;
};
const guide = resolveGuide();
const isGuide = mode === 'guide' || location.pathname.startsWith('/demo/');
const app = createApp({
    render: () =>
        h('div', {}, [
            isGuide
                ? h(Demo, { guide })
                : mode === 'dashboard'
                  ? h(Dashboard, dashboard)
                  : mode === 'orders'
                    ? h(OrderDetail, { order })
                    : mode === 'deposit'
                      ? h(DepositControl, {
                            historyView: query.get('history') === '1',
                        })
                      : mode === 'financial'
                        ? h(Financial)
                        : mode === 'reports'
                          ? h(Reports, {
                                initialDailyReport: dailyReport,
                                initialProductSales: reportProducts,
                            })
                          : h(POS, { products, categories }),
            h(Toaster),
        ]),
});
app.config.globalProperties.$page = {
    props: { auth: { user: { name: 'Demo Cashier' } } },
};
app.use(createPinia()).mount('#app');
