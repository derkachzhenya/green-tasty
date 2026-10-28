import { renderToString } from '@vue/server-renderer';
import { describe, expect, it } from 'vite-plus/test';
import { createSSRApp, h } from 'vue';
import type { DashboardOrder, ReservationDetails } from '@/types/reservation';
import GroupPreOrderSummary from './GroupPreOrderSummary.vue';
import ReservationOverview from './ReservationOverview.vue';
import ReservationParticipants from './ReservationParticipants.vue';

function order(overrides: Partial<DashboardOrder> = {}): DashboardOrder {
    return {
        participants: [],
        status: 'draft',
        submitted_at: null,
        summary: {
            reservation_id: 1,
            pre_order_id: 2,
            items: [
                {
                    menu_item_id: 10,
                    name: 'Vegetable Bowl',
                    quantity: 3,
                    subtotal: '34.50',
                },
            ],
            guests: [
                {
                    reservation_guest_id: 1,
                    name: 'Owner',
                    total_quantity: 1,
                    subtotal: '12.50',
                    items: [
                        {
                            menu_item_id: 10,
                            name: 'Vegetable Bowl',
                            quantity: 1,
                            unit_price: '12.50',
                            subtotal: '12.50',
                        },
                    ],
                },
                {
                    reservation_guest_id: 2,
                    name: 'Alice',
                    total_quantity: 2,
                    subtotal: '22.00',
                    items: [
                        {
                            menu_item_id: 10,
                            name: 'Vegetable Bowl',
                            quantity: 2,
                            unit_price: '11.00',
                            subtotal: '22.00',
                        },
                    ],
                },
            ],
            total_quantity: 3,
            grand_total: '34.50',
        },
        ...overrides,
    };
}

function renderOrder(value = order()): Promise<string> {
    return renderToString(
        createSSRApp({
            render: () => h(GroupPreOrderSummary, { order: value }),
        }),
    );
}

describe('consolidated group pre-order', () => {
    it('shows the submitted UTC timestamp in the reservation timezone without changing money', async () => {
        const value = order({
            status: 'submitted',
            submitted_at: '2026-10-23T08:00:00+00:00',
        });
        const html = await renderToString(
            createSSRApp({
                render: () =>
                    h(GroupPreOrderSummary, {
                        order: value,
                        timezone: 'Asia/Tbilisi',
                    }),
            }),
        );
        expect(html).toContain('datetime="2026-10-23T08:00:00+00:00"');
        expect(html).toContain('23 Oct 2026, 12:00');
        expect(html).toContain('Asia/Tbilisi');
        expect(html).toContain('34.50');
    });
    it('shows aggregation and different saved prices using saved prices and consistent dollar currency', async () => {
        const html = await renderOrder();
        expect(html).toContain('Consolidated dishes');
        expect(html).toContain('$12.50 · $11.00');
        expect(html).toContain('34.50');
        expect(html).toContain('Quantity: 2 · Saved unit price: $11.00');
        expect(html).toContain('22.00');
        expect(html).toContain('$34.50');
        expect(html).not.toContain('99.99');
        expect(html).not.toContain('<input');
        expect(html).not.toContain('Submit pre-order');
    });

    it('renders backend decimal totals exactly instead of performing floating-point calculations', async () => {
        const value = order();
        value.summary.items[0].subtotal = '123456789012345678.90';
        value.summary.grand_total = '123456789012345678.90';
        value.summary.guests[0].items[0].unit_price = '0.10';
        value.summary.guests[0].items[0].subtotal = '0.30';
        const html = await renderOrder(value);
        expect(html).toContain('123456789012345678.90');
        expect(html).toContain('Saved unit price: $0.10');
        expect(html).toContain('0.30');
        expect(html).not.toContain('0.30000000000000004');
    });

    it('labels submitted selections with a link to current permissions and no duplicate submission controls', async () => {
        const html = await renderOrder(
            order({
                status: 'submitted',
                submitted_at: '2026-10-23T08:00:00+00:00',
            }),
        );
        expect(html).toContain(
            'This group pre-order has been submitted. Open Group Pre-Order to view the current review and permitted changes.',
        );
        expect(html).not.toContain('Draft —');
        expect(html).not.toMatch(/<(button|input|form)\b/);
    });

    it('treats an inconsistent submission timestamp conservatively as locked', async () => {
        const html = await renderOrder(
            order({ submitted_at: '2026-10-23T08:00:00+00:00' }),
        );
        expect(html).toContain('Selections are locked for this order.');
        expect(html).not.toContain('has not been submitted');
    });

    it('shows an empty not-started order with its server-provided zero total', async () => {
        const value = order({ status: null });
        value.summary = {
            reservation_id: 1,
            pre_order_id: null,
            items: [],
            guests: [],
            total_quantity: 0,
            grand_total: '0.00',
        };
        const html = await renderOrder(value);
        expect(html).toContain('Not started');
        expect(html).toContain('No dishes selected');
        expect(html).toContain('0.00');
        expect(html).not.toContain('Selections by participant');
    });

    it('escapes participant and dish names and provides native keyboard-accessible disclosure controls', async () => {
        const value = order();
        value.summary.guests[0].name = '<img src=x onerror=alert(1)>';
        value.summary.items[0].name = '<script>alert(1)</script>';
        const html = await renderOrder(value);
        expect(html).not.toContain('<img src=x');
        expect(html).not.toContain('<script>');
        expect(html).toContain('&lt;script&gt;');
        expect(html).toContain('<details');
        expect(html).toContain('<summary');
        expect(html).toContain('Selected dishes for');
        expect(html).toContain('aria-labelledby="group-order-heading"');
    });
});

