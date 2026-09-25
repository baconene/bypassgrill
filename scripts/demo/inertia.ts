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
    props: { auth: { user: { name: 'Demo Cashier' }, roles: ['cashier'] } },
});
export const usePoll = () => {};
