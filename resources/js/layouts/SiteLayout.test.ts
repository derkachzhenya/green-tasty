import { afterEach, expect, it, vi } from 'vite-plus/test';
import { nextTick, reactive } from 'vue';
import {
    findAll,
    fire,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import SiteLayout from './SiteLayout.vue';
const state = vi.hoisted(() => ({
    page: {} as { flash?: Record<string, unknown> },
}));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => state.page }));
vi.mock('@/components/site/SiteHeader.vue', () => ({
    default: { render: () => null },
}));
let mounted: ReturnType<typeof mountComponent>;
afterEach(() => {
    mounted?.unmount();
});
it('announces a server-confirmed login once and lets the user dismiss it', async () => {
    state.page = reactive({ flash: {} });
    mounted = mountComponent(SiteLayout, {});
    expect(textContent(mounted.root)).not.toContain('signed in successfully');
    state.page.flash!.authNotification = 'You have signed in successfully.';
    await nextTick();
    expect(textContent(mounted.root)).toContain(
        'You have signed in successfully.',
    );
    const dismiss = findAll(
        mounted.root,
        (node) => node.props['aria-label'] === 'Dismiss notification',
    )[0];
    await fire(dismiss, 'onClick');
    expect(textContent(mounted.root)).not.toContain('signed in successfully');
    state.page.flash!.authNotification = { unexpected: 'server data' };
    await nextTick();
    expect(textContent(mounted.root)).not.toContain('server data');
});
