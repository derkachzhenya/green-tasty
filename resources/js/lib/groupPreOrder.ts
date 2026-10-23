import type {
    ConsolidatedDish,
    DashboardOrder,
    SubmittedGroupOrder,
} from '@/types/reservation';

export function orderLocked(order: DashboardOrder): boolean {
    return (
        (order.status !== null && order.status !== 'draft') ||
        order.submitted_at !== null
    );
}

export function submittedOrder(
    value: unknown,
    reservationId: number,
): SubmittedGroupOrder {
    const object = (data: unknown): Record<string, unknown> => {
        if (!data || typeof data !== 'object' || Array.isArray(data))
            throw new Error('Invalid submission response.');
        return data as Record<string, unknown>;
    };
    const string = (data: unknown): string => {
        if (typeof data !== 'string')
            throw new Error('Invalid submission response.');
        return data;
    };
    const integer = (data: unknown, min = 1): number => {
        if (
            typeof data !== 'number' ||
            !Number.isSafeInteger(data) ||
            data < min
        )
            throw new Error('Invalid submission response.');
        return data;
    };
    const money = (data: unknown): string => {
        const result = string(data);
        if (!/^\d+\.\d{2}$/.test(result))
            throw new Error('Invalid submission response.');
        return result;
    };
    const list = (data: unknown): unknown[] => {
        if (!Array.isArray(data))
            throw new Error('Invalid submission response.');
        return data;
    };
    const dish = (data: unknown): ConsolidatedDish => {
        const item = object(data);
        return {
            menu_item_id: integer(item.menu_item_id),
            name: string(item.name),
            quantity: integer(item.quantity),
            subtotal: money(item.subtotal),
        };
    };
    const data = object(value);
    if (
        data.reservation_id !== reservationId ||
        data.status !== 'submitted' ||
        !Number.isFinite(Date.parse(string(data.submitted_at)))
    )
        throw new Error('Invalid submission response.');
    const items = list(data.items).map(dish);
    if (!items.length) throw new Error('Invalid submission response.');
    return {
        reservation_id: reservationId,
        pre_order_id: integer(data.pre_order_id),
        items,
        guests: list(data.guests).map((value) => {
            const guest = object(value);
            return {
                reservation_guest_id: integer(guest.reservation_guest_id),
                name: string(guest.name),
                total_quantity: integer(guest.total_quantity, 0),
                subtotal: money(guest.subtotal),
                items: list(guest.items).map((value) => ({
                    ...dish(value),
                    unit_price: money(object(value).unit_price),
                })),
            };
        }),
        total_quantity: integer(data.total_quantity),
        grand_total: money(data.grand_total),
        status: 'submitted',
        submitted_at: string(data.submitted_at),
    };
}

export function submissionTime(value: string, timezone: string): string {
    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
            timeZone: timezone,
        }).format(new Date(value));
    } catch {
        return 'Time unavailable';
    }
}
