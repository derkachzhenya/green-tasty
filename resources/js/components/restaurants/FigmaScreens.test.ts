import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import FigmaReviews from './FigmaReviews.vue';
import RestaurantProfileHero from './RestaurantProfileHero.vue';
import DishPreview from './DishPreview.vue';
import PopularDishCard from './PopularDishCard.vue';
import { nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');
    return {
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
    };
});
vi.mock('@/components/ui/button', async () => {
    const { defineComponent } = await import('vue');
    return {
        Button: defineComponent({
            inheritAttrs: false,
            setup:
                (_props, { slots }) =>
                () =>
                    slots.default?.(),
        }),
    };
});
// Exercise preview content and state with the lightweight renderer. Real Reka focus/portals are checked in Chrome.
vi.mock('reka-ui', async () => {
    const { defineComponent, h, provide, inject, ref } = await import('vue');
    type State = { value: boolean };
    const box = (tag: string) =>
        defineComponent({
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h(tag, attrs, slots.default?.()),
        });
    return {
        DialogRoot: defineComponent({
            setup: (_props, { slots }) => {
                const open = ref(false);
                provide('preview', open);
                return () => h('section', {}, slots.default?.());
            },
        }),
        DialogTrigger: defineComponent({
            props: ['asChild'],
            setup: (_props, { slots }) => {
                const open = inject<State>('preview')!;
                return () =>
                    h(
                        'div',
                        {
                            onClick: () => {
                                open.value = true;
                            },
                        },
                        slots.default?.(),
                    );
            },
        }),
        DialogPortal: defineComponent({
            setup: (_props, { slots }) => {
                const open = inject<State>('preview')!;
                return () => (open.value ? slots.default?.() : null);
            },
        }),
        DialogOverlay: box('div'),
        DialogContent: box('article'),
        DialogTitle: box('h2'),
        DialogDescription: box('p'),
        DialogClose: defineComponent({
            setup: (_props, { attrs, slots }) => {
                const open = inject<State>('preview')!;
                return () =>
                    h(
                        'button',
                        {
                            ...attrs,
                            onClick: () => {
                                open.value = false;
                            },
                        },
                        slots.default?.(),
                    );
            },
        }),
    };
});

let mounted: ReturnType<typeof mountComponent>;
afterEach(() => mounted?.unmount());
const nodes = (tag: string) =>
    findAll(mounted.root, (node) => node.tag === tag);
const restaurant = {
    id: 1,
    name: 'Green Tasty - Location 1',
    slug: 'location-1',
    address: 'Demo Address 1',
    phone: null,
    table_count: 3,
};

