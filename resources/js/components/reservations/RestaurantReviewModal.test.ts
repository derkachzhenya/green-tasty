import {
    beforeEach,
    afterEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h, nextTick } from 'vue';
import {
    mountComponent,
    findAll,
    fire,
    flush,
    textContent,
} from '@/test-support/renderComponent';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import RestaurantReviewModal from './RestaurantReviewModal.vue';
import OwnerRestaurantReview from './OwnerRestaurantReview.vue';
import type { RestaurantReview } from '@/types/restaurant-review';
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
const request = vi.mocked(sessionJson);
const version = `"${'a'.repeat(64)}"`;
function fixture(): RestaurantReview {
    return {
        version,
        revision: 1,
        status: 'Created by Customer',
        editable: true,
        can_submit: false,
        participants: [
            {
                id: 1,
                name: 'Owner',
                is_owner: true,
                items: [
                    {
                        id: 12,
                        menu_item_id: 10,
                        name: 'Salad',
                        quantity: 2,
                        unit_price: '10.00',
                        total: '20.00',
                        notes: 'No onions',
                        decision: 'pending',
                        published: false,
                        superseded: false,
                    },
                ],
            },
        ],
        totals: {
            requested: '20.00',
            confirmed: '0.00',
            rejected: '0.00',
            pending: '20.00',
        },
        events: [],
    };
}
let mounted: ReturnType<typeof mountComponent>;
const changed = vi.fn(),
    close = vi.fn();
