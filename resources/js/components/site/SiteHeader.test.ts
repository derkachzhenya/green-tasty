import { createInertiaApp } from '@inertiajs/vue3';
import { renderToString } from '@vue/server-renderer';
import { describe, expect, it } from 'vite-plus/test';
import { createSSRApp, h, type DefineComponent } from 'vue';
import SiteHeader from './SiteHeader.vue';

async function renderHeader(auth?: { user: { id: number } | null }) {
    return createInertiaApp<{
        errors: Record<string, string>;
        name: string;
        sidebarOpen: boolean;
        auth?: { user: { id: number } | null };
    }>({
        page: {
            component: 'bookings/Create',
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
    it('renders public navigation when auth shared props are absent', async () => {
        const result = await renderHeader();
        expect(result?.body).toContain('Sign In');
        expect(result?.body).toContain('aria-current="page"');
        expect(result?.body).not.toContain('Account settings');
        expect(result?.body).not.toContain('href="/dashboard"');
    });

    it('renders the same public navigation for an explicit guest', async () => {
        const result = await renderHeader({ user: null });
        expect(result?.body).toContain('Sign In');
        expect(result?.body).not.toContain('Account settings');
    });

    it('retains account and reservation navigation for an authenticated user', async () => {
        const result = await renderHeader({ user: { id: 1 } });
        expect(result?.body).toContain('Account settings');
        expect(result?.body).toContain('Reservations');
        expect(result?.body).not.toContain('Sign In');
    });
});
