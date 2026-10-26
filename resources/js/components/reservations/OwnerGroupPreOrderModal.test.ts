import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
    type TestNode,
} from '@/test-support/renderComponent';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import type { OwnerGroupOrder } from '@/types/owner-group-order';
import OwnerGroupPreOrderModal from './OwnerGroupPreOrderModal.vue';

vi.mock('@/lib/sessionJson', async (original) => ({
    ...(await original<typeof import('@/lib/sessionJson')>()),
    sessionJson: vi.fn(),
}));
vi.mock('@/components/ui/dialog', () => {
    const box = (tag: string) =>
        defineComponent({
            inheritAttrs: false,
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
vi.mock('@/components/bookings/BookingModal.vue', () => ({
    default: defineComponent({
        props: ['open', 'title'],
        setup:
            (props, { slots }) =>
            () =>
                props.open
                    ? h('aside', { 'data-confirmation': true }, [
                          h('h2', props.title),
                          slots.default?.(),
                      ])
                    : null,
    }),
}));
vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('button', attrs, slots.default?.()),
    }),
}));
const request = vi.mocked(sessionJson);
let mounted: ReturnType<typeof mountComponent>;
const changed = vi.fn();
function fixture(): OwnerGroupOrder {
    return {
        reservation_id: 7,
        version: `"${'a'.repeat(64)}"`,
        status: 'draft',
        submitted_at: null,
        editable: true,
        can_submit: true,
        active_participants: 2,
        removed_participants: 0,
        participants: [
            {
                id: 1,
                name: 'Owner',
                is_owner: true,
                confirmed: false,
                revoked: false,
                editable: true,
                can_remove: false,
                items: [],
                subtotal: '0.00',
            },
            {
                id: 2,
                name: 'Alex',
                is_owner: false,
                confirmed: false,
                revoked: false,
                editable: true,
                can_remove: true,
                items: [
                    {
                        id: 12,
                        menu_item_id: 10,
                        name: 'Salad',
                        quantity: 1,
                        unit_price: '14.00',
                        subtotal: '14.00',
                        notes: 'No sauce',
                    },
                ],
                subtotal: '14.00',
            },
        ],
        summary: {
            reservation_id: 7,
            pre_order_id: 1,
            items: [],
            guests: [],
            total_quantity: 1,
            grand_total: '14.00',
        },
    };
}
function text() {
    return textContent(mounted.root);
}
function button(name: string, parent: TestNode = mounted.root) {
    const found = findAll(
        parent,
        (n) =>
            n.tag === 'button' &&
            textContent(n).replace(/\s+/g, '') === name.replace(/\s+/g, ''),
    );
    expect(found).toHaveLength(1);
    return found[0];
}
async function click(name: string, parent?: TestNode) {
    await fire(button(name, parent), 'onClick', {
        currentTarget: { isConnected: true, focus: vi.fn() },
    });
    await flush();
}
function confirmation() {
    return findAll(mounted.root, (n) => n.tag === 'aside')[0];
}
beforeEach(async () => {
    vi.stubGlobal('window', {
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    });
    vi.stubGlobal('document', {
        getElementById: () => null,
        querySelector: () => null,
    });
    request.mockReset().mockResolvedValue(fixture());
    changed.mockReset();
    mounted = mountComponent(OwnerGroupPreOrderModal, {
        reservation: {
            id: 7,
            restaurant: { address: 'Actual address' },
            date: '2026-11-01',
            end_date: '2026-11-01',
            time: '12:00',
            end_time: '14:00',
            guest_count: 4,
        },
        onChanged: changed,
    });
    await flush();
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
});
describe('guest removal and empty owner group', () => {
    it('shows the empty owner entry and cancels removal without any write', async () => {
        expect(text()).toContain('My Pre-Order');
        expect(text()).toContain('Edit Pre-Order');
        expect(text()).toContain('14.00');
        await click('Remove Guest Alex');
        expect(textContent(confirmation())).toContain('Alex');
        expect(request).toHaveBeenCalledTimes(1);
        await click('Cancel', confirmation());
        expect(confirmation()).toBeUndefined();
        expect(text()).toContain('Guest: Alex');
        expect(changed).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(1);
    });
    it('removes only after confirmation and announces success from the server snapshot', async () => {
        await click('Remove Guest Alex');
        const updated = fixture();
        updated.version = `"${'b'.repeat(64)}"`;
        updated.participants.pop();
        updated.active_participants = 1;
        updated.removed_participants = 1;
        updated.summary.grand_total = '0.00';
        request.mockResolvedValueOnce(updated);
        await click('Remove Guest', confirmation());
        expect(request).toHaveBeenLastCalledWith(
            '/reservations/7/pre-order/owner-view/guests/2',
            'delete',
            expect.any(AbortSignal),
            undefined,
            `"${'a'.repeat(64)}"`,
        );
        expect(confirmation()).toBeUndefined();
        expect(text()).toContain('Guest Removed');
        expect(text()).not.toContain('Guest: Alex');
        expect(text()).toContain('0.00');
        expect(text()).toContain('1 active participants');
        expect(changed).toHaveBeenCalledOnce();
    });
    it('retains confirmation and guest on conflict without false success', async () => {
        await click('Remove Guest Alex');
        request.mockRejectedValueOnce(new SessionRequestError(409));
        await click('Remove Guest', confirmation());
        expect(confirmation()).toBeDefined();
        expect(text()).toContain('Guest: Alex');
        expect(text()).not.toContain('Guest Removed');
        expect(text()).toContain('permissions changed');
        expect(changed).not.toHaveBeenCalled();
        expect(button('Remove Guest', confirmation()).props.disabled).toBe(
            true,
        );
    });
});
