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
import Confirmation from './Confirmation.vue';

const transport = vi.hoisted(() => ({
    patch: vi.fn(),
    before: undefined as
        | undefined
        | ((event: { detail: { visit: { url: URL } } }) => void),
}));
let form: {
    time: string;
    guest_count: number;
    duration_minutes: number;
    processing: boolean;
    errors: Record<string, string>;
};
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, reactive } = await import('vue');
    return {
        Head: { render: () => null },
        router: {
            on: (_event: string, callback: typeof transport.before) => {
                transport.before = callback;
                return () => {
                    transport.before = undefined;
                };
            },
        },
        Link: defineComponent({
            props: ['href'],
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'a',
                        {
                            href:
                                typeof props.href === 'string'
                                    ? props.href
                                    : props.href.url,
                        },
                        slots.default?.(),
                    ),
        }),
        useForm(data: typeof form) {
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
                patch: transport.patch,
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
const reservation = {
    id: 29,
    code: 'GT-REAL',
    status: 'confirmed',
    guest_count: 2,
    restaurant: {
        name: 'Actual restaurant',
        address: '14 Baratashvili Street',
        slug: 'location-2',
    },
    table: { name: 'Patio', seats: 4 },
    date: '2026-10-24',
    time: '23:30',
    end_date: '2026-10-25',
    end_time: '01:30',
    timezone: 'Asia/Tbilisi',
};
const issued = {
    guest_id: 38,
    name: 'Alice',
    expires_at: '2026-10-30T12:00:00Z',
    invitation_url: `http://localhost/invitations/${'a'.repeat(64)}`,
};
let mounted: ReturnType<typeof mountComponent>;
let fetchMock: ReturnType<typeof vi.fn>;
let copy: ReturnType<typeof vi.fn>;
let pageWindow: EventTarget;
const text = () => textContent(mounted.root);
function button(label: string): TestNode {
    const found = findAll(
        mounted.root,
        (node) => node.tag === 'button' && textContent(node).trim() === label,
    )[0];
    if (!found) throw new Error(`Missing button: ${label}`);
    return found;
}
async function generate(): Promise<void> {
    const input = findAll(
        mounted.root,
        (node) => node.props.id === 'invitation-name',
    )[0];
    await fire(input, 'onUpdate:modelValue', 'Alice');
    await fire(
        findAll(mounted.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
    await flush();
}
beforeEach(() => {
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    pageWindow = Object.assign(new EventTarget(), {
        location: {
            origin: 'http://localhost',
            pathname: '/reservations/29/confirmation',
        },
    });
    vi.stubGlobal('window', pageWindow);
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=encrypted%2Bcookie',
        activeElement: null,
    });
    copy = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText: copy } });
    fetchMock = vi.fn().mockImplementation(() =>
        Promise.resolve(
            new Response(JSON.stringify(issued), {
                status: 201,
                headers: { 'Content-Type': 'application/json' },
            }),
        ),
    );
    vi.stubGlobal('fetch', fetchMock);
    transport.patch.mockReset().mockImplementation(() => {
        form.processing = true;
    });
    mounted = mountComponent(Confirmation, { reservation, canEdit: true });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});
