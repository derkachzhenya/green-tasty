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
import Create from './Create.vue';

const transport = vi.hoisted(() => ({ post: vi.fn() }));
type BookingForm = {
    date: string;
    time: string;
    guest_count: number;
    restaurant_table_id: number | null;
    processing: boolean;
    errors: Record<string, string>;
};
let form: BookingForm;
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    return {
        Head: { render: () => null },
        Link: {
            setup:
                (
                    _props: unknown,
                    { slots }: { slots: { default: () => VNodeChild } },
                ) =>
                () =>
                    h('a', {}, [slots.default()]),
        },
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
                post: transport.post,
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

let mounted: ReturnType<typeof mountComponent>;
let fetchMock: ReturnType<typeof vi.fn>;
const tables = [{ id: 7, name: 'Garden table', seats: 4 }];
function response(value: unknown = { tables }, status = 200) {
    return new Response(JSON.stringify(value), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
function button(label: string): TestNode {
    const target = findAll(
        mounted.root,
        (node) => node.tag === 'button' && textContent(node) === label,
    )[0];
    if (!target) throw new Error(`Missing button: ${label}`);
    return target;
}
function search() {
    return fire(
        findAll(mounted.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
}
function text() {
    return textContent(mounted.root);
}
beforeEach(() => {
    // Vue's native v-model directive checks these constructors when input values change.
    // Focus and real DOM behavior are verified separately in the browser journey.
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    fetchMock = vi.fn().mockResolvedValue(response());
    vi.stubGlobal('fetch', fetchMock);
    transport.post.mockReset().mockImplementation(() => {
        form.processing = true;
    });
    mounted = mountComponent(Create, {
        restaurant: {
            id: 1,
            slug: 'garden',
            name: 'Green Tasty',
            address: 'Test',
        },
        bookingRules: {
            timezone: 'Asia/Tbilisi',
            duration_minutes: 120,
            slot_minutes: 30,
            min_date: '2026-10-23',
            max_date: '2027-01-21',
            default_date: '2026-10-24',
        },
    });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('booking availability and recovery', () => {
    it('checks availability without claiming a persisted booking and requires a table choice', async () => {
        expect(text()).toContain('Find your table');
        await search();
        expect(text()).toContain('Garden table');
        expect(button('Confirm reservation').props.disabled).toBe(true);
        expect(transport.post).not.toHaveBeenCalled();
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toContain('/restaurants/garden/availability?');
        expect(url).not.toContain('restaurant_table_id');
        expect(options.credentials).toBe('same-origin');
    });
    it('retains input and recovers after a network failure, including an empty availability result', async () => {
        fetchMock
            .mockRejectedValueOnce(new TypeError('Offline'))
            .mockResolvedValueOnce(response({ tables: [] }));
        await search();
        expect(text()).toContain('We couldn’t connect');
        expect(form.date).toBe('2026-10-24');
        await search();
        expect(text()).toContain('No tables available');
        await search();
        expect(text()).toContain('Garden table');
        expect(text()).not.toContain('We couldn’t connect');
    });
    it('shows server validation on the associated field and clears it on correction', async () => {
        fetchMock.mockResolvedValueOnce(
            response({ errors: { time: ['Choose a future slot.'] } }, 422),
        );
        await search();
        expect(text()).toContain('Choose a future slot.');
        expect(
            findAll(mounted.root, (node) => node.props.id === 'booking-time')[0]
                .props['aria-invalid'],
        ).toBe(true);
        form.time = '13:00';
        await flush();
        expect(text()).not.toContain('Choose a future slot.');
        await search();
        expect(text()).toContain('Garden table');
    });
    it('ignores a late availability response after the booking inputs change', async () => {
        let resolve!: (value: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((done) => {
                    resolve = done;
                }),
        );
        const pending = search();
        await flush();
        expect(text()).toContain('Checking live availability');
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        form.guest_count = 6;
        await flush();
        expect(signal.aborted).toBe(true);
        resolve(response());
        await pending;
        expect(text()).not.toContain('Garden table');
        expect(form.restaurant_table_id).toBeNull();
    });
    it('prevents duplicate confirmation while Inertia persists the reservation', async () => {
        await search();
        form.restaurant_table_id = 7;
        await flush();
        const confirm = button('Confirm reservation');
        await fire(confirm, 'onClick');
        await fire(confirm, 'onClick');
        expect(transport.post).toHaveBeenCalledTimes(1);
        expect(transport.post.mock.calls[0][0]).toBe(
            '/restaurants/garden/book',
        );
        expect(button('Confirming reservation…').props.disabled).toBe(true);
        expect(text()).not.toContain('Reservation Confirmed');
    });
    it('requires checking availability again after an unconfirmed network response', async () => {
        await search();
        form.restaurant_table_id = 7;
        await flush();
        await fire(button('Confirm reservation'), 'onClick');
        const callbacks = transport.post.mock.calls[0][1] as {
            onNetworkError: () => boolean;
        };
        expect(callbacks.onNetworkError()).toBe(false);
        form.processing = false;
        await flush();
        expect(text()).toContain('We couldn’t confirm your booking');
        expect(text()).not.toContain('Garden table');
        expect(form.restaurant_table_id).toBeNull();
    });
});
