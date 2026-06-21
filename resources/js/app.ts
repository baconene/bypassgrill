import { createInertiaApp } from '@inertiajs/vue3';
import { initOfflineSync } from '@/utils/offlineSync';
import { createApp, h } from 'vue';
import { createPinia } from 'pinia';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import VueApexCharts from 'vue3-apexcharts';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name === 'Menu':
                return null;
            case name === 'PublicOrderPage':
                return null;
            case name === 'PrintingArchitecture':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    setup({ el, App, props, plugin }) {
        const pinia = createPinia();

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(VueApexCharts)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();
initializeFlashToast();
if (typeof window !== 'undefined') {
    initOfflineSync();
}
