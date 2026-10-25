import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { nextTick } from 'vue';
import SiteHeader from '@/components/site/SiteHeader.vue';
import { homepageLocations } from '@/lib/homepage';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
    type TestNode,
} from '@/test-support/renderComponent';
import type { RestaurantListing } from '@/types/restaurant';
import RestaurantLanding from './RestaurantLanding.vue';

vi.mock('@/components/restaurants/DishPreview.vue', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        default: defineComponent({
            props: ['name', 'image'],
            setup:
                (props, { slots }) =>
                () =>
                    h(
                        'button',
                        {
                            type: 'button',
                            'aria-label': `View ${props.name} — Figma demo`,
                        },
                        slots.default?.(),
                    ),
        }),
    };
});

const navigation = vi.hoisted(() => ({
    reload: vi.fn(),
    setMode: (_key: string, _value: 'loaded' | 'loading' | 'error') => {},
    setUser: (_value: boolean) => {},
}));

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, reactive } = await import('vue');
    const modes = reactive<Record<string, string>>({});
    const page = reactive({
        component: 'restaurants/Index',
        props: { auth: { user: null as { name: string } | null } },
    });
    navigation.setMode = (key, value) => {
        modes[key] = value;
    };
    navigation.setUser = (value) => {
        page.props.auth.user = value ? { name: 'Owner' } : null;
    };
    return {
        usePage: () => page,
        router: { reload: navigation.reload },
        Link: defineComponent({
            inheritAttrs: false,
            props: ['href'],
            setup:
                (props, { attrs, slots }) =>
                () =>
                    h(
                        'a',
                        {
                            ...attrs,
                            href:
                                typeof props.href === 'string'
                                    ? props.href
                                    : props.href?.url,
                        },
                        slots.default?.(),
                    ),
        }),
        Deferred: defineComponent({
            props: ['data'],
            setup:
                (props, { slots }) =>
                () =>
                    modes[props.data] === 'loading'
                        ? slots.fallback?.()
                        : modes[props.data] === 'error'
                          ? slots.rescue?.()
                          : slots.default?.(),
        }),
    };
});

vi.mock('@/components/ui/button', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        Button: defineComponent({
            inheritAttrs: false,
            props: ['asChild', 'disabled'],
            setup:
                (props, { attrs, slots }) =>
                () =>
                    props.asChild
                        ? slots.default?.()
                        : h(
                              'button',
                              { ...attrs, disabled: props.disabled },
                              slots.default?.(),
                          ),
        }),
    };
});

const restaurants: RestaurantListing[] = [1, 2, 3].map((id) => ({
    id,
    name: `Green Tasty - Location ${id}`,
    slug: `location-${id}`,
    address: `Demo Address ${id}`,
    table_count: id + 1,
}));
let mounted: ReturnType<typeof mountComponent>;
const nodes = (predicate: (node: TestNode) => boolean) =>
    findAll(mounted.root, predicate);
const links = () => nodes((node) => node.tag === 'a');

beforeEach(() => {
    navigation.reload.mockReset();
    navigation.setMode('restaurants', 'loaded');
    navigation.setMode('featuredPrices', 'loaded');
    navigation.setUser(false);
});
afterEach(() => mounted?.unmount());

