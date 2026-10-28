import { renderToString } from '@vue/server-renderer';
import { describe, expect, it, vi } from 'vite-plus/test';
import { createSSRApp, defineComponent, h } from 'vue';
import UserMenuContent from './UserMenuContent.vue';
const state = vi.hoisted(() => ({ props: { auth: { isStaff: false } } }));
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => state,
    router: { clearHistory: vi.fn() },
    Link: defineComponent({
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
}));
vi.mock('@/components/UserInfo.vue', () => ({
    default: { render: () => null },
}));
vi.mock('@/components/ui/dropdown-menu', () => {
    const box = defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                h('div', {}, slots.default?.()),
    });
    return {
        DropdownMenuGroup: box,
        DropdownMenuItem: box,
        DropdownMenuLabel: box,
        DropdownMenuSeparator: box,
    };
});
const user = {
    id: 1,
    name: 'Person',
    email: 'person@example.test',
    email_verified_at: null,
    created_at: '',
    updated_at: '',
};
describe('account menu role separation', () => {
    it('offers staff workspace instead of a customer dashboard for personnel', async () => {
        state.props.auth.isStaff = true;
        const html = await renderToString(
            createSSRApp(UserMenuContent, { user }),
        );
        expect(html).toContain('Staff workspace');
        expect(html).toContain('href="/staff/enter"');
        expect(html).not.toContain('href="/dashboard"');
        expect(html).not.toContain('Staff area');
        expect(html).toContain('Log out');
    });
    it('keeps customer dashboard and account settings for visitors', async () => {
        state.props.auth.isStaff = false;
        const html = await renderToString(
            createSSRApp(UserMenuContent, { user }),
        );
        expect(html).toContain('Dashboard');
        expect(html).toContain('href="/dashboard"');
        expect(html).toContain('Account settings');
    });
});
