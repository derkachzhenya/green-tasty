import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { findAll, fire, mountComponent } from '@/test-support/renderComponent';
import BookingModal from './BookingModal.vue';

// Real portal/focus behaviour is verified in Chrome; exercise the explicit
// Escape fallback without relying on the dialog library's document listener.
vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h } = await import('vue');
    const box = (tag: string) =>
        defineComponent({
            inheritAttrs: false,
            setup:
                (_props, { attrs, slots }) =>
                () =>
                    h(tag, attrs, slots.default?.()),
        });
    return {
        Dialog: box('section'),
        DialogContent: box('article'),
        DialogTitle: box('h2'),
        DialogDescription: box('p'),
    };
});

let mounted: ReturnType<typeof mountComponent>;
afterEach(() => mounted?.unmount());

function mount(busy: boolean) {
    const update = vi.fn();
    mounted = mountComponent(BookingModal, {
        open: true,
        busy,
        title: 'Review & Confirm Reservation',
        description: 'Review the selected table.',
        'onUpdate:open': update,
    });
    return {
        update,
        content: findAll(mounted.root, (node) => node.tag === 'article')[0],
    };
}
function key(value = 'Escape') {
    return { key: value, preventDefault: vi.fn(), stopPropagation: vi.fn() };
}

describe('booking modal keyboard dismissal', () => {
    it('returns focus to the persistent cart after its submit button has unmounted', async () => {
        const focus = vi.fn();
        const preventDefault = vi.fn();
        mounted = mountComponent(BookingModal, {
            open: true,
            title: 'Pre-Order Submitted',
            description: 'The owner selection was saved.',
            returnFocusTarget: {
                isConnected: true,
                focus,
            } as unknown as HTMLElement,
        });
        const content = findAll(
            mounted.root,
            (node) => node.tag === 'article',
        )[0];
        await fire(content, 'onCloseAutoFocus', { preventDefault });
        expect(preventDefault).toHaveBeenCalledOnce();
        expect(focus).toHaveBeenCalledOnce();
    });
    it.each(['onKeydown', 'onEscapeKeyDown'])(
        'closes an idle modal through %s',
        async (handler) => {
            const { update, content } = mount(false);
            await fire(content, handler, key());
            expect(update).toHaveBeenCalledWith(false);
        },
    );
    it.each(['onKeydown', 'onEscapeKeyDown'])(
        'keeps a pending operation open through %s',
        async (handler) => {
            const { update, content } = mount(true);
            await fire(content, handler, key());
            expect(update).not.toHaveBeenCalled();
        },
    );
    it('does not dismiss on an unrelated key', async () => {
        const { update, content } = mount(false);
        await fire(content, 'onKeydown', key('Enter'));
        expect(update).not.toHaveBeenCalled();
    });
});