describe('homepage presentation and real navigation', () => {
    it('shows the required hero actions and four named dish images in Figma order', () => {
        mounted = mountComponent(RestaurantLanding, {
            restaurants,
            featuredPrices: {},
        });
        expect(
            links()
                .filter((node) => node.props.href === '#locations')
                .map(textContent),
        ).toEqual(['Book a table', 'View menu']);
        const dishes = nodes(
            (node) =>
                node.tag === 'ul' &&
                node.props['aria-label'] === 'Popular dishes',
        )[0];
        expect(
            findAll(dishes, (node) => node.tag === 'h3').map(textContent),
        ).toEqual([
            'Fresh Strawberry Mint Salad',
            'Avocado Pine Nut Bowl',
            'Roasted Sweet Potato & Lentil Salad',
            'Spring Salad',
        ]);
        expect(
            findAll(dishes, (node) => node.tag === 'img').map(
                (node) => node.props.src,
            ),
        ).toEqual([
            '/images/green-tasty/dishes/fresh-strawberry-mint-salad.png',
            '/images/green-tasty/dishes/avocado-pine-nut-bowl.png',
            '/images/green-tasty/dishes/roasted-sweet-potato-lentil-salad.png',
            '/images/green-tasty/dishes/spring-salad.png',
        ]);
        expect(textContent(mounted.root)).not.toContain(
            'Figma demo: dish prices',
        );
        expect(textContent(mounted.root)).not.toContain(
            'illustrative. Check a location',
        );
        const locationNotices = nodes(
            (node) => node.tag === 'p' && textContent(node).startsWith('Demo:'),
        );
        expect(locationNotices).toHaveLength(3);
        expect(
            locationNotices.every((node) => node.props.class === 'sr-only'),
        ).toBe(true);
        expect(textContent(mounted.root)).not.toContain('Customer Reviews');
        expect(textContent(mounted.root)).toContain('$17');
        expect(textContent(mounted.root)).toContain('430 g');
        expect(textContent(mounted.root)).toContain('Daily occupancy:');
    });

    it('maps approved demo labels to existing slugs with real table counts and explicit demo addresses', () => {
        mounted = mountComponent(RestaurantLanding, { restaurants });
        expect(links().map((node) => node.props.href)).toEqual([
            '#locations',
            '#locations',
            '/restaurants/location-1',
            '/restaurants/location-2',
            '/restaurants/location-3',
        ]);
        for (const label of [
            'Demo Address 1',
            'Demo Address 2',
            'Demo Address 3',
            'Demo: Demo Address 1',
            'Demo: Demo Address 2',
            'Demo: Demo Address 3',
            '10 tables',
            '16 tables',
            '20 tables',
        ]) {
            expect(textContent(mounted.root)).toContain(label);
        }
    });

    it('prefers exact real addresses, preserves extra locations, and creates no links for missing records', () => {
        const actual = {
            id: 7,
            name: 'Actual Garden',
            slug: 'actual-garden',
            address: '48 Rustaveli Avenue',
            table_count: 5,
        };
        const extra = {
            id: 8,
            name: 'New Venue',
            slug: 'new-venue',
            address: 'A new address',
            table_count: 1,
        };
        const locations = homepageLocations([restaurants[0], actual, extra]);
        expect(locations[0]).toMatchObject({
            restaurant: actual,
            address: actual.address,
            demoLabel: null,
        });
        expect(locations.map((location) => location.restaurant.id)).toEqual([
            7, 1, 8,
        ]);
        expect(locations[2]).toMatchObject({ restaurant: extra, image: null });
        expect(homepageLocations([])).toEqual([]);
        mounted = mountComponent(RestaurantLanding, { restaurants: [] });
        expect(textContent(mounted.root)).toContain('No locations available');
        expect(
            links().filter((node) =>
                String(node.props.href).startsWith('/restaurants/'),
            ),
        ).toHaveLength(0);
    });

    it('never substitutes a design address for the database identity, before or after demo alignment', () => {
        const before = homepageLocations(restaurants);
        expect(before.map((location) => location.address)).toEqual(
            restaurants.map((restaurant) => restaurant.address),
        );
        expect(before.map((location) => location.restaurant.slug)).toEqual([
            'location-1',
            'location-2',
            'location-3',
        ]);
        const addresses = [
            '48 Rustaveli Avenue',
            '14 Baratashvili Street',
            '9 Abashidze Street',
        ];
        const aligned = restaurants.map((restaurant, index) => ({
            ...restaurant,
            address: addresses[index],
        }));
        mounted = mountComponent(RestaurantLanding, { restaurants: aligned });
        expect(
            links()
                .filter((node) =>
                    String(node.props.href).startsWith('/restaurants/'),
                )
                .map((node) => node.props.href),
        ).toEqual([
            '/restaurants/location-1',
            '/restaurants/location-2',
            '/restaurants/location-3',
        ]);
        for (const address of addresses)
            expect(textContent(mounted.root)).toContain(address);
        expect(textContent(mounted.root)).not.toContain('Demo Address');
        expect(
            homepageLocations(aligned).every(
                (location) => location.address === location.restaurant.address,
            ),
        ).toBe(true);
    });

    it('prefers exact backend decimal strings and labels missing prices as a Figma demo', () => {
        mounted = mountComponent(RestaurantLanding, {
            restaurants,
            featuredPrices: {
                'spring salad': '10.05',
                'Avocado Pine Nut Bowl': '99.99',
            },
        });
        const text = textContent(mounted.root);
        expect(text).toContain('10.05');
        expect(text).toContain('99.99');
        expect(text).toContain('from');
        expect(
            nodes(
                (node) =>
                    node.tag === 'span' &&
                    textContent(node) === 'Figma demo price: ',
            ),
        ).toHaveLength(2);
        expect(text).toContain('Figma demo');
    });

    it('keeps the supplied hero artwork separate from the dish thumbnails', () => {
        mounted = mountComponent(RestaurantLanding, { restaurants });
        const bowls = nodes(
            (node) =>
                node.tag === 'img' &&
                /\/(salad1|hero-avocado-bowl)\.png$/.test(
                    String(node.props.src),
                ),
        );
        expect(bowls).toHaveLength(2);
        expect(
            bowls.map((node) => [node.props.width, node.props.height]),
        ).toEqual([
            ['1254', '1254'],
            ['532', '405'],
        ]);
        expect(bowls.every((node) => node.props.alt === '')).toBe(true);
    });
});