describe('reservation participants', () => {
    it('separates owner, invitation validity and participation response statuses', async () => {
        const html = await renderToString(
            createSSRApp({
                render: () =>
                    h(ReservationParticipants, {
                        selections: order().summary.guests,
                        participants: [
                            {
                                id: 1,
                                name: 'Owner',
                                is_owner: true,
                                response_status: 'pending',
                                invitation_status: 'owner',
                                invitation_expires_at: null,
                            },
                            {
                                id: 2,
                                name: 'Alice',
                                is_owner: false,
                                response_status: 'accepted',
                                invitation_status: 'active',
                                invitation_expires_at:
                                    '2026-10-24T08:00:00+00:00',
                            },
                            {
                                id: 3,
                                name: 'Bob',
                                is_owner: false,
                                response_status: 'pending',
                                invitation_status: 'revoked',
                                invitation_expires_at: null,
                            },
                            {
                                id: 4,
                                name: 'Carol',
                                is_owner: false,
                                response_status: 'pending',
                                invitation_status: 'expired',
                                invitation_expires_at:
                                    '2026-10-22T08:00:00+00:00',
                            },
                        ],
                    }),
            }),
        );
        expect(html).toContain('Owner · no invitation needed');
        expect(html).toContain('(you)');
        expect(html).toContain('Active invitation');
        expect(html).toContain('Revoked invitation');
        expect(html).toContain('Expired invitation');
        expect(html).toContain('accepted');
        expect(html).toContain('2 selected');
        expect(html).toContain('No saved selections');
        expect(html).not.toContain('/invitations/');
        expect(html).not.toContain('<button');
    });

    it('shows a useful empty state', async () => {
        const html = await renderToString(
            createSSRApp({
                render: () => h(ReservationParticipants, { participants: [] }),
            }),
        );
        expect(html).toContain('No participants yet');
        expect(html).toContain('role="status"');
    });
});

describe('reservation overview', () => {
    it('uses supplied local dates including midnight boundaries and handles an unassigned table', async () => {
        const reservation: ReservationDetails = {
            id: 1,
            code: 'GT-TEST',
            status: 'cancelled',
            guest_count: 3,
            restaurant: {
                name: 'Green Tasty',
                slug: 'test',
                address: 'Tbilisi',
            },
            table: null,
            date: '2026-10-24',
            time: '23:30',
            end_date: '2026-10-25',
            end_time: '01:30',
            timezone: 'Asia/Tbilisi',
        };
        const html = await renderToString(
            createSSRApp({
                render: () => h(ReservationOverview, { reservation }),
            }),
        );
        expect(html).toContain('cancelled');
        expect(html).toContain('Asia/Tbilisi');
        expect(html).toContain('2026-10-25');
        expect(html).toContain('No longer assigned');
        expect(html).toContain('Guests, including you');
    });
});
