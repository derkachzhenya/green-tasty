import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { h, type VNodeChild } from 'vue';
import catalog from '../../../data/restaurant-menu.json';
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

vi.mock('@/components/guest/GuestDishPreview.vue', () => ({
    default: {
        setup:
            (_: unknown, { slots }: { slots: { default: () => VNodeChild } }) =>
            () =>
                h('div', {}, [slots.default()]),
    },
}));

vi.mock('@/components/ui/button', () => ({
    Button: {
        setup:
            (_: unknown, { slots }: { slots: { default: () => VNodeChild } }) =>
            () =>
                h('button', {}, [slots.default()]),
    },
}));
vi.mock('@/components/bookings/BookingModal.vue', () => ({
    default: {
        props: ['open', 'title'],
        setup:
            (
                props: { open: boolean; title: string },
                { slots }: { slots: { default: () => VNodeChild } },
            ) =>
            () =>
                props.open
                    ? h('section', { 'data-name-modal': true }, [
                          h('h2', props.title),
                          slots.default(),
                      ])
                    : null,
    },
}));
vi.mock('@/components/ui/sheet', () => ({
    Sheet: {
        props: ['open'],
        setup:
            (
                props: { open: boolean },
                { slots }: { slots: { default: () => VNodeChild } },
            ) =>
            () =>
                props.open
                    ? h('section', { 'data-cart': true }, [slots.default()])
                    : null,
    },
    SheetContent: {
        setup:
            (_: unknown, { slots }: { slots: { default: () => VNodeChild } }) =>
            () =>
                h('div', {}, [slots.default()]),
    },
    SheetTitle: 'h2',
    SheetDescription: 'p',
}));

