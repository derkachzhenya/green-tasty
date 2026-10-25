import { renderToString } from '@vue/server-renderer';
import { describe, expect, it } from 'vite-plus/test';
import { createSSRApp } from 'vue';
import Menu from './Menu.vue';

describe('guest shell server rendering', () => {
    it('renders its loading shell without window, invitation props or account context', async () => {
        const html = await renderToString(createSSRApp(Menu));
        expect(html).toContain('Loading your menu');
        expect(html).not.toContain('undefined');
    });
    it('renders an invalid shell without protected reservation details', async () => {
        const html = await renderToString(
            createSSRApp(Menu, { initiallyInvalid: true }),
        );
        expect(html).toContain('Invitation unavailable');
        expect(html).not.toContain('Your items');
    });
});