describe('persisted booking confirmation', () => {
    it('renders saved identities and cross-midnight interval with real navigation and no invitation side effects', () => {
        expect(text()).toContain('Reservation Confirmed');
        expect(text()).toContain('14 Baratashvili Street');
        expect(text()).toContain('Patio');
        expect(text()).toContain('23:30 – 01:30 (Oct 25, 2026)');
        const links = findAll(mounted.root, (node) => node.tag === 'a');
        expect(
            links.some(
                (node) =>
                    textContent(node).trim() === 'View Reservation' &&
                    node.props.href === '/reservations/29',
            ),
        ).toBe(true);
        expect(
            links.some(
                (node) =>
                    textContent(node).trim() === 'Make Pre-Order' &&
                    node.props.href === '/reservations/29/owner-pre-order',
            ),
        ).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it('creates and copies a real per-guest invitation with CSRF and only after explicit submission', async () => {
        await generate();
        expect(fetchMock).toHaveBeenCalledWith(
            '/reservations/29/invitations',
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                headers: expect.objectContaining({
                    'X-XSRF-TOKEN': 'encrypted+cookie',
                }),
                body: JSON.stringify({ name: 'Alice' }),
            }),
        );
        await fire(button('Copy Link'), 'onClick');
        expect(copy).toHaveBeenCalledWith(issued.invitation_url);
        expect(text()).toContain('Link copied!');
    });
    it('clears the raw link on page lifecycle events and never places it in props or URLs', async () => {
        await generate();
        pageWindow.dispatchEvent(new Event('pagehide'));
        await flush();
        expect(
            findAll(
                mounted.root,
                (node) => node.props.value === issued.invitation_url,
            ),
        ).toHaveLength(0);
        expect(
            findAll(mounted.root, (node) => node.tag === 'a').some((node) =>
                String(node.props.href).includes('invitations'),
            ),
        ).toBe(false);
    });
    it('requires a dashboard refresh after uncertain invitation delivery instead of creating duplicate guests', async () => {
        fetchMock.mockRejectedValueOnce(new TypeError('Offline'));
        await generate();
        expect(text()).toContain('result could not be confirmed');
        expect(button('Create Invite Link').props.disabled).toBe(true);
        await generate();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
    it('saves only supported edits with the real reservation id and blocks repeat saves', async () => {
        await fire(button('Edit Reservation'), 'onClick');
        form.time = '22:00';
        form.guest_count = 3;
        const duration = findAll(
            mounted.root,
            (node) => node.props.id === 'edit-duration',
        )[0];
        await fire(duration, 'onChange', { target: { value: '180' } });
        const editForm = findAll(
            mounted.root,
            (node) => node.tag === 'form',
        )[0];
        await fire(editForm, 'onSubmit');
        await fire(editForm, 'onSubmit');
        expect(form.duration_minutes).toBe(180);
        expect(transport.patch).toHaveBeenCalledTimes(1);
        expect(transport.patch.mock.calls[0][0]).toBe(
            '/reservations/29/booking',
        );
        expect(text()).toContain('Saving changes…');
        expect(text()).toContain('1.5 hours');
    });
    it('keeps edit errors and requires reload after an uncertain result', async () => {
        await fire(button('Edit Reservation'), 'onClick');
        await fire(
            findAll(mounted.root, (node) => node.tag === 'form')[0],
            'onSubmit',
        );
        transport.patch.mock.calls[0][1].onNetworkError();
        form.processing = false;
        await flush();
        expect(text()).toContain('Reload the saved reservation');
        expect(button('Save Changes').props.disabled).toBe(true);
    });
    it('does not clear uncertain-edit protection when reopening the edit form', async () => {
        await fire(button('Edit Reservation'), 'onClick');
        await fire(
            findAll(mounted.root, (node) => node.tag === 'form')[0],
            'onSubmit',
        );
        transport.patch.mock.calls[0][1].onNetworkError();
        form.processing = false;
        await flush();
        await fire(button('Edit Reservation'), 'onClick');
        expect(text()).toContain('Reload the saved reservation');
        await fire(
            findAll(mounted.root, (node) => node.tag === 'form')[0],
            'onSubmit',
        );
        expect(transport.patch).toHaveBeenCalledTimes(1);
    });
    it.each([0, 5, 1.5])(
        'rejects edit guest count %s outside the real table capacity',
        async (count) => {
            await fire(button('Edit Reservation'), 'onClick');
            form.guest_count = count;
            await fire(
                findAll(mounted.root, (node) => node.tag === 'form')[0],
                'onSubmit',
            );
            expect(transport.patch).not.toHaveBeenCalled();
            expect(text()).toContain('whole number of guests between 1 and 4');
        },
    );
    it('permits another edit only after receiving a refreshed saved reservation', async () => {
        await fire(button('Edit Reservation'), 'onClick');
        await fire(
            findAll(mounted.root, (node) => node.tag === 'form')[0],
            'onSubmit',
        );
        transport.patch.mock.calls[0][1].onNetworkError();
        form.processing = false;
        await flush();
        mounted.setProps({
            reservation: { ...reservation, time: '22:00', end_time: '00:00' },
        });
        await flush();
        await fire(button('Edit Reservation'), 'onClick');
        expect(text()).not.toContain('Reload the saved reservation');
        expect(form.time).toBe('22:00');
        await fire(
            findAll(mounted.root, (node) => node.tag === 'form')[0],
            'onSubmit',
        );
        expect(transport.patch).toHaveBeenCalledTimes(2);
    });
    it('does not offer invitations or editing for a terminal reservation', async () => {
        mounted.setProps({
            reservation: { ...reservation, status: 'completed' },
            canEdit: false,
        });
        await flush();
        expect(text()).not.toContain('Create Invite Link');
        expect(text()).not.toContain('Edit Reservation');
        expect(text()).toContain('Reservation details');
        expect(text()).not.toContain('Make Pre-Order');
        expect(
            findAll(mounted.root, (node) => node.tag === 'a').some(
                (node) =>
                    textContent(node).trim() === 'View Pre-Order' &&
                    node.props.href === '/reservations/29#group-pre-order',
            ),
        ).toBe(true);
    });
});
