import { describe, expect, it, vi } from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import ReservationListCard from './ReservationListCard.vue';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
vi.mock('@/lib/sessionJson', async (original) => ({
    ...(await original<typeof import('@/lib/sessionJson')>()),
    sessionJson: vi.fn(),
}));
vi.mock('@/components/bookings/BookingModal.vue', () => ({
    default: defineComponent({
        props: ['open'],
        setup:
            (props, { slots }) =>
            () =>
                props.open ? h('aside', slots.default?.()) : null,
    }),
}));
vi.mock('@inertiajs/vue3', () => ({
    Link: defineComponent({
        props: ['href'],
        setup:
            (props, { slots }) =>
            () =>
                h('a', { href: props.href }, slots.default?.()),
    }),
}));
describe('reservation group modal entry', () => {
    it('shows an accessible unread marker and only cancels after confirmation with the saved version', async () => {
        const request = vi
            .mocked(sessionJson)
            .mockReset()
            .mockResolvedValue({ status: 'cancelled' });
        const changed = vi.fn();
        const cancelled = vi.fn();
        const version = `"${'a'.repeat(64)}"`;
        const mounted = mountComponent(ReservationListCard, {
            reservation: {
                id: 7,
                status: 'confirmed',
                date: '2026-11-01',
                time: '12:00',
                end_date: '2026-11-01',
                end_time: '14:00',
                guest_count: 4,
                restaurant: { name: 'Garden', address: 'Garden address' },
                has_pre_order_updates: true,
                can_cancel: true,
                cancel_version: version,
            },
            onChanged: changed,
            onCancelled: cancelled,
        });
        const button = (name: string) =>
            findAll(
                mounted.root,
                (n) => n.tag === 'button' && textContent(n).trim() === name,
            )[0];
        const trigger = () =>
            findAll(
                mounted.root,
                (n) =>
                    n.tag === 'button' &&
                    String(n.props['aria-label']).startsWith(
                        'Cancel reservation',
                    ),
            )[0];
        expect(textContent(mounted.root)).toContain('New pre-order updates');
        await fire(trigger(), 'onClick', { currentTarget: {} });
        await fire(button('Keep it'), 'onClick');
        expect(request).not.toHaveBeenCalled();
        await fire(trigger(), 'onClick', { currentTarget: {} });
        await fire(button('Cancel'), 'onClick');
        expect(request).toHaveBeenCalledWith(
            '/reservations/7/cancel',
            'post',
            expect.any(AbortSignal),
            {},
            version,
        );
        expect(changed).toHaveBeenCalledOnce();
        expect(cancelled).toHaveBeenCalledOnce();
        expect(findAll(mounted.root, (n) => n.tag === 'aside')).toHaveLength(0);
        mounted.unmount();
    });
    it('retains the confirmation and reports a conflict without claiming cancellation', async () => {
        vi.mocked(sessionJson)
            .mockReset()
            .mockRejectedValue(new SessionRequestError(409));
        const changed = vi.fn();
        const mounted = mountComponent(ReservationListCard, {
            reservation: {
                id: 7,
                status: 'confirmed',
                date: '2026-11-01',
                time: '12:00',
                end_date: '2026-11-01',
                end_time: '14:00',
                guest_count: 4,
                restaurant: { name: 'Garden', address: 'Garden address' },
                can_cancel: true,
                cancel_version: `"${'a'.repeat(64)}"`,
            },
            onChanged: changed,
        });
        await fire(
            findAll(
                mounted.root,
                (n) =>
                    n.tag === 'button' &&
                    String(n.props['aria-label']).startsWith(
                        'Cancel reservation',
                    ),
            )[0],
            'onClick',
            { currentTarget: {} },
        );
        await fire(
            findAll(
                mounted.root,
                (n) => n.tag === 'button' && textContent(n).trim() === 'Cancel',
            )[0],
            'onClick',
        );
        expect(textContent(mounted.root)).toContain('cancellation is closed');
        expect(changed).not.toHaveBeenCalled();
        mounted.unmount();
    });
    it('emits the modal action without an Inertia navigation or form submission', async () => {
        const group = vi.fn();
        const mounted = mountComponent(ReservationListCard, {
            reservation: {
                id: 7,
                status: 'confirmed',
                date: '2026-10-30',
                time: '12:00',
                end_date: '2026-10-30',
                end_time: '14:00',
                timezone: 'Asia/Tbilisi',
                guest_count: 4,
                restaurant: {
                    name: 'Garden',
                    address: 'Garden address',
                    slug: 'garden',
                },
                can_view_group: true,
            },
            onGroup: group,
        });
        const action = findAll(
            mounted.root,
            (n) =>
                textContent(n).trim() === 'Group Pre-Order' &&
                n.tag === 'button',
        )[0];
        expect(action).toBeDefined();
        expect(action.props.type).toBe('button');
        expect(action.props.href).toBeUndefined();
        await fire(action, 'onClick');
        expect(group).toHaveBeenCalledOnce();
        mounted.unmount();
    });
});