describe('loading and retry states', () => {
    it('preserves dish artwork and announces price and location loading', () => {
        navigation.setMode('featuredPrices', 'loading');
        navigation.setMode('restaurants', 'loading');
        mounted = mountComponent(RestaurantLanding, {});
        expect(textContent(mounted.root)).toContain('Loading locations');
        expect(
            nodes(
                (node) =>
                    node.tag === 'span' &&
                    textContent(node) === 'Loading price',
            ),
        ).toHaveLength(4);
        expect(
            nodes(
                (node) =>
                    node.tag === 'ul' && node.props['aria-busy'] === 'true',
            ),
        ).toHaveLength(1);
    });

    it('retries only prices, disables duplicate retries and reports network failures', async () => {
        navigation.setMode('featuredPrices', 'error');
        mounted = mountComponent(RestaurantLanding, { restaurants });
        const retry = () =>
            nodes(
                (node) =>
                    node.tag === 'button' &&
                    /Try again|Trying again/.test(textContent(node)),
            )[0];
        expect(textContent(mounted.root)).toContain(
            'We couldn’t load current menu prices',
        );
        expect(
            links().some(
                (node) => node.props.href === '/restaurants/location-1',
            ),
        ).toBe(true);
        navigation.reload.mockImplementation((options) => {
            options.onStart();
        });
        await fire(retry(), 'onClick');
        const options = navigation.reload.mock.calls[0][0];
        expect(options.only).toEqual(['featuredPrices']);
        expect(retry().props.disabled).toBe(true);
        expect(options.onNetworkError()).toBe(false);
        options.onFinish();
        await nextTick();
        expect(textContent(mounted.root)).toContain(
            'Please check your connection',
        );
        expect(retry().props.disabled).toBe(false);
        navigation.setMode('featuredPrices', 'loaded');
        mounted.setProps({ featuredPrices: {} });
        await nextTick();
        expect(textContent(mounted.root)).not.toContain(
            'We couldn’t load current menu prices',
        );
        expect(
            nodes(
                (node) =>
                    node.tag === 'span' &&
                    textContent(node) === 'Figma demo price: ',
            ),
        ).toHaveLength(4);
    });

    it('handles location errors independently of dish prices', async () => {
        navigation.setMode('restaurants', 'error');
        mounted = mountComponent(RestaurantLanding, {
            featuredPrices: { 'Spring Salad': '11.20' },
        });
        expect(textContent(mounted.root)).toContain('11.20');
        expect(textContent(mounted.root)).toContain(
            'We couldn’t load the locations',
        );
        await fire(
            nodes(
                (node) =>
                    node.tag === 'button' && textContent(node) === 'Try again',
            )[0],
            'onClick',
        );
        expect(navigation.reload.mock.calls[0][0].only).toEqual([
            'restaurants',
        ]);
    });

    it('renders the auth background without starting another deferred price request', () => {
        navigation.setMode('featuredPrices', 'loading');
        mounted = mountComponent(RestaurantLanding, {
            restaurants,
            pricesResolved: true,
        });
        expect(
            nodes(
                (node) =>
                    node.tag === 'span' &&
                    textContent(node) === 'Figma demo price: ',
            ),
        ).toHaveLength(4);
        expect(navigation.reload).not.toHaveBeenCalled();
    });
});

describe('shared header', () => {
    it('keeps the real sign-in and booking destinations with the supplied logo', () => {
        mounted = mountComponent(SiteHeader, {});
        expect(
            links().find((node) => textContent(node) === 'Sign In')?.props.href,
        ).toBe('/login');
        expect(
            links().find((node) => textContent(node).trim() === 'Book a Table')
                ?.props.href,
        ).toBe('/book');
        expect(
            links().find((node) => textContent(node).trim() === 'Main page')
                ?.props['aria-current'],
        ).toBe('page');
        expect(nodes((node) => node.tag === 'img')[0].props.src).toBe(
            '/images/green-tasty/logo.png',
        );
    });

    it('shows the protected My reservations destination for an authenticated owner', () => {
        navigation.setUser(true);
        mounted = mountComponent(SiteHeader, {});
        expect(
            links().find((node) => textContent(node) === 'Reservations')?.props
                .href,
        ).toBe('/dashboard');
        expect(links().some((node) => node.props.href === '/login')).toBe(
            false,
        );
    });
});
