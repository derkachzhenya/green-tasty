import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vite-plus/test';
import { createSSRApp, defineComponent, h } from 'vue';
import Access from './Access.vue';

const { auth } = vi.hoisted(() => ({
    auth: { user: null as null | { name: string } },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { auth } }),
    router: { clearHistory: vi.fn() },
    Head: { render: () => null },
    Link: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('a', attrs, slots.default?.()),
    }),
}));

describe('staff access page', () => {
    it('offers guests a protected sign-in entry and gives customers a clear account switch', async () => {
        auth.user = null;
        const guest = await renderToString(
            createSSRApp(Access, { workplaces: [] }),
        );
        expect(guest).toContain('Staff sign in');
        expect(guest).toContain('href="/staff/login"');
        expect(guest).not.toContain('Open workspace');

        auth.user = { name: 'Customer' };
        const customer = await renderToString(
            createSSRApp(Access, { workplaces: [] }),
        );
        expect(customer).toContain('This account has no active staff access');
        expect(customer).toContain('Switch to staff sign in');
        expect(customer).not.toContain('Open workspace');
    });

    it('offers authorized staff their restaurant workspace', async () => {
        auth.user = { name: 'Alex Caper' };
        const html = await renderToString(
            createSSRApp(Access, {
                workplaces: [
                    {
                        id: 42,
                        name: 'Assigned restaurant',
                        address: 'Restaurant address',
                    },
                ],
            }),
        );
        expect(html).toContain('Welcome, Alex Caper');
        expect(html).toContain('Assigned restaurant');
        expect(html).toContain('href="/staff/restaurants/42/reservations"');
        expect(html).not.toContain('Staff sign in');
    });
});
