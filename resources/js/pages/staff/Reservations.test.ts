import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h, nextTick } from 'vue';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import type { WaiterReservation } from '@/types/restaurant-review';
import Reservations from './Reservations.vue';

const navigation = vi.hoisted(() => ({ reload: vi.fn(), get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { name: 'Waiter' } } } }),
    router: navigation,
    Head: { render: () => null },
    Link: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('a', attrs, slots.default?.()),
    }),
}));
vi.mock('@/lib/sessionJson', async (original) => ({
    ...(await original<typeof import('@/lib/sessionJson')>()),
    sessionJson: vi.fn(),
}));
vi.mock('@/components/bookings/BookingModal.vue', () => ({
    default: defineComponent({
        props: ['open', 'title'],
        setup:
            (props, { slots }) =>
            () =>
                props.open
                    ? h('aside', [h('h2', props.title), slots.default?.()])
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

const reservation: WaiterReservation = {
    id: 7,
    code: 'GT-TEST',
    owner_name: 'Customer',
    date: '2026-11-05',
    time: '12:00',
    end_time: '14:00',
    duration_minutes: 120,
    guest_count: 2,
    active_participants: 2,
    table_id: 1,
    restaurant: { name: 'Restaurant', address: 'Address' },
    table: { name: 'Table 1', seats: 2 },
    phase: 'upcoming',
    version: 'saved-version',
    pre_orders: 0,
    has_pre_order: false,
    needs_review: false,
    updated: false,
    can_edit: true,
    can_cancel: true,
};
let mounted: ReturnType<typeof mountComponent>;
const request = vi.mocked(sessionJson);
function text() {
    return textContent(mounted.root);
}
function confirmation() {
    return findAll(mounted.root, (node) => node.tag === 'aside')[0];
}
function button(name: string) {
    const target = findAll(
        mounted.root,
        (node) =>
            node.tag === 'button' &&
            (node.props['aria-label'] === name ||
                textContent(node).trim() === name),
    )[0];
    if (!target) throw new Error(`Missing button: ${name}`);
    return target;
}
async function openCancellation() {
    await fire(button('Cancel reservation GT-TEST'), 'onClick', {
        currentTarget: null,
    });
}
beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('HTMLElement', class {});
    request.mockReset();
    navigation.reload.mockReset();
    mounted = mountComponent(Reservations, {
        restaurant: { id: 1, name: 'Restaurant', address: 'Address' },
        filters: { date: '2026-11-05' },
        tables: [],
        reservations: { data: [reservation], links: [] },
        notifications: [],
    });
});
afterEach(() => {
    mounted.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('waiter cancellation confirmation', () => {
    it('keeps the reservation without sending a request', async () => {
        await openCancellation();
        await fire(button('Keep it'), 'onClick');
        expect(confirmation()).toBeUndefined();
        expect(request).not.toHaveBeenCalled();
    });
    it('closes only after server success and dismisses the success notice after five seconds', async () => {
        let complete!: (value: unknown) => void;
        request.mockReturnValueOnce(
            new Promise((resolve) => {
                complete = resolve;
            }),
        );
        await openCancellation();
        const pending = fire(button('Cancel'), 'onClick');
        await nextTick();
        expect(confirmation()).toBeDefined();
        expect(button('Cancelling…').props.disabled).toBe(true);
        expect(text()).not.toContain('Reservation Cancelled!');
        expect(request).toHaveBeenCalledWith(
            '/staff/restaurants/1/reservations/7/cancel',
            'post',
            expect.any(AbortSignal),
            {},
            'saved-version',
        );
        complete({ status: 'cancelled' });
        await pending;
        expect(confirmation()).toBeUndefined();
        expect(text()).toContain('Reservation Cancelled!');
        expect(navigation.reload).toHaveBeenCalledOnce();
        vi.advanceTimersByTime(4999);
        await nextTick();
        expect(text()).toContain('Reservation Cancelled!');
        vi.advanceTimersByTime(1);
        await nextTick();
        expect(text()).not.toContain('Reservation Cancelled!');
    });
    it('keeps the confirmation open on a conflict without announcing success', async () => {
        request.mockRejectedValueOnce(new SessionRequestError(409));
        await openCancellation();
        await fire(button('Cancel'), 'onClick');
        expect(confirmation()).toBeDefined();
        expect(text()).toContain('The result could not be confirmed.');
        expect(text()).not.toContain('Reservation Cancelled!');
        expect(button('Cancel').props.disabled).toBe(true);
        expect(navigation.reload).not.toHaveBeenCalled();
    });
});
