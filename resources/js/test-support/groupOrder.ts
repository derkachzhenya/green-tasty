import type { DashboardOrder, SubmittedGroupOrder } from '@/types/reservation';

export function groupOrder(): DashboardOrder {
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
    };
}
export function submissionResult(): SubmittedGroupOrder {
    return {
        ...groupOrder().summary,
        status: 'submitted',
        submitted_at: '2026-10-23T08:00:00+00:00',
    };
}
