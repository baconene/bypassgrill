import { defineComponent, h } from 'vue';
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
            user: { name: 'Demo Cashier' },
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
