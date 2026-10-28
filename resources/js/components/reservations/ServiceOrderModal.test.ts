import {
    beforeEach,
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import {
    mountComponent,
    findAll,
    fire,
    flush,
    textContent,
} from '@/test-support/renderComponent';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import ServiceOrderModal from './ServiceOrderModal.vue';
import type { ServiceOrder } from '@/types/restaurant-review';
vi.mock('@/lib/sessionJson', async (original) => ({
    ...(await original<typeof import('@/lib/sessionJson')>()),
    sessionJson: vi.fn(),
}));
vi.mock('@/components/ui/dialog', () => {
    const box = (tag: string) =>
        defineComponent({
            setup:
                (_, { attrs, slots }) =>
                () =>
                    h(tag, attrs, slots.default?.()),
        });
    return {
        Dialog: box('section'),
        DialogContent: box('article'),
        DialogTitle: box('h2'),
        DialogDescription: box('p'),
    };
});
vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('button', attrs, slots.default?.()),
    }),
}));
const request = vi.mocked(sessionJson),
    version = `"${'a'.repeat(64)}"`;
const fixture = (): ServiceOrder => ({
    exists: true,
    revision: 1,
    editable: true,
    phase: 'in_progress',
    version,
    items: [
        {
            line_key: 'new-1',
            menu_item_id: 1,
            name: 'Salad',
            quantity: 1,
            unit_price: '10.50',
            notes: 'No onions',
            total: '10.50',
        },
    ],
    total: '10.50',
    catalog: [{ id: 2, name: 'Soup', price: '4.10', is_available: true }],
});
let mounted: ReturnType<typeof mountComponent>;
const changed = vi.fn(),
    close = vi.fn();
function mount() {
    return mountComponent(ServiceOrderModal, {
        restaurantId: 1,
        reservation: {
            id: 7,
            code: 'GT-ORDER',
            owner_name: 'Owner',
            date: '2026-10-28',
            time: '12:00',
            end_time: '14:00',
            restaurant: { address: 'Address' },
            guest_count: 2,
        },
        onChanged: changed,
        onClose: close,
    });
}
const buttons = () => findAll(mounted.root, (node) => node.tag === 'button');
const named = (label: string) =>
    buttons().find(
        (node) =>
            node.props['aria-label'] === label || textContent(node) === label,
    )!;
beforeEach(async () => {
    request.mockReset().mockResolvedValue(fixture());
    changed.mockReset();
    close.mockReset();
    mounted = mount();
    await flush();
});
afterEach(() => mounted.unmount());
describe('service order', () => {
    it('changes quantities and totals locally, and cancel restores all saved selections without a write', async () => {
        await fire(named('Increase Salad'), 'onClick');
        await flush();
        expect(textContent(mounted.root)).toContain('$21.00');
        await fire(named('Cancel Changes'), 'onClick');
        await flush();
        expect(textContent(mounted.root)).toContain('$10.50');
        expect(named('Save Order').props.disabled).toBe(true);
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('sends IDs, quantities and notes with the server version, never client prices', async () => {
        await fire(named('Increase Salad'), 'onClick');
        await flush();
        request.mockResolvedValueOnce({ ...fixture(), revision: 2 });
        await fire(named('Save Order'), 'onClick');
        await flush();
        expect(request).toHaveBeenLastCalledWith(
            '/staff/restaurants/1/reservations/7/order',
            'put',
            expect.any(AbortSignal),
            {
                items: [
                    {
                        line_key: 'new-1',
                        menu_item_id: 1,
                        quantity: 2,
                        notes: 'No onions',
                    },
                ],
            },
            version,
        );
        expect(changed).toHaveBeenCalledOnce();
        expect(textContent(mounted.root)).toContain('Order saved.');
    });
    it('never retries an ambiguous or stale write and requires refresh', async () => {
        await fire(named('Increase Salad'), 'onClick');
        await flush();
        request.mockRejectedValueOnce(new SessionRequestError(409));
        await fire(named('Save Order'), 'onClick');
        await flush();
        expect(named('Save Order').props.disabled).toBe(true);
        expect(named('Refresh order')).toBeDefined();
        expect(changed).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(2);
    });
    it('makes completed orders read-only, keeping dishes, notes and amounts', async () => {
        mounted.unmount();
        request.mockResolvedValueOnce({
            ...fixture(),
            editable: false,
            phase: 'completed',
            catalog: [],
        });
        mounted = mount();
        await flush();
        expect(textContent(mounted.root)).toContain('View Order');
        expect(textContent(mounted.root)).toContain('No onions');
        expect(textContent(mounted.root)).toContain('$10.50');
        expect(named('Save Order')).toBeUndefined();
        expect(named('Increase Salad')).toBeUndefined();
        await fire(named('Close order'), 'onClick');
        expect(close).toHaveBeenCalledOnce();
    });
    it('does not allow an empty order to be saved', async () => {
        await fire(named('Remove Salad'), 'onClick');
        await flush();
        expect(textContent(mounted.root)).toContain('No dishes in this order.');
        expect(named('Save Order').props.disabled).toBe(true);
    });
});
