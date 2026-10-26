import { renderToString } from '@vue/server-renderer';
import { describe, it, expect, vi } from 'vite-plus/test';
import { createSSRApp, defineComponent, h } from 'vue';
import Reservations from './Reservations.vue';
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { auth: { user: { name: 'Authorized Waiter' } } },
    }),
    router: { reload: vi.fn() },
    Head: { render: () => null },
    Link: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('a', attrs, slots.default?.()),
    }),
}));
describe('waiter SSR', () => {
    it('renders an authorized empty result without optional reservation props', async () => {
        const html = await renderToString(
            createSSRApp(Reservations, {
                restaurant: {
                    id: 1,
                    name: 'Actual Restaurant',
                    address: 'Address',
                },
                filters: { date: '2026-11-01' },
                tables: [],
                reservations: { data: [], links: [] },
                notifications: [],
            }),
        );
        expect(html).toContain('Authorized Waiter');
        expect(html).toContain('No reservations');
        expect(html).not.toContain('undefined');
    });
});
