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
    it('shows the correct actions for each lifecycle phase and never actions on cancelled cards', async () => {
        const base = {
            id: 7,
            code: 'GT-STATE',
            owner_name: 'Customer',
            date: '2026-10-28',
            time: '12:00',
            end_time: '14:00',
            restaurant: { name: 'Restaurant', address: 'Address' },
            guest_count: 2,
            table: { name: 'Table 1', seats: 4 },
            table_id: 1,
            version: 'version',
            pre_orders: 0,
            has_pre_order: false,
            has_service_order: false,
            needs_review: false,
            updated: false,
            can_edit: false,
            can_cancel: false,
        };
        for (const [phase, changes, expected, absent] of [
            [
                'upcoming',
                {
                    can_edit: true,
                    can_cancel: true,
                    has_pre_order: true,
                    needs_review: true,
                    updated: true,
                },
                'Submit Pre-order',
                'Create Order',
            ],
            [
                'upcoming',
                { can_edit: true, can_cancel: true, has_pre_order: true },
                'View Pre-order',
                'Edit Order',
            ],
            ['in_progress', { can_edit: true }, 'Create Order', 'View Order'],
            [
                'in_progress',
                { can_edit: true, has_service_order: true },
                'Edit Order',
                'Create Order',
            ],
            ['completed', {}, 'View Order', 'Edit Order'],
            [
                'canceled',
                { has_pre_order: true },
                'Cancelled',
                'View Pre-order',
            ],
        ] as const) {
            const html = await renderToString(
                createSSRApp(Reservations, {
                    restaurant: {
                        id: 1,
                        name: 'Restaurant',
                        address: 'Address',
                    },
                    filters: { date: '2026-10-28' },
                    tables: [],
                    reservations: {
                        data: [{ ...base, ...changes, phase }],
                        links: [],
                    },
                    notifications: [],
                }),
            );
            expect(html).toContain(expected);
            expect(html).not.toContain(absent);
            if (phase === 'canceled') {
                expect(html).not.toContain('Edit reservation');
                expect(html).not.toContain('Cancel reservation');
            }
        }
    });
});
