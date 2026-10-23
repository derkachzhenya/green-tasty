import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { h, type VNodeChild } from 'vue';
import { groupOrder, submissionResult } from '@/test-support/groupOrder';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
    type TestNode,
} from '@/test-support/renderComponent';
import GroupPreOrderSubmission from './GroupPreOrderSubmission.vue';

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
vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h } = await import('vue');
    const box = (tag: string) =>
        defineComponent({
            setup:
                (_props, { slots }) =>
                () =>
                    h(tag, slots.default?.()),
        });
    return {
        Dialog: defineComponent({
            props: { open: Boolean },
            setup:
                (props, { slots }) =>
                () =>
                    props.open
                        ? h('section', { role: 'dialog' }, slots.default?.())
                        : null,
        }),
        DialogContent: box('div'),
        DialogHeader: box('header'),
        DialogTitle: box('h2'),
        DialogDescription: box('p'),
        DialogFooter: box('footer'),
    };
});
let mounted: ReturnType<typeof mountComponent>;
let fetchMock: ReturnType<typeof vi.fn>;
let submitted: ReturnType<typeof vi.fn>;
let refresh: ReturnType<typeof vi.fn>;
let pageWindow: EventTarget;
let elementById: ReturnType<typeof vi.fn>;
function response(value: unknown = submissionResult(), status = 200) {
    return new Response(JSON.stringify(value), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
function text() {
    return textContent(mounted.root);
}
function buttons(label: string) {
    return findAll(
        mounted.root,
        (node) => node.tag === 'button' && textContent(node) === label,
    );
}
function button(label: string): TestNode {
    const found = buttons(label)[0];
    if (!found) throw new Error(`Missing button: ${label}`);
    return found;
}
function dialog() {
    return findAll(mounted.root, (node) => node.props.role === 'dialog')[0];
}
async function begin() {
    await fire(button('Submit Group Pre-Order'), 'onClick');
}
async function confirm() {
    await fire(
        findAll(mounted.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
    await flush();
}
async function startConfirmation() {
    void fire(
        findAll(mounted.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
    await flush();
}
beforeEach(() => {
    elementById = vi.fn();
    pageWindow = new EventTarget();
    vi.stubGlobal('window', pageWindow);
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=encrypted%2Bcookie%3D',
        getElementById: elementById,
    });
    fetchMock = vi.fn().mockImplementation(() => Promise.resolve(response()));
    vi.stubGlobal('fetch', fetchMock);
    submitted = vi.fn();
    refresh = vi.fn();
    mounted = mountComponent(GroupPreOrderSubmission, {
        reservationId: 1,
        reservationStatus: 'confirmed',
        order: groupOrder(),
        canSubmit: true,
        refreshing: false,
        refreshVersion: 0,
        onSubmitted: submitted,
        onRefresh: refresh,
    });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('owner group submission', () => {
    it('focuses the new summary heading after success instead of an opener being removed', async () => {
        const opener = button('Submit Group Pre-Order');
        const oldFocus = vi.fn();
        Object.assign(opener, { isConnected: true, focus: oldFocus });
        const headingFocus = vi.fn();
        elementById.mockReturnValue({
            focus: headingFocus,
        } as unknown as HTMLElement);
        await begin();
        const content = findAll(dialog(), (node) =>
            Boolean(node.props.onCloseAutoFocus),
        )[0];
        await confirm();
        const preventDefault = vi.fn();
        await fire(content, 'onCloseAutoFocus', { preventDefault });
        expect(preventDefault).toHaveBeenCalled();
        expect(oldFocus).not.toHaveBeenCalled();
        expect(headingFocus).toHaveBeenCalledOnce();
    });
    it('retains only the supported submission fields and historical prices', async () => {
        const result = submissionResult();
        fetchMock.mockResolvedValueOnce(
            response({
                ...result,
                invitation_token: 'private-hash',
                guests: result.guests.map((guest) => ({
                    ...guest,
                    email: 'private@example.test',
                })),
            }),
        );
        await begin();
        await confirm();
        expect(submitted).toHaveBeenCalledWith(result);
        expect(JSON.stringify(submitted.mock.calls)).not.toContain('private');
    });
    it('requires an explicit second action and explains the locked guest selections', async () => {
        await begin();
        expect(dialog()).toBeDefined();
        expect(text()).toContain(
            'Guests will no longer be able to add, change or remove dishes.',
        );
        expect(text()).toContain('34.50');
        expect(text()).not.toContain('$');
        expect(fetchMock).not.toHaveBeenCalled();
        await fire(button('Keep reviewing'), 'onClick');
        expect(dialog()).toBeUndefined();
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it('submits an empty request using session authentication and the encrypted CSRF cookie', async () => {
        await begin();
        await confirm();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/reservations/1/pre-order/submit');
        expect(options).toMatchObject({
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': 'encrypted+cookie=',
            },
        });
        expect(options.body).toBeUndefined();
        expect(submitted).toHaveBeenCalledWith(submissionResult());
        expect(dialog()).toBeUndefined();
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
    });
    it('allows nonempty orders containing free dishes', async () => {
        const order = groupOrder();
        order.summary.grand_total = '0.00';
        mounted.setProps({ order });
        await flush();
        expect(button('Submit Group Pre-Order').props.disabled).toBe(false);
        await begin();
        expect(text()).toContain('0.00');
    });
    it('prevents duplicate requests, cancellation and dismissing the dialog during submission', async () => {
        fetchMock.mockReturnValue(new Promise(() => {}));
        await begin();
        await startConfirmation();
        expect(button('Submitting…').props.disabled).toBe(true);
        expect(button('Keep reviewing').props.disabled).toBe(true);
        await fire(button('Keep reviewing'), 'onClick');
        expect(dialog()).toBeDefined();
        await confirm();
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const content = findAll(dialog(), (node) =>
            Boolean(node.props.onEscapeKeyDown),
        )[0];
        const preventDefault = vi.fn();
        await fire(content, 'onEscapeKeyDown', { preventDefault });
        expect(preventDefault).toHaveBeenCalled();
    });
    it('does not offer submission without the server-provided policy ability', async () => {
        mounted.setProps({ canSubmit: false });
        await flush();
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it.each(['cancelled', 'completed'])(
        'blocks a %s reservation',
        async (reservationStatus) => {
            mounted.setProps({ reservationStatus });
            await flush();
            expect(button('Submit Group Pre-Order').props.disabled).toBe(true);
            await begin();
            expect(dialog()).toBeUndefined();
        },
    );
    it('blocks empty or not-started orders and explains how to proceed', async () => {
        const order = groupOrder();
        order.summary.items = [];
        order.summary.total_quantity = 0;
        order.summary.grand_total = '0.00';
        order.status = null;
        mounted.setProps({ order });
        await flush();
        expect(button('Submit Group Pre-Order').props.disabled).toBe(true);
        expect(text()).toContain('Add at least one saved dish');
        await begin();
        expect(dialog()).toBeUndefined();
    });
    it.each([
        ['submitted', '2026-10-23T08:00:00Z'],
        ['submitted', null],
        ['draft', '2026-10-23T08:00:00Z'],
        ['cancelled', null],
    ])(
        'keeps order status %s and timestamp %s read-only',
        async (status, submitted_at) => {
            mounted.setProps({
                order: { ...groupOrder(), status, submitted_at },
            });
            await flush();
            expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
            expect(dialog()).toBeUndefined();
        },
    );
    it('blocks confirmation while the dashboard summary is refreshing', async () => {
        mounted.setProps({ refreshing: true });
        await flush();
        await begin();
        expect(dialog()).toBeUndefined();
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it.each([401, 403, 419, 404])(
        'handles HTTP %i without allowing a blind retry',
        async (status) => {
            fetchMock.mockResolvedValueOnce(response({}, status));
            await begin();
            await confirm();
            expect(text()).toContain(
                status === 404
                    ? 'reservation is no longer available'
                    : 'session or permission has changed',
            );
            expect(button('Submit Group Pre-Order').props.disabled).toBe(true);
            expect(dialog()).toBeUndefined();
            expect(submitted).not.toHaveBeenCalled();
            mounted.setProps({ refreshVersion: 1 });
            await flush();
            expect(button('Submit Group Pre-Order').props.disabled).toBe(true);
        },
    );
    it('displays validation errors, then requires refresh and renewed confirmation', async () => {
        fetchMock.mockResolvedValueOnce(
            response(
                { errors: { pre_order: ['The group pre-order is empty.'] } },
                422,
            ),
        );
        await begin();
        await confirm();
        expect(text()).toContain('The group pre-order is empty.');
        expect(button('Submit Group Pre-Order').props.disabled).toBe(true);
        await fire(button('Refresh order status'), 'onClick');
        expect(refresh).toHaveBeenCalledOnce();
        mounted.setProps({ refreshVersion: 1, order: groupOrder() });
        await flush();
        expect(dialog()).toBeUndefined();
        expect(button('Submit Group Pre-Order').props.disabled).toBe(false);
        await begin();
        await confirm();
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
    it.each([409, 500])(
        'refreshes after HTTP %i before permitting another submission',
        async (status) => {
            fetchMock.mockResolvedValueOnce(response({}, status));
            await begin();
            await confirm();
            expect(button('Submit Group Pre-Order').props.disabled).toBe(true);
            expect(buttons('Refresh order status')).toHaveLength(1);
            const result = submissionResult();
            mounted.setProps({
                order: {
                    ...groupOrder(),
                    status: result.status,
                    submitted_at: result.submitted_at,
                },
                refreshVersion: 1,
            });
            await flush();
            expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
        },
    );
    it('handles a network failure as uncertain, without claiming success or sending duplicate requests', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await begin();
        await confirm();
        expect(text()).toContain('The order may already be submitted.');
        expect(submitted).not.toHaveBeenCalled();
        await begin();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
    it('handles rate limiting and allows an explicit retry', async () => {
        fetchMock.mockResolvedValueOnce(response({}, 429));
        await begin();
        await confirm();
        expect(text()).toContain('Wait a minute');
        expect(dialog()).toBeDefined();
        await confirm();
        expect(submitted).toHaveBeenCalledOnce();
    });
    it.each([
        { status: 'draft' },
        { reservation_id: 2 },
        { submitted_at: null },
        { grand_total: 34.5 },
    ])(
        'rejects malformed or mismatched success metadata %j',
        async (change) => {
            fetchMock.mockResolvedValueOnce(
                response({ ...submissionResult(), ...change }),
            );
            await begin();
            await confirm();
            expect(submitted).not.toHaveBeenCalled();
            expect(text()).toContain('The result could not be confirmed.');
        },
    );
    it('requires renewed review if saved selections change while confirmation is open', async () => {
        await begin();
        mounted.setProps({ order: groupOrder() });
        await flush();
        expect(dialog()).toBeUndefined();
        expect(text()).toContain('Saved selections changed.');
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it('aborts and ignores a late response when the reservation changes', async () => {
        let finish!: (value: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        );
        await begin();
        await startConfirmation();
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        const order = groupOrder();
        order.summary.reservation_id = 2;
        mounted.setProps({ reservationId: 2, order });
        await flush();
        finish(response());
        await flush();
        expect(signal.aborted).toBe(true);
        expect(submitted).not.toHaveBeenCalled();
    });
    it('aborts in-flight submissions on unmount and ignores their late responses', async () => {
        let finish!: (value: Response) => void;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        );
        await begin();
        await startConfirmation();
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        mounted.unmount();
        finish(response());
        await flush();
        expect(signal.aborted).toBe(true);
        expect(submitted).not.toHaveBeenCalled();
    });
    it('refreshes the server status after a back-forward cache restore', async () => {
        await begin();
        pageWindow.dispatchEvent(new Event('pagehide'));
        await flush();
        expect(dialog()).toBeUndefined();
        pageWindow.dispatchEvent(
            Object.assign(new Event('pageshow'), { persisted: true }),
        );
        expect(refresh).toHaveBeenCalledOnce();
    });
});