const buttons = () => findAll(mounted.root, (n) => n.tag === 'button');
beforeEach(async () => {
    request.mockReset().mockResolvedValue(fixture());
    changed.mockReset();
    close.mockReset();
    mounted = mountComponent(RestaurantReviewModal, {
        restaurantId: 1,
        reservation: {
            id: 7,
            code: 'GT-TEST',
            restaurant: { address: 'Actual address' },
            date: '2026-11-01',
            time: '12:00',
            end_time: '14:00',
            guest_count: 4,
        },
        onChanged: changed,
        onClose: close,
    });
    await flush();
});
afterEach(() => {
    mounted.unmount();
    vi.useRealTimers();
});
describe('restaurant review controls', () => {
    it('requires every decision, publishes rejections and keeps the result read-only', async () => {
        const initial = fixture();
        initial.participants.push({
            id: 2,
            name: 'Alex',
            is_owner: false,
            items: [
                {
                    ...initial.participants[0].items[0],
                    id: 13,
                    name: 'Soup',
                    quantity: 1,
                    unit_price: '12.00',
                    total: '12.00',
                },
            ],
        });
        mounted.unmount();
        request.mockResolvedValueOnce(initial);
        mounted = mountComponent(RestaurantReviewModal, {
            restaurantId: 1,
            reservation: {
                id: 7,
                code: 'GT-TEST',
                restaurant: { address: 'Address' },
            },
        });
        await flush();
        const submit = () =>
            buttons().find((node) => textContent(node) === 'Submit Pre-order')!;
        const control = (name: string) =>
            buttons().find((node) => node.props['aria-label'] === name)!;
        expect(submit().props.disabled).toBe(true);
        const partial = structuredClone(initial);
        partial.version = 'second-version';
        partial.participants[0].items[0].decision = 'confirmed';
        request.mockResolvedValueOnce(partial);
        await fire(control('Confirm Salad (Owner)'), 'onClick');
        expect(submit().props.disabled).toBe(true);
        const checked = structuredClone(partial);
        checked.version = 'third-version';
        checked.participants[1].items[0].decision = 'rejected';
        checked.can_submit = true;
        request.mockResolvedValueOnce(checked);
        await fire(control('Reject Soup (Alex)'), 'onClick');
        expect(request).toHaveBeenLastCalledWith(
            '/staff/restaurants/1/reservations/7/review/items/13',
            'patch',
            expect.any(AbortSignal),
            { decision: 'rejected' },
            'second-version',
        );
        expect(submit().props.disabled).toBe(false);
        expect(textContent(mounted.root)).toContain(
            'Owner will be notified after you submit.',
        );
        const published = structuredClone(checked);
        published.status = 'Confirmed by Restaurant';
        published.editable = published.can_submit = false;
        published.participants.forEach((participant) =>
            participant.items.forEach((item) => {
                item.published = true;
            }),
        );
        request.mockResolvedValueOnce(published);
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
        await fire(submit(), 'onClick');
        expect(request).toHaveBeenLastCalledWith(
            '/staff/restaurants/1/reservations/7/review/submit',
            'post',
            expect.any(AbortSignal),
            undefined,
            'third-version',
        );
        expect(textContent(mounted.root)).toContain('Confirmed by Restaurant');
        expect(textContent(mounted.root)).toContain(
            'Owner was notified about it.',
        );
        expect(control('Confirm Salad (Owner)').props.disabled).toBe(true);
        expect(control('Reject Soup (Alex)').props.disabled).toBe(true);
        expect(submit().props.disabled).toBe(true);
        expect(textContent(mounted.root)).toContain(
            'Pre-order submitted. The owner can see the result.',
        );
        vi.advanceTimersByTime(5000);
        await nextTick();
        expect(textContent(mounted.root)).not.toContain(
            'Pre-order submitted. The owner can see the result.',
        );
        expect(textContent(mounted.root)).toContain('Reservation Pre-Order');
    });
    it('reviews new guest items without reopening previously published confirmations', async () => {
        const updated = fixture();
        updated.revision = 2;
        updated.status = 'Updated Pre-Order';
        updated.participants[0].items[0].decision = 'confirmed';
        updated.participants[0].items[0].published = true;
        updated.participants.push({
            id: 2,
            name: 'Julia',
            is_owner: false,
            items: [
                { ...fixture().participants[0].items[0], id: 14, name: 'Soup' },
            ],
        });
        mounted.unmount();
        request.mockResolvedValueOnce(updated);
        mounted = mountComponent(RestaurantReviewModal, {
            restaurantId: 1,
            reservation: { id: 7, restaurant: { address: 'Address' } },
        });
        await flush();
        const control = (name: string) =>
            buttons().find((node) => node.props['aria-label'] === name)!;
        expect(control('Confirm Salad (Owner)').props.disabled).toBe(true);
        expect(control('Confirm Salad (Owner)').props['aria-pressed']).toBe(
            true,
        );
        expect(control('Confirm Soup (Julia)').props.disabled).toBe(false);
        expect(textContent(mounted.root).replace(/\s+/g, ' ')).toContain(
            'Julia has updated the pre-order.',
        );
        const checked = structuredClone(updated);
        checked.participants[1].items[0].decision = 'confirmed';
        checked.can_submit = true;
        request.mockResolvedValueOnce(checked);
        await fire(control('Confirm Soup (Julia)'), 'onClick');
        expect(control('Confirm Salad (Owner)').props.disabled).toBe(true);
        expect(
            buttons().find((node) => textContent(node) === 'Submit Pre-order')!
                .props.disabled,
        ).toBe(false);
    });
    it('identifies owner-only updates that need a new restaurant review', async () => {
        const updated = fixture();
        updated.revision = 2;
        updated.status = 'Updated Pre-Order';
        request.mockResolvedValueOnce(updated);
        mounted.unmount();
        mounted = mountComponent(RestaurantReviewModal, {
            restaurantId: 1,
            reservation: {
                id: 7,
                code: 'GT-TEST',
                restaurant: { address: 'Address' },
            },
        });
        await flush();
        expect(textContent(mounted.root).replace(/\s+/g, ' ')).toContain(
            'Owner has updated the pre-order. Review all new dishes before submitting.',
        );
    });
    it('refreshes at the cutoff and cannot confirm canceled dishes', async () => {
        mounted.unmount();
        vi.useFakeTimers({
            toFake: ['setInterval', 'clearInterval', 'performance'],
        });
        const initial = fixture();
        initial.timing = {
            server_now: '2026-11-01T07:29:59Z',
            warning_at: '2026-11-01T07:15:00Z',
            locks_at: '2026-11-01T07:30:00Z',
            locked: false,
            unconfirmed: true,
        };
        request.mockResolvedValueOnce(initial);
        mounted = mountComponent(RestaurantReviewModal, {
            restaurantId: 1,
            reservation: { id: 7, restaurant: { address: 'Actual address' } },
        });
        await flush();
        const expired = fixture();
        expired.timing = {
            ...initial.timing,
            locked: true,
            unconfirmed: false,
        };
        expired.editable = expired.can_submit = false;
        expired.participants[0].items[0].superseded = true;
        expired.participants[0].items[0].cancelled = true;
        expired.totals.requested = expired.totals.pending = '0.00';
        request.mockResolvedValueOnce(expired);
        await vi.advanceTimersByTimeAsync(1000);
        await flush();
        expect(textContent(mounted.root)).toContain(
            'Canceled at the 30-minute deadline',
        );
        expect(
            buttons().some(
                (n) => n.props['aria-label'] === 'Confirm Salad (Owner)',
            ),
        ).toBe(false);
        expect(
            buttons().find((n) => textContent(n) === 'Submit Pre-order')!.props
                .disabled,
        ).toBe(true);
        await vi.advanceTimersByTimeAsync(5000);
        expect(request).toHaveBeenCalledTimes(3);
        expect(request.mock.calls.every((call) => call[1] === 'get')).toBe(
            true,
        );
    });
    it('renders real quantities, saved prices, notes and accessible pending controls', () => {
        expect(textContent(mounted.root)).toContain('No onions');
        expect(textContent(mounted.root)).toContain('10.00 each');
        const confirm = buttons().find(
            (n) => n.props['aria-label'] === 'Confirm Salad (Owner)',
        )!;
        expect(confirm.props['aria-pressed']).toBe(false);
        expect(
            buttons().find((n) => textContent(n) === 'Submit Pre-order')!.props
                .disabled,
        ).toBe(true);
    });
    it('sends the opaque version, then enables submission only from the server result', async () => {
        request.mockResolvedValueOnce({ ...fixture(), can_submit: true });
        await fire(
            buttons().find(
                (n) => n.props['aria-label'] === 'Confirm Salad (Owner)',
            )!,
            'onClick',
        );
        await flush();
        expect(request).toHaveBeenLastCalledWith(
            '/staff/restaurants/1/reservations/7/review/items/12',
            'patch',
            expect.any(AbortSignal),
            { decision: 'confirmed' },
            version,
        );
        expect(changed).toHaveBeenCalledTimes(1);
        expect(
            buttons().find((n) => textContent(n) === 'Submit Pre-order')!.props
                .disabled,
        ).toBe(false);
    });
    it('blocks stale writes, offers refresh and never claims failed submission succeeded', async () => {
        request.mockRejectedValueOnce(new SessionRequestError(409, {}));
        await fire(
            buttons().find(
                (n) => n.props['aria-label'] === 'Reject Salad (Owner)',
            )!,
            'onClick',
        );
        await flush();
        expect(textContent(mounted.root)).toContain('Refresh review');
        expect(changed).not.toHaveBeenCalled();
        expect(
            buttons().find(
                (n) => n.props['aria-label'] === 'Confirm Salad (Owner)',
            )!.props.disabled,
        ).toBe(true);
    });
    it('published reviews are read-only and close action is explicit', async () => {
        mounted.unmount();
        const published = fixture();
        published.status = 'Confirmed by Restaurant';
        published.editable = false;
        published.participants[0].items[0].decision = 'confirmed';
        published.participants[0].items[0].published = true;
        request.mockResolvedValueOnce(published);
        mounted = mountComponent(RestaurantReviewModal, {
            restaurantId: 1,
            reservation: { id: 7, restaurant: { address: 'A' } },
            onClose: close,
        });
        await flush();
        expect(
            buttons().find(
                (n) => n.props['aria-label'] === 'Confirm Salad (Owner)',
            )!.props.disabled,
        ).toBe(true);
        expect(textContent(mounted.root)).not.toContain(
            'Some items were rejected.',
        );
        const calls = request.mock.calls.length;
        await fire(
            buttons().find(
                (n) => n.props['aria-label'] === 'Confirm Salad (Owner)',
            )!,
            'onClick',
        );
        expect(request).toHaveBeenCalledTimes(calls);
        await fire(
            buttons().find((n) => n.props['aria-label'] === 'Close review')!,
            'onClick',
        );
        expect(close).toHaveBeenCalledOnce();
    });
    it('owner rejection corrections submit server-selected IDs without price or total input', async () => {
        mounted.unmount();
        const review = fixture();
        review.can_correct = true;
        review.catalog = [{ id: 11, name: 'Replacement', price: '15.50' }];
        review.participants[0].items[0].decision = 'rejected';
        review.participants[0].items[0].published = true;
        mounted = mountComponent(OwnerRestaurantReview, {
            review,
            reservationId: 7,
            onChanged: changed,
        });
        const select = findAll(mounted.root, (n) => n.tag === 'select')[0];
        await fire(select, 'onUpdate:modelValue', 11);
        await flush();
        request.mockResolvedValueOnce(review);
        await fire(
            findAll(mounted.root, (n) => n.tag === 'form')[0],
            'onSubmit',
            { preventDefault: vi.fn() },
        );
        await flush();
        expect(request).toHaveBeenLastCalledWith(
            '/reservations/7/restaurant-review/items/12/replace',
            'post',
            expect.any(AbortSignal),
            { menu_item_id: 11, quantity: 1 },
            version,
        );
        expect(changed).toHaveBeenCalledOnce();
    });
});
