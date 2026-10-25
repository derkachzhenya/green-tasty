import { describe, expect, it, vi } from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import ReservationListCard from './ReservationListCard.vue';
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
