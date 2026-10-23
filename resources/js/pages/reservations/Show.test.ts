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
import type { SubmittedGroupOrder } from '@/types/reservation';
import Show from './Show.vue';

const navigation = vi.hoisted(() => ({
    reload: vi.fn(),
    rescue: (_value: boolean) => {},
}));
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, ref } = await import('vue');
    const rescued = ref(false);
    navigation.rescue = (value) => {
        rescued.value = value;
    };
    return {
        router: { reload: navigation.reload },
        Head: defineComponent({ setup: () => () => null }),
        Link: defineComponent({
            setup:
                (_props, { slots }) =>
                () =>
                    h('a', slots.default?.()),
        }),
        Deferred: defineComponent({
            setup:
                (_props, { slots }) =>
                () =>
                    rescued.value ? slots.rescue?.() : slots.default?.(),
        }),
    };
});
vi.mock('@/components/reservations/GuestInvitationManager.vue', () => ({
    default: { render: () => null },
}));
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
    const value = buttons(label)[0];
    if (!value) throw new Error(`Missing button: ${label}`);
    return value;
}
function response(value: SubmittedGroupOrder) {
    return new Response(JSON.stringify(value), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}
async function submit() {
    await fire(button('Submit Group Pre-Order'), 'onClick');
    await fire(
        findAll(mounted.root, (node) => node.tag === 'form')[0],
        'onSubmit',
    );
    await flush();
}
function reloadCallbacks() {
    return navigation.reload.mock.calls.at(-1)![0] as {
        onSuccess: (page: unknown) => void;
        onFinish: () => void;
        onNetworkError: () => boolean;
        onHttpException: () => boolean;
    };
}
beforeEach(() => {
    vi.stubGlobal('window', new EventTarget());
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=csrf',
        getElementById: vi.fn(),
    });
    navigation.rescue(false);
    navigation.reload
        .mockReset()
        .mockImplementation((options) => options.onStart?.());
    const result = submissionResult();
    result.total_quantity = 4;
    result.items[0].quantity = 4;
    result.items[0].subtotal = '45.50';
    result.grand_total = '45.50';
    result.guests[1].total_quantity = 3;
    result.guests[1].subtotal = '33.00';
    result.guests[1].items[0].quantity = 3;
    result.guests[1].items[0].subtotal = '33.00';
    fetchMock = vi
        .fn()
        .mockImplementation(() => Promise.resolve(response(result)));
    vi.stubGlobal('fetch', fetchMock);
    mounted = mountComponent(Show, {
        reservation: {
            id: 1,
            code: 'GT-TEST',
            status: 'confirmed',
            guest_count: 2,
            restaurant: {
                name: 'Green Tasty',
                slug: 'green-tasty',
                address: 'Test',
            },
            table: { name: 'Table 1', seats: 4 },
            date: '2026-10-24',
            time: '12:00',
            end_date: '2026-10-24',
            end_time: '14:00',
            timezone: 'Asia/Tbilisi',
        },
        groupOrder: groupOrder(),
        canSubmitPreOrder: true,
        canManageInvitations: true,
    });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('dashboard group submission integration', () => {
    it('shows the consolidated and individual saved prices before confirmation, then uses the server result and refreshes', async () => {
        expect(text()).toContain('All selected dishes');
        expect(text()).toContain('Selections by participant');
        expect(text()).toContain('12.50 · 11.00');
        expect(text()).toContain('34.50');
        expect(text()).toContain('Alice');
        await submit();
        expect(text()).toContain('Guest selections are now locked.');
        expect(text()).toContain('45.50');
        expect(text()).toContain('23 Oct 2026, 12:00');
        expect(text()).toContain('(Asia/Tbilisi)');
        expect(text()).not.toContain('Draft —');
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
        expect(navigation.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['groupOrder', 'reservation', 'canSubmitPreOrder'],
            }),
        );
    });
    it.each(['onNetworkError', 'onHttpException'] as const)(
        'keeps the confirmed submitted view when refresh fails through %s',
        async (callback) => {
            await submit();
            expect(reloadCallbacks()[callback]()).toBe(false);
            reloadCallbacks().onFinish();
            await flush();
            expect(text()).toContain('couldn’t refresh');
            expect(text()).toContain('45.50');
            expect(text()).toContain('Selections are locked.');
            expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
        },
    );
    it('keeps the submitted snapshot visible when deferred refresh is rescued', async () => {
        await submit();
        navigation.rescue(true);
        mounted.setProps({ groupOrder: undefined });
        reloadCallbacks().onSuccess({
            rescuedProps: ['groupOrder'],
            props: {},
        });
        reloadCallbacks().onFinish();
        await flush();
        expect(text()).toContain('45.50');
        expect(text()).toContain('Guest selections are now locked.');
        expect(text()).not.toContain('couldn’t load your participants');
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
    });
    it('does not unlock or regress to an older draft snapshot returned by a stale refresh', async () => {
        await submit();
        mounted.setProps({ groupOrder: groupOrder() });
        reloadCallbacks().onSuccess({
            rescuedProps: [],
            props: { groupOrder: groupOrder() },
        });
        reloadCallbacks().onFinish();
        await flush();
        expect(text()).toContain('45.50');
        expect(text()).not.toContain('Draft —');
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
    });
    it('requires a successful refresh and new confirmation after an uncertain submission', async () => {
        fetchMock.mockRejectedValueOnce(new Error('offline'));
        await submit();
        expect(text()).toContain('The order may already be submitted.');
        await fire(button('Refresh order status'), 'onClick');
        mounted.setProps({ groupOrder: groupOrder() });
        reloadCallbacks().onSuccess({
            rescuedProps: [],
            props: { groupOrder: groupOrder() },
        });
        reloadCallbacks().onFinish();
        await flush();
        expect(button('Submit Group Pre-Order').props.disabled).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(
            findAll(mounted.root, (node) => node.props.role === 'dialog'),
        ).toHaveLength(0);
    });
    it('does not expose submission controls without the backend ability', async () => {
        mounted.setProps({ canSubmitPreOrder: false });
        await flush();
        expect(text()).toContain('All selected dishes');
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it('does not render submit controls when the deferred summary is unavailable', async () => {
        navigation.rescue(true);
        mounted.setProps({ groupOrder: undefined });
        await flush();
        expect(text()).toContain('couldn’t load your participants');
        expect(buttons('Submit Group Pre-Order')).toHaveLength(0);
    });
});
