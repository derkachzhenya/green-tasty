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
import type { BookingSearchTable } from '@/types/booking';
import Create from './Create.vue';

const transport = vi.hoisted(() => ({
    post: vi.fn(),
    resume: vi.fn(),
    user: { id: 1, email_verified_at: '2026-10-01' } as {
        id: number;
        email_verified_at: string | null;
    } | null,
}));
type BookingForm = {
    restaurant: string;
    date: string;
    time: string;
    guest_count: number;
    duration_minutes: number;
    restaurant_table_id: number | null;
    processing: boolean;
    errors: Record<string, string>;
    transformData?: (data: unknown) => unknown;
};
let form: BookingForm;
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Head: { render: () => null },
        usePage: () => ({
            props: {
                auth: {
                    get user() {
                        return transport.user;
                    },
                },
            },
        }),
        router: { post: transport.resume },
        useForm(data: BookingForm) {
            const state = reactive({
                ...data,
                processing: false,
                errors: {} as Record<string, string>,
                clearErrors() {
                    state.errors = {};
                },
                setError(field: string, message: string) {
                    state.errors[field] = message;
                },
                transform(callback: (data: unknown) => unknown) {
                    state.transformData = callback;
                },
                post: transport.post,
                transformData: undefined as
                    | ((data: unknown) => unknown)
                    | undefined,
            });
            form = state;
            return state;
        },
    };
});
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
vi.mock('@/components/bookings/BookingModal.vue', async () => {
    const { defineComponent } = await import('vue');
    return {
        default: defineComponent({
            props: ['open', 'title', 'description', 'busy'],
            setup:
                (props, { slots }) =>
                () =>
                    props.open
                        ? h('section', { role: 'dialog' }, [
                              h('h2', props.title),
                              slots.default?.(),
                          ])
                        : null,
        }),
    };
});
const restaurant = {
    id: 11,
    slug: 'location-1',
    name: 'Selected restaurant',
    address: '48 Rustaveli Avenue',
    phone: '+995 32 123 4567',
};
const tables: BookingSearchTable[] = [
    {
        id: 77,
        name: 'Garden table',
        seats: 4,
        restaurant,
        slots: [
            { time: '12:00', available: true },
            { time: '12:30', available: false },
            { time: '14:00', available: true },
        ],
    },
];
const rules = {
    timezone: 'Asia/Tbilisi',
    duration_minutes: 120,
    slot_minutes: 30,
    min_date: '2026-10-23',
    max_date: '2027-01-21',
    default_date: '2026-10-24',
};
let mounted: ReturnType<typeof mountComponent>;
let fetchMock: ReturnType<typeof vi.fn>;
function response(value: unknown = { tables }, status = 200) {
    return new Response(JSON.stringify(value), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
function button(label: string): TestNode {
    const target = findAll(
        mounted.root,
        (node) =>
            node.tag === 'button' &&
            (textContent(node).trim() === label ||
                node.props['aria-label'] === label),
    )[0];
    if (!target) throw new Error(`Missing button: ${label}`);
    return target;
}
const search = async () => {
    await flush();
    await fire(
        findAll(mounted.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
    await flush();
};
const text = () => textContent(mounted.root);
const slot = () => button('Garden table, 48 Rustaveli Avenue, 12:00');
beforeEach(async () => {
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    fetchMock = vi.fn().mockImplementation(() => Promise.resolve(response()));
    vi.stubGlobal('fetch', fetchMock);
    transport.user = { id: 1, email_verified_at: '2026-10-01' };
    transport.post.mockReset().mockImplementation(() => {
        form.processing = true;
    });
    transport.resume.mockReset();
    mounted = mountComponent(Create, {
        restaurant,
        restaurants: [restaurant],
        bookingRules: rules,
    });
    form.date = '2026-10-24';
    form.time = '12:00';
    form.guest_count = 2;
    await flush();
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('database-backed booking search and review', () => {
    it('uses the supplied default photo, actual addresses and real support phone without an initial booking', () => {
        expect(
            findAll(mounted.root, (node) => node.tag === 'img').some((node) =>
                decodeURIComponent(String(node.props.src)).endsWith(
                    'Restaurant Image.png',
                ),
            ),
        ).toBe(true);
        expect(text()).toContain('48 Rustaveli Avenue');
        expect(text()).toContain('+995 32 123 4567');
        expect(text()).not.toContain('+123 445');
        expect(transport.post).not.toHaveBeenCalled();
    });
    it.each([
        ['location-1', '48 Rustaveli Avenue'],
        ['location-2', '14 Baratashvili Street'],
        ['location-3', '9 Abashidze Street'],
    ])(
        'keeps actual restaurant %s for search, review and POST',
        async (slug, address) => {
            const chosen = { ...restaurant, slug, address, id: 90 };
            mounted.setProps({ restaurant: chosen, restaurants: [chosen] });
            await flush();
            fetchMock.mockResolvedValueOnce(
                response({ tables: [{ ...tables[0], restaurant: chosen }] }),
            );
            await search();
            expect(fetchMock.mock.calls[0][0]).toContain(`restaurant=${slug}`);
            const available = button(`Garden table, ${address}, 12:00`);
            await fire(available, 'onClick');
            expect(text()).toContain('Review & Confirm Reservation');
            expect(text()).toContain(address);
            expect(transport.post).not.toHaveBeenCalled();
            await fire(button('Confirm'), 'onClick');
            expect(transport.post.mock.calls[0][0]).toBe(
                `/restaurants/${slug}/book`,
            );
            expect(form.transformData?.(form)).toEqual({
                date: '2026-10-24',
                time: '12:00',
                guest_count: 2,
                duration_minutes: 120,
                restaurant_table_id: 77,
            });
        },
    );
    it('marks availability requests as AJAX so Laravel keeps the booking page as its return URL', async () => {
        await search();
        expect(fetchMock.mock.calls[0][1].headers['X-Requested-With']).toBe(
            'XMLHttpRequest',
        );
    });
    it.each([0, 256, 1.5, Number.NaN])(
        'rejects invalid guest count %s before search',
        async (count) => {
            form.guest_count = count;
            await search();
            expect(fetchMock).not.toHaveBeenCalled();
            expect(text()).toContain(
                'whole number of guests between 1 and 255',
            );
        },
    );
    it('shows disabled conflicts, local start/end times and creates nothing before confirm', async () => {
        await search();
        expect(
            button('Garden table, 48 Rustaveli Avenue, 12:30, unavailable')
                .props.disabled,
        ).toBe(true);
        await fire(slot(), 'onClick');
        expect(text()).toContain('12:00 – 14:00');
        expect(text()).toContain('Asia/Tbilisi');
        expect(text()).not.toContain('Reservation Confirmed');
        expect(transport.post).not.toHaveBeenCalled();
    });
    it('aggregates All Locations and removes the time filter via the empty-state action', async () => {
        form.restaurant = '';
        fetchMock.mockResolvedValueOnce(response({ tables: [] }));
        await search();
        expect(text()).toContain('No Tables Available');
        await fire(button('Show all slots on Oct 24, 2026'), 'onClick');
        await flush();
        expect(fetchMock.mock.calls[1][0]).toContain('time=all');
        expect(form.time).toBe('all');
        expect(text()).toContain('Garden table');
    });
    it('retains inputs and recovers after unavailable network and rate limits', async () => {
        fetchMock
            .mockRejectedValueOnce(new TypeError('Offline'))
            .mockResolvedValueOnce(response({}, 429));
        await search();
        expect(text()).toContain('We couldn’t connect');
        expect(form.date).toBe('2026-10-24');
        await search();
        expect(text()).toContain('Too many searches');
        await search();
        expect(text()).toContain('Garden table');
    });
    it('shows field validation and clears stale results when inputs change', async () => {
        fetchMock.mockResolvedValueOnce(
            response(
                {
                    errors: {
                        time: ['Choose a future slot.'],
                        restaurant: ['Choose a location.'],
                    },
                },
                422,
            ),
        );
        await search();
        expect(text()).toContain('Choose a future slot.');
        expect(
            findAll(mounted.root, (node) => node.props.id === 'booking-time')[0]
                .props['aria-invalid'],
        ).toBe(true);
        form.time = '14:00';
        await flush();
        expect(text()).not.toContain('Choose a future slot.');
        await search();
        await fire(slot(), 'onClick');
        form.guest_count = 3;
        await flush();
        expect(text()).not.toContain('Garden table');
        expect(text()).not.toContain('Review & Confirm Reservation');
    });
    it('aborts and ignores late results after a filter change', async () => {
        let resolve!: (response: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((done) => {
                    resolve = done;
                }),
        );
        const pending = search();
        await flush();
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        form.guest_count = 6;
        await flush();
        expect(signal.aborted).toBe(true);
        resolve(response());
        await pending;
        await flush();
        expect(text()).not.toContain('Garden table');
    });
    it('blocks duplicate confirmation and reports uncertain responses without claiming success', async () => {
        await search();
        await fire(slot(), 'onClick');
        const confirm = button('Confirm');
        await fire(confirm, 'onClick');
        await fire(confirm, 'onClick');
        expect(transport.post).toHaveBeenCalledTimes(1);
        const callbacks = transport.post.mock.calls[0][1];
        expect(callbacks.onNetworkError()).toBe(false);
        form.processing = false;
        await flush();
        expect(text()).toContain('Check My Reservations');
        expect(form.restaurant_table_id).toBeNull();
        expect(text()).not.toContain('Reservation Confirmed');
    });
    it.each([null, { id: 1, email_verified_at: null }])(
        'continues through existing auth for guest/unverified state %j',
        async (user) => {
            transport.user = user;
            await search();
            await fire(slot(), 'onClick');
            await fire(button('Sign In to Confirm'), 'onClick');
            expect(transport.resume).toHaveBeenCalledWith(
                '/book/continue',
                {
                    restaurant: 'location-1',
                    date: '2026-10-24',
                    time: '12:00',
                    guest_count: 2,
                    duration_minutes: 120,
                    table: 77,
                },
                expect.any(Object),
            );
            expect(transport.post).not.toHaveBeenCalled();
        },
    );
    it('blocks duplicate guest handoffs and reports failures without making a booking', async () => {
        transport.user = null;
        await search();
        await fire(slot(), 'onClick');
        const confirm = button('Sign In to Confirm');
        await fire(confirm, 'onClick');
        await fire(confirm, 'onClick');
        expect(transport.resume).toHaveBeenCalledTimes(1);
        const callbacks = transport.resume.mock.calls[0][2];
        callbacks.onError();
        callbacks.onFinish();
        await flush();
        expect(text()).toContain('selection is no longer valid');
        expect(text()).not.toContain('Review & Confirm Reservation');
        expect(transport.post).not.toHaveBeenCalled();
    });
    it('restores a selection only after a new server availability check', async () => {
        mounted.unmount();
        mounted = mountComponent(Create, {
            restaurant: null,
            restaurants: [restaurant],
            bookingRules: rules,
            initialSelection: {
                restaurant: restaurant.slug,
                date: '2026-10-24',
                time: '12:00',
                guest_count: '2',
                table: '77',
            },
        });
        await flush();
        expect(fetchMock).toHaveBeenCalled();
        expect(text()).toContain('Review & Confirm Reservation');
        expect(transport.post).not.toHaveBeenCalled();
    });
    it('edits a draft without persistence, and rechecks the selected table', async () => {
        await search();
        await fire(slot(), 'onClick');
        await fire(button('Edit'), 'onClick');
        expect(text()).toContain('Edit Reservation');
        const duration = findAll(
            mounted.root,
            (node) => node.props.id === 'edit-duration',
        )[0];
        expect(duration.props.disabled).toBe(false);
        await fire(duration, 'onChange', { target: { value: '180' } });
        const editForm = findAll(
            mounted.root,
            (node) => node.tag === 'form',
        )[1];
        await fire(editForm, 'onSubmit');
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(text()).toContain('Review & Confirm Reservation');
        expect(text()).toContain('12:00 – 15:00');
        expect(fetchMock.mock.calls[1][0]).toContain('duration_minutes=180');
        expect(transport.post).not.toHaveBeenCalled();
        await fire(button('Confirm'), 'onClick');
        expect(form.transformData?.(form)).toMatchObject({
            duration_minutes: 180,
        });
    });
});