describe('public profile and demo content', () => {
    it('keeps booking identity and menu navigation', () => {
        mounted = mountComponent(RestaurantProfileHero, { restaurant });
        expect(nodes('h1').map(textContent).join('')).toContain(
            'Demo Address 1',
        );
        expect(nodes('a').map((node) => node.props.href)).toEqual([
            '/restaurants/location-1/book',
            '#menu',
        ]);
    });
    it('does not attach Rustaveli demo content to an unrelated restaurant', () => {
        mounted = mountComponent(RestaurantProfileHero, {
            restaurant: {
                ...restaurant,
                slug: 'actual',
                address: 'Actual address',
            },
        });
        expect(textContent(mounted.root)).toContain('Actual address');
        expect(textContent(mounted.root)).toContain('3 tables');
        expect(textContent(mounted.root)).not.toContain('4.73');
        expect(textContent(mounted.root)).not.toContain('Atmosphere');
    });
    it('paginates distinct samples and resets the page when category or sort changes', async () => {
        mounted = mountComponent(FigmaReviews, {});
        const button = (name: string) =>
            nodes('button').find((node) => node.props['aria-label'] === name)!;
        const categoryButton = (name: string) =>
            nodes('button').find((node) => textContent(node) === name)!;
        const firstPageText = nodes('article').map(textContent);
        expect(nodes('article')).toHaveLength(4);
        expect(nodes('time')[0].props.datetime).toBe('2024-08-06');
        await fire(button('Page 2'), 'onClick');
        const secondPageText = nodes('article').map(textContent);
        expect(
            secondPageText.every((text) => !firstPageText.includes(text)),
        ).toBe(true);
        expect(button('Page 2').props['aria-current']).toBe('page');
        await fire(button('Next review page'), 'onClick');
        expect(button('Page 3').props['aria-current']).toBe('page');
        expect(
            nodes('article')
                .map(textContent)
                .every((text) => !secondPageText.includes(text)),
        ).toBe(true);
        expect(button('Next review page').props.disabled).toBe(true);
        await fire(nodes('select')[0], 'onChange', {
            target: { value: 'oldest' },
        });
        expect(button('Page 1').props['aria-current']).toBe('page');
        expect(nodes('time')[0].props.datetime).toBe('2024-06-21');
        await fire(nodes('select')[0], 'onChange', {
            target: { value: 'lowest' },
        });
        expect(
            nodes('p').find((node) => node.props['aria-label'])?.props[
                'aria-label'
            ],
        ).toBe('2 out of 5 stars');
        await fire(nodes('select')[0], 'onChange', {
            target: { value: 'highest' },
        });
        expect(
            nodes('p').find((node) => node.props['aria-label'])?.props[
                'aria-label'
            ],
        ).toBe('5 out of 5 stars');
        await fire(button('Page 3'), 'onClick');
        await fire(categoryButton('Cuisine experience'), 'onClick');
        expect(button('Page 1').props['aria-current']).toBe('page');
        expect(categoryButton('Cuisine experience').props['aria-pressed']).toBe(
            true,
        );
        expect(
            nodes('article')
                .map(textContent)
                .every((text) => !firstPageText.includes(text)),
        ).toBe(true);
        expect(textContent(mounted.root)).toContain(
            'The strawberry salad had a lovely balance',
        );
    });

    it.each([
        ['Fresh Strawberry Mint Salad', '17.00', '$17', '430 g'],
        ['Spring Salad', '19.00', '$19', '280 g'],
        ['Roasted Sweet Potato & Lentil Salad', '10.00', '$10', '380 g'],
        ['Avocado Pine Nut Bowl', '99.99', '$99.99', '430 g'],
    ])(
        'uses the same price and catalog weight for %s in the card and dialog',
        async (name, price, formatted, weight) => {
            mounted = mountComponent(PopularDishCard, {
                name,
                image: '/dish.png',
                price,
            });
            expect(nodes('p').map(textContent)).toContain(formatted);
            await fire(
                findAll(
                    mounted.root,
                    (node) => typeof node.props.onClick === 'function',
                )[0],
                'onClick',
            );
            expect(nodes('span').map(textContent)).toContain(formatted);
            expect(textContent(nodes('article')[1])).toContain(weight);
            expect(textContent(nodes('article')[1])).not.toContain('$15');
            expect(nodes('article')[1].props.class).toContain('green-tasty');
            mounted.setProps({ price: '22.50' });
            await nextTick();
            expect(nodes('p').map(textContent)).toContain('$22.50');
            expect(nodes('span').map(textContent)).toContain('$22.50');
            if (name === 'Fresh Strawberry Mint Salad') {
                expect(textContent(mounted.root)).toContain(
                    'Calories: ~503 kcal',
                );
                expect(nodes('strong').map(textContent)).toContain('Fats:');
            } else {
                expect(textContent(mounted.root)).not.toContain('Calories');
            }
            await fire(
                nodes('button').find(
                    (node) => node.props['aria-label'] === 'Close dish preview',
                )!,
                'onClick',
            );
            expect(nodes('h2')).toHaveLength(0);
        },
    );
    it('uses fallback catalog prices and does not invent unknown dish details', async () => {
        mounted = mountComponent(PopularDishCard, {
            name: 'Spring Salad',
            image: '/dish.png',
        });
        expect(textContent(mounted.root)).toContain('$19');
        await fire(
            findAll(
                mounted.root,
                (node) => typeof node.props.onClick === 'function',
            )[0],
            'onClick',
        );
        expect(textContent(nodes('article')[1])).toContain('$19');
        mounted.unmount();
        mounted = mountComponent(DishPreview, {
            name: 'Unknown dish',
            image: '/dish.png',
        });
        await fire(
            findAll(
                mounted.root,
                (node) => typeof node.props.onClick === 'function',
            )[0],
            'onClick',
        );
        expect(textContent(mounted.root)).toContain('Price unavailable');
        expect(textContent(mounted.root)).toContain('Weight not specified');
        expect(textContent(mounted.root)).not.toContain('430');
        expect(textContent(mounted.root)).not.toContain('Calories');
    });
});
