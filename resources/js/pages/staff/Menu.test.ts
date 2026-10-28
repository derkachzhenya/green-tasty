import { renderToString } from '@vue/server-renderer';
import { describe, it, expect, vi } from 'vite-plus/test';
import { createSSRApp, defineComponent, h } from 'vue';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import GuestDishPreview from '@/components/guest/GuestDishPreview.vue';
import Menu from './Menu.vue';

vi.mock('@inertiajs/vue3', () => ({ Head: { render: () => null } }));
vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('button', attrs, slots.default?.()),
    }),
}));
vi.mock('reka-ui', () => {
    const box = defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('div', attrs, slots.default?.()),
    });
    return Object.fromEntries(
        [
            'DialogRoot',
            'DialogTrigger',
            'DialogPortal',
            'DialogOverlay',
            'DialogContent',
            'DialogTitle',
            'DialogDescription',
            'DialogClose',
        ].map((name) => [name, box]),
    );
});
const dish = {
    id: 1,
    name: 'Fresh Strawberry Mint Salad',
    description: 'Ingredients',
    price: '17.00',
    available: true,
    sort_order: 1,
    created_at: null,
    popularity: 0,
};
describe('staff menu', () => {
    it('shows current prices and sold out dishes without customer order controls', async () => {
        const html = await renderToString(
            createSSRApp(Menu, {
                restaurant: {
                    id: 1,
                    name: 'Workplace',
                    address: '48 Rustaveli Avenue',
                },
                categories: [
                    {
                        id: 1,
                        name: 'Salads',
                        dishes: [
                            dish,
                            {
                                ...dish,
                                id: 2,
                                name: 'Green Asparagus Salad',
                                available: false,
                            },
                        ],
                    },
                ],
            }),
        );
        expect(html).toContain('48 Rustaveli Avenue');
        expect(html).toContain('$17');
        expect(html).toContain('Sold Out');
        expect(html).not.toContain('Pre-Order');
        expect(html).not.toContain('Selections locked');
        expect(html).not.toContain('Book a Table');
    });
    it('switches categories locally', async () => {
        const mounted = mountComponent(Menu, {
            restaurant: { id: 1, name: 'Workplace', address: 'Address' },
            categories: [
                { id: 1, name: 'Salads', dishes: [dish] },
                {
                    id: 2,
                    name: 'Desserts',
                    dishes: [{ ...dish, id: 2, name: 'Citrus Cream Tart' }],
                },
            ],
        });
        try {
            expect(textContent(mounted.root)).toContain(
                'Fresh Strawberry Mint Salad',
            );
            await fire(
                findAll(
                    mounted.root,
                    (n) => n.tag === 'button' && textContent(n) === 'Desserts',
                )[0],
                'onClick',
            );
            expect(textContent(mounted.root)).toContain('Citrus Cream Tart');
            expect(textContent(mounted.root)).not.toContain(
                'Fresh Strawberry Mint Salad',
            );
        } finally {
            mounted.unmount();
        }
    });
    it('preserves the pre-order action in the existing customer dish preview', async () => {
        const html = await renderToString(
            createSSRApp(GuestDishPreview, {
                dish,
                disabled: false,
                locked: false,
            }),
        );
        expect(html).toContain('Pre-Order');
    });
});
