import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vite-plus/test';
import { createSSRApp, defineComponent, h } from 'vue';
import Dashboard from './Dashboard.vue';

const state = vi.hoisted(() => ({ loaded: false }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth: { user: { name: 'Owner' } } } }),
    router: { reload: vi.fn() },
    Head: { render: () => null },
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
    Deferred: defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                state.loaded ? slots.default?.() : slots.fallback?.(),
    }),
}));

describe('reservations dashboard SSR', () => {
    it('renders without optional deferred reservations or counters', async () => {
        state.loaded = false;
        const html = await renderToString(createSSRApp(Dashboard));
        expect(html).toContain('Hello, Owner');
        expect(html).toContain('Loading your reservations');
        expect(html).not.toContain('undefined');
    });
    it('renders the empty persisted result without missing restaurant props', async () => {
        state.loaded = true;
        const html = await renderToString(
            createSSRApp(Dashboard, {
                reservations: {
                    data: [],
                    total: 0,
                    current_page: 1,
                    last_page: 1,
                    per_page: 6,
                    from: null,
                    to: null,
                    prev_page_url: null,
                    next_page_url: null,
                },
                reservationCounts: {
                    all: 0,
                    upcoming: 0,
                    in_progress: 0,
                    completed: 0,
                    canceled: 0,
                },
            }),
        );
        expect(html).toContain('No Reservations');
        expect(html).not.toContain('undefined');
    });
});
