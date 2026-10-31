import { createInertiaApp } from '@inertiajs/vue3';
import { renderToString } from '@vue/server-renderer';
import { describe, expect, it } from 'vite-plus/test';
import { createSSRApp, h, type DefineComponent } from 'vue';
import SiteHeader from './SiteHeader.vue';

async function renderHeader(
    auth?: { user: { id: number } | null; isStaff?: boolean },
    component = 'bookings/Create',
    extraProps: Record<string, unknown> = {},
) {
    return createInertiaApp<{
        errors: Record<string, string>;
        name: string;
        sidebarOpen: boolean;
        auth?: { user: { id: number } | null; isStaff?: boolean };
    }>({
        page: {
            component,
            props: {
                errors: {},
                name: 'Green Tasty',
                sidebarOpen: true,
                ...extraProps,
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
    it('keeps staff navigation inside the workspace on every page and highlights its menu', async () => {
        for (const component of [
            'staff/Reservations',
            'staff/Menu',
            'settings/Profile',
            'restaurants/Show',
        ]) {
            const result = await renderHeader(
                { user: { id: 3 }, isStaff: true },
                component,
                {
                    restaurant: { id: 7, slug: 'location-1' },
                    staffRestaurants: [{ id: 7, name: 'Workplace' }],
                    notifications: [
                        {
                            reservation_id: 12,
                            kind: 'group_submitted',
                            revision: 1,
                        },
                    ],
                },
            );
            expect(result?.body).toContain(
                'href="/staff/restaurants/7/reservations"',
            );
            expect(result?.body).toContain('href="/staff/restaurants/7/menu"');
            expect(result?.body).not.toContain('Book a Table');
            expect(result?.body).not.toContain('Main page');
            expect(result?.body).not.toContain('href="/reservations"');
            expect(result?.body).not.toContain('/restaurants/location-1');
            if (component === 'staff/Reservations') {
                expect(result?.body).toContain(
                    'aria-label="Restaurant notifications"',
                );
                expect(result?.body).toContain('Group pre-order submitted');
            } else {
                expect(result?.body).not.toContain(
                    'aria-label="Restaurant notifications"',
                );
            }
            if (component === 'staff/Menu')
                expect(result?.body).toMatch(
                    /<a(?=[^>]*href="\/staff\/restaurants\/7\/menu")(?=[^>]*aria-current="page")[^>]*>\s*Menu\s*<\/a>/,
                );
        }
    });
    it('does not make invalid links when all staff workplaces have been revoked', async () => {
        const result = await renderHeader(
            { user: { id: 3 }, isStaff: true },
            'staff/Access',
            { staffRestaurants: [] },
        );
        expect(result?.body).toContain('href="/staff"');
        expect(result?.body).not.toContain('undefined');
        expect(result?.body).not.toContain('Book a Table');
    });
    it('highlights Main page for a restaurant profile', async () => {
        const result = await renderHeader(undefined, 'restaurants/Show');
        expect(result?.body).toMatch(
            /<a(?=[^>]*href="\/")(?=[^>]*aria-current="page")[^>]*>\s*Main page\s*<\/a>/,
        );
    });
    it('renders public navigation when auth shared props are absent', async () => {
        const result = await renderHeader();
        expect(result?.body).toContain('Sign In');
        expect(result?.body).not.toContain('href="/staff"');
        expect(result?.body).not.toContain('Staff area');
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
        expect(result?.body).not.toContain('href="/staff"');
        expect(result?.body).not.toContain('Sign In');
    });

    it('keeps staff sign-in on public navigation without a staff link in the header', async () => {
        const result = await renderHeader({ user: null }, 'staff/Access');
        expect(result?.body).not.toContain('href="/staff"');
        expect(result?.body).not.toContain('Staff area');
        expect(result?.body).not.toContain('/staff/restaurants/undefined');
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
