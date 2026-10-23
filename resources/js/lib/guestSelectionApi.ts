import type {
    GuestMenu,
    GuestSelectionSummary,
    SelectionInput,
} from '@/types/guest-selection';

export class GuestSelectionError extends Error {
    constructor(
        public status: number,
        public messages: string[] = [],
    ) {
        super('Guest selection request failed.');
    }
}

export async function guestRequest(
    path: string,
    signal: AbortSignal,
    items?: SelectionInput[],
): Promise<unknown> {
    const response = await fetch(path, {
        method: items === undefined ? 'GET' : 'PUT',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        credentials: 'omit',
        cache: 'no-store',
        redirect: 'error',
        referrerPolicy: 'no-referrer',
        signal,
        ...(items === undefined ? {} : { body: JSON.stringify({ items }) }),
    });
    if (!response.ok) {
        const data = (await response.json().catch(() => null)) as {
            errors?: Record<string, unknown>;
        } | null;
        const messages = Object.values(data?.errors ?? {}).flatMap((value) =>
            Array.isArray(value)
                ? value.filter(
                      (message): message is string =>
                          typeof message === 'string',
                  )
                : [],
        );
        throw new GuestSelectionError(response.status, messages);
    }
    return response.json();
}

function record(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw new Error('Invalid guest response.');
    return value as Record<string, unknown>;
}
function string(value: unknown): string {
    if (typeof value !== 'string') throw new Error('Invalid guest response.');
    return value;
}
function money(value: unknown): string {
    const result = string(value);
    if (!/^\d+\.\d{2}$/.test(result))
        throw new Error('Invalid guest response.');
    return result;
}
function integer(value: unknown, minimum = 1): number {
    if (
        typeof value !== 'number' ||
        !Number.isSafeInteger(value) ||
        value < minimum
    )
        throw new Error('Invalid guest response.');
    return value;
}
function list(value: unknown): unknown[] {
    if (!Array.isArray(value)) throw new Error('Invalid guest response.');
    return value;
}
function nullableString(value: unknown): string | null {
    return value === null ? null : string(value);
}

export function selectionSummary(value: unknown): GuestSelectionSummary {
    const data = record(value);
    return {
        items: list(data.items).map((value) => {
            const item = record(value);
            return {
                menu_item_id: integer(item.menu_item_id),
                name: string(item.name),
                quantity: integer(item.quantity),
                unit_price: money(item.unit_price),
                subtotal: money(item.subtotal),
            };
        }),
        total_quantity: integer(data.total_quantity, 0),
        subtotal: money(data.subtotal),
    };
}

export function guestMenu(value: unknown): GuestMenu {
    const data = record(value);
    const invitation = record(data.invitation);
    const order = record(data.order);
    if (typeof order.editable !== 'boolean')
        throw new Error('Invalid guest response.');
    return {
        invitation: {
            restaurant: { name: string(record(invitation.restaurant).name) },
            guest: { name: string(record(invitation.guest).name) },
            reservation: {
                reserved_at: string(record(invitation.reservation).reserved_at),
            },
            menu_categories: list(invitation.menu_categories).map((value) => {
                const category = record(value);
                return {
                    id: integer(category.id),
                    name: string(category.name),
                    dishes: list(category.dishes).map((value) => {
                        const dish = record(value);
                        return {
                            id: integer(dish.id),
                            name: string(dish.name),
                            description: nullableString(dish.description),
                            price: money(dish.price),
                        };
                    }),
                };
            }),
        },
        selections: selectionSummary(data.selections),
        order: {
            status: nullableString(order.status),
            submitted_at: nullableString(order.submitted_at),
            editable: order.editable,
        },
        timezone: string(data.timezone),
    };
}
