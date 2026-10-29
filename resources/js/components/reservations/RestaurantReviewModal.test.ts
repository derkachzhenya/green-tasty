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
        request.mockResolvedValueOnce({ ...fixture(), editable: false });
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
