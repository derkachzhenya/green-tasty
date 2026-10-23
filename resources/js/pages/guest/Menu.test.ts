import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { h, type VNodeChild } from 'vue';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
    type TestNode,
} from '@/test-support/renderComponent';
import type { GuestMenu } from '@/types/guest-selection';
import Menu from './Menu.vue';

vi.mock('@/components/ui/button', () => ({
    Button: {
        setup:
            (
                _props: unknown,
                { slots }: { slots: { default: () => VNodeChild } },
            ) =>
            () =>
                h('button', {}, [slots.default()]),
    },
}));

function fixture(): GuestMenu {
    return {
        invitation: {
            restaurant: { name: 'Green Tasty Test' },
            guest: { name: 'Alice' },
            reservation: { reserved_at: '2026-11-01T08:00:00+00:00' },
            menu_categories: [
                {
                    id: 1,
                    name: 'Main courses',
                    dishes: [
                        {
                            id: 10,
                            name: 'Vegetable Bowl',
                            description: 'Fresh vegetables',
                            price: '12.50',
                        },
                    ],
                },
                {
                    id: 2,
                    name: 'Starters',
                    dishes: [
                        {
                            id: 11,
                            name: 'Garden Salad',
                            description: null,
                            price: '0.20',
                        },
                    ],
                },
            ],
        },
        selections: { items: [], total_quantity: 0, subtotal: '0.00' },
        order: { status: null, submitted_at: null, editable: true },
        timezone: 'Asia/Tbilisi',
    };
}
function saved(menu: GuestMenu): GuestMenu {
    menu.selections = {
        items: [
            {
                menu_item_id: 10,
                name: 'Vegetable Bowl',
                quantity: 2,
                unit_price: '0.10',
                subtotal: '0.20',
            },
        ],
        total_quantity: 2,
        subtotal: '0.20',
    };
    menu.order.status = 'draft';
    return menu;
}
function response(data: unknown, status = 200): Response {
    return new Response(JSON.stringify(data), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
let mounted: ReturnType<typeof mountComponent> | undefined;
let fetchMock: ReturnType<typeof vi.fn>;
let pageWindow: EventTarget;
let storeToken: ReturnType<typeof vi.fn>;
function mount(invalid = false) {
    mounted = mountComponent(Menu, { initiallyInvalid: invalid });
}
function text() {
    return textContent(mounted!.root);
}
function buttons(label: string): TestNode[] {
    return findAll(
        mounted!.root,
        (node) =>
            node.tag === 'button' &&
            (node.props['aria-label'] === label || textContent(node) === label),
    );
}
function button(label: string): TestNode {
    const result = buttons(label)[0];
    if (!result) throw new Error(`Missing button: ${label}`);
    return result;
}
function total(): string {
    return textContent(
        findAll(
            mounted!.root,
            (node) => node.props['aria-label'] === 'Your pre-order total',
        )[0],
    );
}
async function save() {
    await fire(
        findAll(mounted!.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
    await flush();
}

beforeEach(() => {
    storeToken = vi.fn(() => {
        throw new Error('Do not store bearer tokens.');
    });
    pageWindow = Object.assign(new EventTarget(), {
        location: { pathname: `/invitations/${'a'.repeat(64)}` },
    });
    vi.stubGlobal('window', pageWindow);
    vi.stubGlobal('document', {});
    vi.stubGlobal('localStorage', {
        getItem: vi.fn(() => {
            throw new Error('Do not store bearer tokens.');
        }),
        setItem: storeToken,
    });
    vi.stubGlobal('sessionStorage', {
        getItem: vi.fn(() => {
            throw new Error('Do not store bearer tokens.');
        }),
        setItem: storeToken,
    });
    fetchMock = vi
        .fn()
        .mockImplementation(() => Promise.resolve(response(fixture())));
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    mounted?.unmount();
    mounted = undefined;
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('invited guest menu interactions', () => {
    it('shows loading while validating the invitation and retrieving saved dishes', async () => {
        fetchMock.mockReturnValue(new Promise(() => {}));
        mount();
        await flush();
        expect(text()).toContain('Loading your menu');
        expect(buttons('Save selections')).toHaveLength(0);
    });
    it('browses real categories, descriptions and prices without sign-in', async () => {
        mount();
        await flush();
        expect(text()).toContain('Green Tasty Test');
        expect(text()).toContain('Alice');
        expect(text()).toContain('1 Nov 2026, 12:00 (Asia/Tbilisi)');
        expect(text()).toContain('Fresh vegetables');
        expect(text()).toContain('12.50');
        const links = findAll(mounted!.root, (node) => node.tag === 'a');
        expect(links.map((node) => node.props.href)).toContain('#category-2');
        expect(links.map((node) => node.props.href)).toContain('#your-order');
        expect(text()).not.toContain('Sign in');
        expect(total()).toBe('0.00');
        expect(button('Save selections').props.disabled).toBe(true);
    });
    it('adds multiple dishes, changes quantities and removes a line using accessible buttons', async () => {
        mount();
        await flush();
        await fire(button('Add Vegetable Bowl'), 'onClick');
        await fire(button('Increase Vegetable Bowl quantity'), 'onClick');
        await fire(button('Add Garden Salad'), 'onClick');
        expect(total()).toBe('25.20');
        expect(button('Save selections').props.disabled).toBe(false);
        await fire(button('Decrease Vegetable Bowl quantity'), 'onClick');
        expect(total()).toBe('12.70');
        await fire(button('Remove Vegetable Bowl'), 'onClick');
        expect(total()).toBe('0.20');
        expect(text()).toContain('You have unsaved changes.');
    });
    it('restores saved quantities and historical prices instead of current menu prices', async () => {
        fetchMock.mockResolvedValue(response(saved(fixture())));
        mount();
        await flush();
        expect(total()).toBe('0.20');
        expect(text()).toContain('Unit price: 0.10 · Quantity: 2');
        expect(text()).toContain('Price: 12.50');
        await fire(button('Increase Vegetable Bowl quantity'), 'onClick');
        expect(total()).toBe('0.30');
    });
    it('keeps the original snapshot when a saved dish is removed and re-added before saving', async () => {
        fetchMock.mockResolvedValue(response(saved(fixture())));
        mount();
        await flush();
        await fire(button('Remove Vegetable Bowl'), 'onClick');
        await fire(button('Add Vegetable Bowl'), 'onClick');
        expect(total()).toBe('0.10');
    });
    it('sends only integer IDs and quantities and displays the server-confirmed snapshot', async () => {
        mount();
        await flush();
        await fire(button('Add Vegetable Bowl'), 'onClick');
        fetchMock.mockResolvedValueOnce(
            response({
                items: [
                    {
                        menu_item_id: 10,
                        name: 'Vegetable Bowl',
                        quantity: 1,
                        unit_price: '13.00',
                        subtotal: '13.00',
                    },
                ],
                total_quantity: 1,
                subtotal: '13.00',
            }),
        );
        await save();
        const [url, options] = fetchMock.mock.calls[1];
        expect(url).toBe(`/invitations/${'a'.repeat(64)}/selections`);
        expect(options).toMatchObject({
            method: 'PUT',
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error',
            referrerPolicy: 'no-referrer',
        });
        expect(JSON.parse(options.body)).toEqual({
            items: [{ menu_item_id: 10, quantity: 1 }],
        });
        expect(total()).toBe('13.00');
        expect(text()).toContain('Your selections are saved.');
        expect(button('Save selections').props.disabled).toBe(true);
    });
    it('saves an empty replacement and takes the current price if the dish is then re-added', async () => {
        fetchMock.mockResolvedValueOnce(response(saved(fixture())));
        mount();
        await flush();
        await fire(button('Remove Vegetable Bowl'), 'onClick');
        fetchMock.mockResolvedValueOnce(response(fixture().selections));
        await save();
        expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
            items: [],
        });
        expect(total()).toBe('0.00');
        await fire(button('Add Vegetable Bowl'), 'onClick');
        expect(total()).toBe('12.50');
    });
    it('does not send a duplicate save while a save is pending and disables controls', async () => {
        mount();
        await flush();
        await fire(button('Add Garden Salad'), 'onClick');
        fetchMock.mockReturnValue(new Promise(() => {}));
        await save();
        expect(button('Saving selections…').props.disabled).toBe(true);
        expect(button('Increase Garden Salad quantity').props.disabled).toBe(
            true,
        );
        await save();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
    it('prevents quantities above 99 and removes the dish when decremented to zero', async () => {
        const data = saved(fixture());
        data.selections.items[0].quantity = 99;
        fetchMock.mockResolvedValueOnce(response(data));
        mount();
        await flush();
        expect(button('Increase Vegetable Bowl quantity').props.disabled).toBe(
            true,
        );
        // Guard the business boundary even if a disabled event is dispatched programmatically.
        await fire(button('Increase Vegetable Bowl quantity'), 'onClick');
        expect(total()).toBe('9.90');
        await fire(button('Remove Vegetable Bowl'), 'onClick');
        await fire(button('Add Vegetable Bowl'), 'onClick');
        await fire(button('Decrease Vegetable Bowl quantity'), 'onClick');
        expect(total()).toBe('0.00');
    });
    it('retains unavailable saved dishes for review and requires their removal before saving', async () => {
        const data = saved(fixture());
        data.invitation.menu_categories[0].dishes = [];
        fetchMock.mockResolvedValueOnce(response(data));
        mount();
        await flush();
        expect(text()).toContain('No longer available.');
        expect(total()).toBe('0.20');
        await fire(button('Add Garden Salad'), 'onClick');
        expect(button('Save selections').props.disabled).toBe(true);
        await save();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await fire(button('Remove Vegetable Bowl'), 'onClick');
        expect(button('Save selections').props.disabled).toBe(false);
    });
    it('shows empty menu and empty category states with a reachable order panel', async () => {
        const data = fixture();
        data.invitation.menu_categories = [];
        fetchMock.mockResolvedValueOnce(response(data));
        mount();
        await flush();
        expect(text()).toContain('The menu is being prepared');
        expect(text()).toContain('No dishes selected.');
        data.invitation.menu_categories = [
            { id: 1, name: 'Empty category', dishes: [] },
        ];
        fetchMock.mockResolvedValueOnce(response(data));
        await fire(button('Refresh menu and status'), 'onClick');
        await flush();
        expect(text()).toContain('No dishes are available in this category.');
    });
    it('shows submitted selections as read-only and does not issue writes', async () => {
        const data = saved(fixture());
        data.order = {
            status: 'submitted',
            submitted_at: '2026-11-01T07:00:00Z',
            editable: false,
        };
        fetchMock.mockResolvedValueOnce(response(data));
        mount();
        await flush();
        expect(text()).toContain('The group pre-order is locked.');
        expect(total()).toBe('0.20');
        expect(buttons('Save selections')).toHaveLength(0);
        expect(buttons('Remove Vegetable Bowl')).toHaveLength(0);
        expect(buttons('Add Garden Salad')).toHaveLength(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
    it('handles a submission race with 409 and replaces unsaved edits with the saved snapshot', async () => {
        fetchMock.mockResolvedValueOnce(response(saved(fixture())));
        mount();
        await flush();
        await fire(button('Increase Vegetable Bowl quantity'), 'onClick');
        fetchMock.mockResolvedValueOnce(response({}, 409));
        await save();
        expect(total()).toBe('0.20');
        expect(text()).toContain('Your changes were not saved.');
        expect(buttons('Save selections')).toHaveLength(0);
    });
    it.each([404, 429, 500])(
        'handles initial HTTP %i without showing private guest data',
        async (status) => {
            fetchMock.mockResolvedValueOnce(response({}, status));
            mount();
            await flush();
            expect(text()).not.toContain('Alice');
            expect(text()).not.toContain('Vegetable Bowl');
            if (status === 404)
                expect(text()).toContain('Invitation unavailable');
            else expect(buttons('Try again')).toHaveLength(1);
        },
    );
    it('does not fetch or reveal data for an invitation rejected by the HTML endpoint', async () => {
        mount(true);
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(text()).toContain(
            'Ask the reservation owner for a new invitation.',
        );
    });
    it('rejects a malformed URL without making requests', async () => {
        Object.assign(pageWindow, {
            location: { pathname: '/invitations/invalid' },
        });
        mount();
        await flush();
        expect(fetchMock).not.toHaveBeenCalled();
        expect(text()).toContain('Invitation unavailable');
    });
    it('retries an initial network error', async () => {
        fetchMock.mockRejectedValueOnce(new Error('network'));
        mount();
        await flush();
        await fire(button('Try again'), 'onClick');
        await flush();
        expect(text()).toContain('Alice');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
    it('retains the draft after a failed save so it can be retried', async () => {
        mount();
        await flush();
        await fire(button('Add Garden Salad'), 'onClick');
        fetchMock.mockRejectedValueOnce(new Error('network'));
        await save();
        expect(total()).toBe('0.20');
        expect(text()).toContain('Your changes have not been confirmed.');
        expect(button('Save selections').props.disabled).toBe(false);
    });
    it('displays server validation errors as text and keeps the unsaved draft', async () => {
        mount();
        await flush();
        await fire(button('Add Garden Salad'), 'onClick');
        fetchMock.mockResolvedValueOnce(
            response(
                {
                    errors: {
                        items: ['Dish is unavailable.'],
                        'items.0.quantity': [
                            'Quantity must be between 1 and 99.',
                        ],
                    },
                },
                422,
            ),
        );
        await save();
        expect(text()).toContain('Dish is unavailable.');
        expect(text()).toContain('Quantity must be between 1 and 99.');
        expect(total()).toBe('0.20');
    });
    it('clears all guest data if an invitation is revoked, expired or regenerated while open', async () => {
        mount();
        await flush();
        await fire(button('Add Garden Salad'), 'onClick');
        fetchMock.mockResolvedValueOnce(response({}, 404));
        await save();
        expect(text()).toContain('Invitation unavailable');
        expect(text()).not.toContain('Alice');
        expect(text()).not.toContain('Garden Salad');
        expect(buttons('Save selections')).toHaveLength(0);
    });
    it('refreshes menu eligibility without discarding unsaved draft quantities', async () => {
        fetchMock.mockResolvedValueOnce(response(saved(fixture())));
        mount();
        await flush();
        await fire(button('Increase Vegetable Bowl quantity'), 'onClick');
        fetchMock.mockResolvedValueOnce(response(saved(fixture())));
        await fire(button('Refresh menu and status'), 'onClick');
        await flush();
        expect(total()).toBe('0.30');
        expect(text()).toContain('You have unsaved changes.');
    });
    it('refreshes into the persisted submitted snapshot if the owner finalizes the group', async () => {
        fetchMock.mockResolvedValueOnce(response(saved(fixture())));
        mount();
        await flush();
        await fire(button('Increase Vegetable Bowl quantity'), 'onClick');
        const data = saved(fixture());
        data.order.editable = false;
        data.order.status = 'submitted';
        fetchMock.mockResolvedValueOnce(response(data));
        await fire(button('Refresh menu and status'), 'onClick');
        await flush();
        expect(total()).toBe('0.20');
        expect(buttons('Save selections')).toHaveLength(0);
    });
    it('does not consume an unsafe or malformed money response', async () => {
        const data = fixture();
        data.invitation.menu_categories[0].dishes[0].price = 'NaN';
        fetchMock.mockResolvedValueOnce(response(data));
        mount();
        await flush();
        expect(text()).toContain('Unable to load your menu');
        expect(buttons('Add Vegetable Bowl')).toHaveLength(0);
    });
    it('clears private data on pagehide and reloads after a back-forward cache restore', async () => {
        mount();
        await flush();
        pageWindow.dispatchEvent(new Event('pagehide'));
        await flush();
        expect(text()).not.toContain('Alice');
        pageWindow.dispatchEvent(
            Object.assign(new Event('pageshow'), { persisted: true }),
        );
        await flush();
        expect(text()).toContain('Alice');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
    it('ignores late responses after pagehide and aborts the pending bearer request', async () => {
        let finish!: (value: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        );
        mount();
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        pageWindow.dispatchEvent(new Event('pagehide'));
        finish(response(saved(fixture())));
        await flush();
        expect(signal.aborted).toBe(true);
        expect(text()).not.toContain('Alice');
    });
    it('warns about unsaved changes before leaving and never uses persistent storage', async () => {
        mount();
        await flush();
        await fire(button('Add Garden Salad'), 'onClick');
        const event = new Event('beforeunload', { cancelable: true });
        pageWindow.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(storeToken).not.toHaveBeenCalled();
        expect(fetchMock.mock.calls[0][1]).toMatchObject({
            credentials: 'omit',
            referrerPolicy: 'no-referrer',
        });
    });
});