function fixture(): GuestMenu {
    const dish = {
        id: 10,
        name: 'Fresh Strawberry Mint Salad',
        description: 'Fresh vegetables',
        price: '17.00',
        available: true,
        created_at: '2026-10-01T00:00:00Z',
        popularity: 3,
        sort_order: 0,
    };
    const second = {
        ...dish,
        id: 11,
        name: 'Roasted Sweet Potato & Lentil Salad',
        price: '10.00',
        popularity: 5,
        created_at: '2026-10-02T00:00:00Z',
    };
    return {
        context: {
            restaurant: 'Green Tasty',
            address: '48 Rustaveli Avenue',
            table: 'Table 1',
            date: '2026-11-01',
            time: '12:00',
            end_date: '2026-11-01',
            end_time: '14:00',
            guests: 3,
        },
        guest_state: { name_confirmed: true, submitted_at: null, version: 0 },
        catalog: [
            {
                id: 1,
                name: 'Appetizers',
                dishes: [
                    dish,
                    second,
                    {
                        ...dish,
                        id: 12,
                        name: 'Sold Out Salad',
                        available: false,
                    },
                ],
            },
            {
                id: 2,
                name: 'Desserts',
                dishes: [{ ...dish, id: 13, name: 'Dessert', price: '2.00' }],
            },
        ],
        invitation: {
            restaurant: { name: 'Green Tasty' },
            guest: { name: 'Alice' },
            reservation: { reserved_at: '2026-11-01T08:00:00Z' },
            menu_categories: [
                { id: 1, name: 'Appetizers', dishes: [dish, second] },
                {
                    id: 2,
                    name: 'Desserts',
                    dishes: [
                        { ...dish, id: 13, name: 'Dessert', price: '2.00' },
                    ],
                },
            ],
        },
        selections: { items: [], total_quantity: 0, subtotal: '0.00' },
        order: { status: null, submitted_at: null, editable: true },
        timezone: 'Asia/Tbilisi',
    };
}
function response(data: unknown, status = 200): Response {
    return new Response(JSON.stringify(data), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
let server: GuestMenu;
let mounted: ReturnType<typeof mountComponent> | undefined;
let fetchMock: ReturnType<typeof vi.fn>;
let pageWindow: EventTarget;
let storage: ReturnType<typeof vi.fn>;
const nodes = (predicate: (node: TestNode) => boolean) =>
    findAll(mounted!.root, predicate);
const text = () => textContent(mounted!.root);
function button(label: string): TestNode {
    const node = nodes(
        (node) =>
            node.tag === 'button' &&
            (node.props['aria-label'] === label ||
                textContent(node).replace(/\s+/g, ' ').trim() === label),
    )[0];
    if (!node) throw new Error(`Missing button: ${label}`);
    return node;
}
async function mount(invalid = false) {
    mounted = mountComponent(Menu, { initiallyInvalid: invalid });
    await flush();
}
async function click(label: string) {
    await fire(button(label), 'onClick');
    await flush();
}
async function cart() {
    await click(`Open cart, ${server.selections.total_quantity} items`);
}
async function form() {
    await fire(nodes((node) => node.tag === 'form')[0], 'onSubmit');
    await flush();
}

beforeEach(() => {
    server = fixture();
    storage = vi.fn(() => {
        throw new Error('No bearer storage');
    });
    pageWindow = Object.assign(new EventTarget(), {
        location: { pathname: `/invitations/${'a'.repeat(64)}` },
    });
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    vi.stubGlobal('window', pageWindow);
    vi.stubGlobal('document', {
        getElementById: vi.fn(() => ({ focus: vi.fn() })),
    });
    vi.stubGlobal('localStorage', { setItem: storage, getItem: storage });
    vi.stubGlobal('sessionStorage', { setItem: storage, getItem: storage });
    fetchMock = vi.fn(async (path: string, options: RequestInit) => {
        if (options.method === 'GET') return response(server);
        if (typeof options.body !== 'string')
            throw new Error('Expected a JSON request body.');
        const body = JSON.parse(options.body);
        if (path.endsWith('/identity')) {
            server.invitation.guest.name = body.name;
            server.guest_state.name_confirmed = true;
            return response(server);
        }
        if (path.endsWith('/submit')) {
            server.guest_state.submitted_at = '2026-10-25T08:00:00Z';
            server.order.editable = false;
            return response(server);
        }
        server.selections.items = body.items.map(
            (item: { menu_item_id: number; quantity: number }) => {
                const dish = server.catalog
                    .flatMap((category) => category.dishes)
                    .find((dish) => dish.id === item.menu_item_id)!;
                const unit =
                    server.selections.items.find(
                        (saved) => saved.menu_item_id === item.menu_item_id,
                    )?.unit_price ?? dish.price;
                return {
                    ...item,
                    name: dish.name,
                    unit_price: unit,
                    subtotal: (Number(unit) * item.quantity).toFixed(2),
                };
            },
        );
        server.selections.total_quantity = server.selections.items.reduce(
            (sum, item) => sum + item.quantity,
            0,
        );
        server.selections.subtotal = server.selections.items
            .reduce((sum, item) => sum + Number(item.subtotal), 0)
            .toFixed(2);
        server.guest_state.version++;
        server.order.status = 'draft';
        return response(server.selections);
    });
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    mounted?.unmount();
    mounted = undefined;
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('Figma guest pre-order flow', () => {
    it('renders all installed catalog photographs while keeping server prices authoritative', async () => {
        const template = server.catalog[0].dishes[0];
        server.catalog[0].dishes = catalog.dishes.map((dish, index) => ({
            ...template,
            id: 100 + index,
            name: dish.name,
            available: dish.available,
            price: index === 0 ? '99.99' : dish.price,
            sort_order: index,
            popularity: 0,
        }));
        await mount();
        expect(nodes((n) => n.tag === 'article')).toHaveLength(8);
        const sources = nodes((n) => n.tag === 'img').map((n) => n.props.src);
        for (const dish of catalog.dishes)
            expect(sources).toContain(dish.image);
        expect(text()).toContain('99.99');
        expect(
            nodes((n) => n.props['aria-label'] === 'Add Green Asparagus Salad'),
        ).toHaveLength(0);
    });
    it('loads invitation data without browser storage, cookies or referrers', async () => {
        await mount();
        expect(text()).toContain('48 Rustaveli Avenue');
        expect(text()).toContain('12:00 – 14:00');
        expect(fetchMock.mock.calls[0][1]).toMatchObject({
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'error',
            referrerPolicy: 'no-referrer',
        });
        expect(storage).not.toHaveBeenCalled();
    });
    it('shows the required name modal with actual reservation facts', async () => {
        server.guest_state.name_confirmed = false;
        await mount();
        expect(text()).toContain('You’re Invited!');
        expect(text()).toContain('3 people');
        expect(button('Open cart, 0 items').props.disabled).toBe(true);
        await fire(
            nodes((n) => n.tag === 'input')[0],
            'onUpdate:modelValue',
            'Bob',
        );
        await form();
        expect(text()).not.toContain('You’re Invited!');
        expect(server.invitation.guest.name).toBe('Bob');
        expect(button('Open cart, 0 items').props.disabled).toBe(false);
    });
    it.each(['', '   ', 'x'.repeat(256)])(
        'rejects invalid guest name %s before a write',
        async (value) => {
            server.guest_state.name_confirmed = false;
            await mount();
            await fire(
                nodes((n) => n.tag === 'input')[0],
                'onUpdate:modelValue',
                value,
            );
            await form();
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(
                nodes((n) => n.props.role === 'alert').length,
            ).toBeGreaterThan(0);
        },
    );
    it('shows backend name errors without leaving the invitation', async () => {
        server.guest_state.name_confirmed = false;
        await mount();
        fetchMock.mockResolvedValueOnce(
            response({ errors: { name: ['Choose another name.'] } }, 422),
        );
        await form();
        expect(text()).toContain('Choose another name.');
        expect(text()).toContain('You’re Invited!');
    });
    it('renders only the selected category and supports category navigation', async () => {
        await mount();
        expect(text()).not.toContain('Add Dessert');
        await click('Desserts');
        expect(button('Add Dessert')).toBeTruthy();
        expect(nodes((n) => n.tag === 'article')).toHaveLength(1);
    });
    it('sorts actual prices and popularity without changing prices', async () => {
        await mount();
        expect(nodes((n) => n.tag === 'article')[0].props['aria-label']).toBe(
            'Roasted Sweet Potato & Lentil Salad',
        );
        const select = nodes((n) => n.tag === 'select')[0];
        await fire(select, 'onChange', { target: { value: 'price-desc' } });
        expect(nodes((n) => n.tag === 'article')[0].props['aria-label']).toBe(
            'Fresh Strawberry Mint Salad',
        );
        await fire(select, 'onChange', { target: { value: 'newest' } });
        expect(nodes((n) => n.tag === 'article')[0].props['aria-label']).toBe(
            'Roasted Sweet Potato & Lentil Salad',
        );
    });
    it('omits purchase actions for sold-out dishes and reuses the original dish images', async () => {
        await mount();
        expect(
            nodes((n) => n.props['aria-label'] === 'Add Sold Out Salad'),
        ).toHaveLength(0);
        expect(text()).toContain('Sold Out');
        expect(nodes((n) => n.tag === 'img').map((n) => n.props.src)).toContain(
            '/images/green-tasty/dishes/fresh-strawberry-mint-salad.png',
        );
    });
    it('shows the empty drawer with its original illustration and returns to menu', async () => {
        await mount();
        await cart();
        expect(text()).toContain('Your cart is empty');
        expect(nodes((n) => n.tag === 'img').map((n) => n.props.src)).toContain(
            '/images/green-tasty/icons/Illustration.png',
        );
        await click('Go to Menu');
        expect(text()).not.toContain('Your cart is empty');
    });
    it('persists a new dish and reloads the authoritative price and version', async () => {
        await mount();
        await click('Add Fresh Strawberry Mint Salad');
        expect(server.selections.subtotal).toBe('17.00');
        const call = fetchMock.mock.calls.find(
            (call) => call[1].method === 'PUT',
        )!;
        expect(JSON.parse(call[1].body)).toEqual({
            items: [{ menu_item_id: 10, quantity: 1 }],
        });
        expect(call[1].headers['If-Match']).toBe('"0"');
        await cart();
        expect(
            textContent(
                nodes(
                    (n) =>
                        n.tag === 'output' &&
                        n.props['aria-label'] === 'Your pre-order total',
                )[0],
            ),
        ).toBe('17.00');
    });
    it('updates quantities and removes only the selected guest line', async () => {
        await mount();
        await click('Add Fresh Strawberry Mint Salad');
        await click('Increase Fresh Strawberry Mint Salad quantity');
        expect(server.selections.subtotal).toBe('34.00');
        await cart();
        await click('Remove Fresh Strawberry Mint Salad');
        expect(server.selections.total_quantity).toBe(0);
        expect(text()).toContain('Your cart is empty');
    });
    it('preserves saved historical prices and restores the cart on reload', async () => {
        server.selections = {
            items: [
                {
                    menu_item_id: 10,
                    name: 'Fresh Strawberry Mint Salad',
                    quantity: 2,
                    unit_price: '0.10',
                    subtotal: '0.20',
                },
            ],
            total_quantity: 2,
            subtotal: '0.20',
        };
        await mount();
        await click('Increase Fresh Strawberry Mint Salad quantity');
        expect(server.selections.subtotal).toBe('0.30');
        mounted?.unmount();
        await mount();
        await cart();
        expect(text()).toContain('Unit price: 0.10');
        expect(text()).toContain('0.30');
    });
    it('confirms only after server success and refresh restores the submitted screen', async () => {
        await mount();
        await click('Add Fresh Strawberry Mint Salad');
        await cart();
        await click('Submit Pre-Order');
        expect(text()).toContain('Pre-Order Submitted!');
        expect(text()).toContain('17.00');
        expect(button('Create New Pre-Order').props.disabled).toBeDefined();
        expect(text()).not.toContain('Submit Pre-OrderCart');
        const call = fetchMock.mock.calls.find((call) =>
            call[0].endsWith('/submit'),
        )!;
        expect(call[1].method).toBe('POST');
        expect(call[1].headers['If-Match']).toBe('"1"');
        mounted?.unmount();
        await mount();
        expect(text()).toContain('Pre-Order Submitted!');
    });
    it('never shows success for a failed or uncertain submission', async () => {
        await mount();
        await click('Add Fresh Strawberry Mint Salad');
        await cart();
        fetchMock.mockRejectedValueOnce(new Error('Connection lost'));
        await click('Submit Pre-Order');
        expect(text()).not.toContain('Pre-Order Submitted!');
        expect(text()).toContain('have not been confirmed');
        expect(button('Submit Pre-Order')).toBeTruthy();
    });
    it('prevents duplicate submission while its request is pending', async () => {
        await mount();
        await click('Add Fresh Strawberry Mint Salad');
        await cart();
        fetchMock.mockReturnValueOnce(new Promise(() => {}));
        void fire(button('Submit Pre-Order'), 'onClick');
        await flush();
        expect(button('Submitting…').props.disabled).toBe(true);
        const calls = fetchMock.mock.calls.length;
        await fire(button('Submitting…'), 'onClick');
        expect(fetchMock).toHaveBeenCalledTimes(calls);
    });
    it('locks a stale cart on 409 and allows authoritative refresh', async () => {
        await mount();
        fetchMock.mockResolvedValueOnce(response({}, 409));
        await click('Add Fresh Strawberry Mint Salad');
        expect(text()).toContain('Refresh before making more changes');
        await click('Refresh menu and status');
        expect(text()).not.toContain('Refresh before making more changes');
        expect(server.selections.total_quantity).toBe(0);
    });
    it.each([422, 429, 500])(
        'retains a draft on save error %s without claiming success',
        async (status) => {
            await mount();
            fetchMock.mockResolvedValueOnce(
                response({ errors: { items: ['Dishes changed.'] } }, status),
            );
            await click('Add Fresh Strawberry Mint Salad');
            await click('Open cart, 1 items');
            expect(text()).not.toContain('Pre-Order Submitted!');
            expect(button('Retry saving cart')).toBeTruthy();
        },
    );
    it('clears private data when an invitation is revoked during a write', async () => {
        await mount();
        fetchMock.mockResolvedValueOnce(response({}, 404));
        await click('Add Fresh Strawberry Mint Salad');
        expect(text()).toContain('Invitation unavailable');
        expect(text()).not.toContain('48 Rustaveli Avenue');
    });
    it('adopts the matching cart snapshot and version returned after name confirmation', async () => {
        server.guest_state.name_confirmed = false;
        await mount();
        server.selections = {
            items: [
                {
                    menu_item_id: 10,
                    name: 'Fresh Strawberry Mint Salad',
                    quantity: 2,
                    unit_price: '17.00',
                    subtotal: '34.00',
                },
            ],
            total_quantity: 2,
            subtotal: '34.00',
        };
        server.guest_state.version = 1;
        await form();
        await click('Open cart, 2 items');
        expect(text()).toContain('34.00');
    });
    it('requires another review when a cart changes between saving and the follow-up read', async () => {
        await mount();
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await click('Add Fresh Strawberry Mint Salad');
        await click('Open cart, 1 items');
        const saved = {
            items: [
                {
                    menu_item_id: 10,
                    name: 'Fresh Strawberry Mint Salad',
                    quantity: 1,
                    unit_price: '17.00',
                    subtotal: '17.00',
                },
            ],
            total_quantity: 1,
            subtotal: '17.00',
        };
        server.selections = {
            items: [{ ...saved.items[0], quantity: 9, subtotal: '153.00' }],
            total_quantity: 9,
            subtotal: '153.00',
        };
        server.guest_state.version = 2;
        fetchMock
            .mockResolvedValueOnce(response(saved))
            .mockResolvedValueOnce(response(server));
        await click('Submit Pre-Order');
        expect(
            fetchMock.mock.calls.some((call) => call[0].endsWith('/submit')),
        ).toBe(false);
        expect(text()).not.toContain('Pre-Order Submitted!');
        expect(text()).toContain('153.00');
        expect(text()).toContain('Review the saved selections');
    });
    it('renders a locked group without allowing edits or creating a guest confirmation', async () => {
        server.order.editable = false;
        server.order.status = 'submitted';
        await mount();
        expect(text()).toContain('Selections are locked');
        expect(text()).not.toContain('Pre-Order Submitted!');
    });
    it('handles initial invalid and loading states without private content', async () => {
        await mount(true);
        expect(text()).toContain('Invitation unavailable');
        expect(fetchMock).not.toHaveBeenCalled();
        mounted?.unmount();
        fetchMock.mockReturnValue(new Promise(() => {}));
        await mount();
        expect(text()).toContain('Loading your menu');
        expect(text()).not.toContain('48 Rustaveli Avenue');
    });
    it('offers retry after initial network failure', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await mount();
        expect(text()).toContain('Unable to load your menu');
        await click('Try again');
        expect(text()).toContain('48 Rustaveli Avenue');
    });
    it('rejects malformed money rather than showing a transactional total', async () => {
        server.catalog[0].dishes[0].price = 'NaN';
        await mount();
        expect(text()).toContain('Unable to load your menu');
    });
    it('clears bearer context on pagehide and reloads after bfcache restore', async () => {
        await mount();
        pageWindow.dispatchEvent(new Event('pagehide'));
        await flush();
        expect(text()).not.toContain('48 Rustaveli Avenue');
        const event = Object.assign(new Event('pageshow'), { persisted: true });
        pageWindow.dispatchEvent(event);
        await flush();
        expect(text()).toContain('48 Rustaveli Avenue');
        expect(storage).not.toHaveBeenCalled();
    });
});
