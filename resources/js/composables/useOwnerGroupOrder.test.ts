import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import { mountComponent, flush } from '@/test-support/renderComponent';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import { useOwnerGroupOrder } from './useOwnerGroupOrder';
import type { OwnerGroupOrder } from '@/types/owner-group-order';
vi.mock('@/lib/sessionJson', async (original) => ({
    ...(await original<typeof import('@/lib/sessionJson')>()),
    sessionJson: vi.fn(),
}));
const request = vi.mocked(sessionJson);
let api: ReturnType<typeof useOwnerGroupOrder>;
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
        participants: [],
        summary: {
            reservation_id: 7,
            pre_order_id: 1,
            items: [],
            guests: [],
            total_quantity: 3,
            grand_total: '44.00',
        },
    };
}
beforeEach(async () => {
    vi.stubGlobal('window', {
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    });
    request.mockReset().mockResolvedValue(fixture());
    changed.mockReset();
    mounted = mountComponent(
        defineComponent({
            setup() {
                api = useOwnerGroupOrder(7, changed);
                return () => h('div');
            },
        }),
        {},
    );
    await flush();
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
});
describe('owner group order concurrency and recovery', () => {
    it('keeps revised quantity rows in place without merging repeated dishes or using old API ids', async () => {
        const initial = fixture();
        const item = {
            id: 11,
            menu_item_id: 10,
            name: 'Salad',
            quantity: 1,
            unit_price: '14.00',
            subtotal: '14.00',
            notes: null,
        };
        initial.participants = [
            {
                id: 1,
                name: 'Owner',
                is_owner: true,
                confirmed: true,
                revoked: false,
                editable: true,
                can_remove: false,
                items: [
                    item,
                    { ...item, id: 12 },
                    { ...item, id: 13, menu_item_id: 20, name: 'Toast' },
                ],
                subtotal: '42.00',
            },
        ];
        request.mockResolvedValueOnce(initial);
        await api.load();
        const revised = structuredClone(initial);
        revised.version = `"${'b'.repeat(64)}"`;
        revised.participants[0].items = [
            initial.participants[0].items[1],
            initial.participants[0].items[2],
            { ...item, id: 99, quantity: 2, subtotal: '28.00' },
        ];
        request.mockResolvedValueOnce(revised);
        await api.mutate('/items/11', 'patch', { quantity: 2 });
        expect(api.order.value?.participants[0].items.map((i) => i.id)).toEqual(
            [99, 12, 13],
        );
        expect(
            api.order.value?.participants[0].items.map((i) => i.display_key),
        ).toEqual([11, 12, 13]);

        const next = structuredClone(revised);
        next.version = `"${'c'.repeat(64)}"`;
        next.participants[0].items = [
            initial.participants[0].items[1],
            initial.participants[0].items[2],
            { ...item, id: 100, quantity: 3, subtotal: '42.00' },
        ];
        request.mockResolvedValueOnce(next);
        await api.mutate('/items/99', 'patch', { quantity: 3 });
        expect(request).toHaveBeenLastCalledWith(
            '/reservations/7/pre-order/owner-view/items/99',
            'patch',
            expect.any(AbortSignal),
            { quantity: 3 },
            revised.version,
        );
        expect(api.order.value?.participants[0].items.map((i) => i.id)).toEqual(
            [100, 12, 13],
        );
        expect(
            api.order.value?.participants[0].items.map((i) => i.display_key),
        ).toEqual([11, 12, 13]);
    });
    it('acknowledges only the loaded event snapshot and keeps reads usable if acknowledgement races', async () => {
        const result = { ...fixture(), notification_event_id: 12 };
        request
            .mockResolvedValueOnce(result)
            .mockRejectedValueOnce(new SessionRequestError(409));
        await api.load();
        await flush();
        expect(request).toHaveBeenLastCalledWith(
            '/reservations/7/pre-order/owner-view/seen',
            'post',
            expect.any(AbortSignal),
            undefined,
            result.version,
        );
        expect(api.order.value?.notification_event_id).toBe(12);
        expect(api.stale.value).toBe(false);
        expect(changed).not.toHaveBeenCalled();
        request
            .mockResolvedValueOnce(result)
            .mockResolvedValueOnce({ acknowledged: true });
        await api.load();
        await flush();
        expect(changed).toHaveBeenCalledOnce();
    });
    it('loads only the protected read endpoint and sends the authoritative version with mutations', async () => {
        expect(request.mock.calls[0].slice(0, 2)).toEqual([
            '/reservations/7/pre-order/owner-view',
            'get',
        ]);
        const updated = fixture();
        updated.version = `"${'b'.repeat(64)}"`;
        updated.summary.grand_total = '61.00';
        request.mockResolvedValueOnce(updated);
        expect(await api.mutate('/items/12', 'patch', { quantity: 3 })).toBe(
            true,
        );
        expect(request).toHaveBeenLastCalledWith(
            '/reservations/7/pre-order/owner-view/items/12',
            'patch',
            expect.any(AbortSignal),
            { quantity: 3 },
            `"${'a'.repeat(64)}"`,
        );
        expect(api.order.value?.summary.grand_total).toBe('61.00');
        expect(changed).toHaveBeenCalledOnce();
    });
    it('blocks another mutation after a conflict until an explicit authoritative refresh', async () => {
        request.mockRejectedValueOnce(new SessionRequestError(409));
        expect(await api.mutate('/items/12', 'patch', { quantity: 3 })).toBe(
            false,
        );
        expect(api.stale.value).toBe(true);
        const calls = request.mock.calls.length;
        await api.mutate('/submit', 'post');
        expect(request.mock.calls).toHaveLength(calls);
        await api.load();
        expect(api.stale.value).toBe(false);
        expect(api.submitted.value).toBe(false);
    });
    it('clears private order details if access is lost', async () => {
        request.mockRejectedValueOnce(new SessionRequestError(403));
        await api.mutate('/submit', 'post');
        expect(api.order.value).toBeNull();
        expect(api.error.value).toContain('access has changed');
    });
    it('does not claim success on an uncertain response', async () => {
        request.mockRejectedValueOnce(new Error('offline'));
        await api.mutate('/submit', 'post');
        expect(api.submitted.value).toBe(false);
        expect(api.stale.value).toBe(true);
        expect(changed).not.toHaveBeenCalled();
    });
    it('guards repeated submission while pending and accepts only the server result', async () => {
        let resolve!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const first = api.mutate('/submit', 'post');
        const count = request.mock.calls.length;
        expect(await api.mutate('/submit', 'post')).toBe(false);
        expect(request.mock.calls).toHaveLength(count);
        resolve({
            ...fixture(),
            status: 'submitted',
            editable: false,
            can_submit: false,
        });
        await first;
        expect(api.submitted.value).toBe(true);
        expect(api.order.value?.editable).toBe(false);
    });
    it('aborts and ignores a late response when the modal unmounts', async () => {
        let resolve!: (value: unknown) => void;
        request.mockImplementationOnce(
            () =>
                new Promise((done) => {
                    resolve = done;
                }),
        );
        const pending = api.mutate('/submit', 'post');
        const signal = request.mock.calls.at(-1)![2];
        mounted.unmount();
        resolve(fixture());
        await pending;
        expect(signal.aborted).toBe(true);
        expect(api.order.value).toBeNull();
        expect(changed).not.toHaveBeenCalled();
    });
});
