import { createInertiaApp } from '@inertiajs/vue3';
import { renderToString } from '@vue/server-renderer';
import { describe, expect, it } from 'vite-plus/test';
import { createSSRApp, h, type DefineComponent } from 'vue';
import SiteHeader from './SiteHeader.vue';

async function renderHeader(
    auth?: { user: { id: number } | null },
    component = 'bookings/Create',
) {
    return createInertiaApp<{
        errors: Record<string, string>;
        name: string;
        sidebarOpen: boolean;
        auth?: { user: { id: number } | null };
    }>({
        page: {
            component,
            props: {
                errors: {},
                name: 'Green Tasty',
                sidebarOpen: true,
                ...(auth ? { auth } : {}),
            },
            url: '/book',
            version: null,
            clearHistory: false,
            encryptHistory: false,
            flash: {},
            rescuedProps: [],
            rememberedState: {},
        },
        resolve: () => SiteHeader as DefineComponent,
        render: renderToString,
        setup: ({ App, props, plugin }) =>
            createSSRApp({ render: () => h(App, props) }).use(plugin),
    });
}

describe('site header server rendering', () => {
    it('highlights Main page for a restaurant profile', async () => {
        const result = await renderHeader(undefined, 'restaurants/Show');
        expect(result?.body).toMatch(
            /<a(?=[^>]*href="\/")(?=[^>]*aria-current="page")[^>]*>\s*Main page\s*<\/a>/,
        );
    });
    it('renders public navigation when auth shared props are absent', async () => {
        const result = await renderHeader();
        expect(result?.body).toContain('Sign In');
        expect(result?.body).toContain('aria-current="page"');
        expect(result?.body).not.toContain('Account settings');
        expect(result?.body).not.toContain('href="/reservations"');
    });

    it('renders the same public navigation for an explicit guest', async () => {
        const result = await renderHeader({ user: null });
        expect(result?.body).toContain('Sign In');
        expect(result?.body).not.toContain('Account settings');
    });

    it('retains account and reservation navigation for an authenticated user', async () => {
        const result = await renderHeader({ user: { id: 1 } });
        expect(result?.body).toContain('Account menu');
        expect(result?.body).toContain('Reservations');
        expect(result?.body).not.toContain('Sign In');
    });

    it('keeps Reservations active on reservation details and outside account settings', async () => {
        const details = await renderHeader(
            { user: { id: 1 } },
            'reservations/Show',
        );
        expect(details?.body).toMatch(
            /<a(?=[^>]*href="\/reservations")(?=[^>]*aria-current="page")[^>]*>\s*Reservations\s*<\/a>/,
        );
        const settings = await renderHeader(
            { user: { id: 1 } },
            'settings/Profile',
        );
        expect(settings?.body).not.toMatch(
            /<a(?=[^>]*href="\/reservations")(?=[^>]*aria-current="page")/,
        );
        expect(settings?.body).toContain('Account menu');
    });
});
