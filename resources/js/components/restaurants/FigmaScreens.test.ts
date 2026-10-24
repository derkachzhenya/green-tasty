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

describe('Figma profile and safe demonstration content', () => {
    it('links to the real booking route and live menu, with an explicit demo location and rating', () => {
        mounted = mountComponent(RestaurantProfileHero, { restaurant });
        expect(textContent(mounted.root)).toContain('Demo Address 1');
        expect(nodes('h1').map(textContent).join('')).not.toContain(
            '48 Rustaveli Avenue',
        );
        expect(textContent(mounted.root)).toContain('Demo: Demo Address 1');
        expect(textContent(mounted.root)).toContain('Booking uses live data');
        expect(
            nodes('p').find((node) =>
                textContent(node).includes('Booking uses live data'),
            )?.props.class,
        ).toBe('sr-only');
        expect(nodes('a').map((node) => node.props.href)).toEqual([
            '/restaurants/location-1/book',
            '#menu',
        ]);
        expect(nodes('img')[0].props.src).toContain('rustaveli-profile.jpg');
    });
    it('does not attach Rustaveli descriptions, ratings or photos to an unrelated restaurant', () => {
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
        expect(
            nodes('img').some((node) =>
                String(node.props.src).includes('rustaveli-profile'),
            ),
        ).toBe(false);
    });
    it('labels reviews as fictional design examples and supports sorting without pretending additional pages exist', async () => {
        mounted = mountComponent(FigmaReviews, {});
        expect(textContent(mounted.root)).toContain(
            'not real customer reviews',
        );
        expect(
            nodes('p').find((node) =>
                textContent(node).includes('not real customer reviews'),
            )?.props.class,
        ).toBe('sr-only');
        expect(nodes('article')).toHaveLength(4);
        expect(nodes('time')[0].props.datetime).toBe('2024-08-06');
        await fire(nodes('select')[0], 'onChange', {
            target: { value: 'oldest' },
        });
        expect(nodes('time')[0].props.datetime).toBe('2024-07-01');
        await fire(
            nodes('button').find(
                (node) => textContent(node) === 'Cuisine experience',
            )!,
            'onClick',
        );
        expect(
            nodes('button').find(
                (node) => textContent(node) === 'Cuisine experience',
            )!.props['aria-pressed'],
        ).toBe(true);
        expect(
            nodes('button').filter((node) => node.props.disabled !== undefined),
        ).toHaveLength(3);
        expect(nodes('form')).toHaveLength(0);
    });
    it('opens and closes a read-only dish preview with verified Figma nutrition and demo price', async () => {
        mounted = mountComponent(DishPreview, {
            name: 'Fresh Strawberry Mint Salad',
            image: '/thumbnail.png',
        });
        expect(nodes('h2')).toHaveLength(0);
        await fire(
            findAll(
                mounted.root,
                (node) => typeof node.props.onClick === 'function',
            )[0],
            'onClick',
        );
        expect(nodes('h2').map(textContent)).toEqual([
            'Fresh Strawberry Mint Salad',
        ]);
        expect(textContent(mounted.root)).toContain('Calories: ~503 kcal');
        expect(textContent(mounted.root)).toContain('$15');
        expect(textContent(mounted.root)).toContain(
            'This preview cannot be ordered',
        );
        expect(
            nodes('p').find((node) =>
                textContent(node).includes('This preview cannot be ordered'),
            )?.props.class,
        ).toBe('sr-only');
        expect(nodes('img')[0].props.src).toContain('strawberry-detail.png');
        expect(nodes('form')).toHaveLength(0);
        await fire(
            nodes('button').find(
                (node) => node.props['aria-label'] === 'Close dish preview',
            )!,
            'onClick',
        );
        expect(nodes('h2')).toHaveLength(0);
    });
    it('does not invent nutritional information for other preview dishes', async () => {
        mounted = mountComponent(DishPreview, {
            name: 'Spring Salad',
            image: '/spring.png',
        });
        await fire(
            findAll(
                mounted.root,
                (node) => typeof node.props.onClick === 'function',
            )[0],
            'onClick',
        );
        expect(textContent(mounted.root)).not.toContain('Calories');
        expect(textContent(mounted.root)).toContain('live menu');
        expect(nodes('img')[0].props.src).toBe('/spring.png');
    });
});
