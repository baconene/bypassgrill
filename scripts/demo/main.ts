import { createPinia } from 'pinia';
import { createApp, h } from 'vue';
import { Toaster } from 'vue-sonner';
import POS from '../../resources/js/pages/CashierDashboard.vue';
import Dashboard from '../../resources/js/pages/Dashboard.vue';
import Demo from '../../resources/js/pages/DemoFunctionality.vue';
import { dashboard } from './dashboard';
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
const guide = location.pathname.startsWith('/demo/')
    ? location.pathname.split('/').filter(Boolean).at(-1) === 'functionality'
        ? undefined
        : location.pathname.split('/').filter(Boolean).at(-1)
    : query.get('guide') || undefined;
const isGuide = mode === 'guide' || location.pathname.startsWith('/demo/');
const app = createApp({
    render: () =>
        h('div', {}, [
            isGuide
                ? h(Demo, { guide })
                : mode === 'dashboard'
                  ? h(Dashboard, dashboard)
                  : h(POS, { products, categories }),
            h(Toaster),
        ]),
});
app.config.globalProperties.$page = {
    props: { auth: { user: { name: 'Demo Cashier' } } },
};
app.use(createPinia()).mount('#app');
