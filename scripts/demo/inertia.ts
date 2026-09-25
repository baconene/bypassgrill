import { defineComponent, h } from 'vue';
import { DEMO_USER_ID } from './deposit';
export const Head = defineComponent({ setup: () => () => null });
export const Link = defineComponent({
    props: ['href'],
    setup:
        (p, { slots }) =>
        () =>
            h('a', { href: p.href }, slots.default?.()),
});
export const usePage = () => ({
    props: {
        auth: {
            // Deposit control compares this id with the shift owner before it will
            // show the close and count controls, so the sample user needs one.
            user: { id: DEMO_USER_ID, name: 'Demo Cashier' },
            roles: [
                new URLSearchParams(location.search).get('preview') ===
                'dashboard'
                    ? 'admin'
                    : 'cashier',
            ],
        },
    },
});
export const router = {
    reload(options: {
        onStart?: () => void;
        onSuccess?: () => void;
        onFinish?: () => void;
    }) {
        options.onStart?.();
        options.onSuccess?.();
        options.onFinish?.();
    },
};
export const usePoll = () => {};
