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
    version?: number,
): Promise<unknown> {
    return request(
        path,
        signal,
        items === undefined ? 'GET' : 'PUT',
        items === undefined ? undefined : { items },
        version,
    );
}

export function guestAction(
    path: string,
    signal: AbortSignal,
    body: unknown,
    version?: number,
): Promise<unknown> {
    return request(path, signal, 'POST', body, version);
}

async function request(
    path: string,
    signal: AbortSignal,
    method: string,
    body?: unknown,
    version?: number,
): Promise<unknown> {
    const owner =
        /^\/reservations\/[1-9]\d*\/owner-pre-order\/(menu|selections|submit)$/.test(
            path,
        );
    const csrf = owner
        ? document.cookie
              .split('; ')
              .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
              ?.slice(11)
        : undefined;
    const response = await fetch(path, {
        method,
        headers: {
            Accept: 'application/json',
            ...(csrf ? { 'X-XSRF-TOKEN': decodeURIComponent(csrf) } : {}),
            'Content-Type': 'application/json',
            ...(version === undefined ? {} : { 'If-Match': `"${version}"` }),
        },
        credentials: owner ? 'same-origin' : 'omit',
        cache: 'no-store',
        redirect: 'error',
        referrerPolicy: 'no-referrer',
        signal,
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
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
    const context = record(data.context);
    const state = record(data.guest_state);
    if (typeof state.name_confirmed !== 'boolean')
        throw new Error('Invalid guest response.');
    if (typeof order.editable !== 'boolean')
        throw new Error('Invalid guest response.');
    return {
        context: {
            restaurant: string(context.restaurant),
            address: string(context.address),
            table: string(context.table),
            date: string(context.date),
            time: string(context.time),
            end_date: string(context.end_date),
            end_time: string(context.end_time),
            guests: integer(context.guests),
        },
        guest_state: {
            name_confirmed: state.name_confirmed,
            submitted_at: nullableString(state.submitted_at),
            version: integer(state.version, 0),
        },
        catalog: list(data.catalog).map((value) => {
            const category = record(value);
            return {
                id: integer(category.id),
                name: string(category.name),
                dishes: list(category.dishes).map((value) => {
                    const dish = record(value);
                    if (typeof dish.available !== 'boolean')
                        throw new Error('Invalid guest response.');
                    return {
                        id: integer(dish.id),
                        name: string(dish.name),
                        description: nullableString(dish.description),
                        price: money(dish.price),
                        available: dish.available,
                        created_at: nullableString(dish.created_at),
                        popularity: integer(dish.popularity, 0),
                        sort_order: integer(dish.sort_order, 0),
                    };
                }),
            };
        }),
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
