import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { computed, defineComponent, h, shallowRef } from 'vue';
import { mountComponent } from '@/test-support/renderComponent';
import { usePreOrderClock, type PreOrderTiming } from './usePreOrderClock';
afterEach(() => vi.useRealTimers());
describe('server-based pre-order clock', () => {
    it('enters the warning and hard lock at exact deadlines independently of the client wall clock', () => {
        vi.useFakeTimers({
            toFake: ['setInterval', 'clearInterval', 'performance'],
        });
        const source = shallowRef<PreOrderTiming>({
            server_now: '2026-10-31T19:29:59Z',
            warning_at: '2026-10-31T19:30:00Z',
            locks_at: '2026-10-31T19:45:00Z',
            locked: false,
            unconfirmed: true,
        });
        let clock!: ReturnType<typeof usePreOrderClock>;
        const mounted = mountComponent(
            defineComponent({
                setup() {
                    clock = usePreOrderClock(computed(() => source.value));
                    return () => h('div');
                },
            }),
            {},
        );
        expect(clock.warning.value).toBe(false);
        vi.advanceTimersByTime(1000);
        expect(clock.warning.value).toBe(true);
        expect(clock.minutes.value).toBe(15);
        vi.advanceTimersByTime(899000);
        expect(clock.cutoffLocked.value).toBe(false);
        vi.advanceTimersByTime(1000);
        expect(clock.cutoffLocked.value).toBe(true);
        expect(clock.warning.value).toBe(false);
        mounted.unmount();
        expect(vi.getTimerCount()).toBe(0);
    });
    it('does not invent warnings without persisted unsubmitted selections', () => {
        vi.useFakeTimers();
        let clock!: ReturnType<typeof usePreOrderClock>;
        const mounted = mountComponent(
            defineComponent({
                setup() {
                    clock = usePreOrderClock(
                        computed(() => ({
                            server_now: '2026-10-31T19:40:00Z',
                            warning_at: '2026-10-31T19:30:00Z',
                            locks_at: '2026-10-31T19:45:00Z',
                            locked: false,
                            unconfirmed: false,
                        })),
                    );
                    return () => h('div');
                },
            }),
            {},
        );
        expect(clock.warning.value).toBe(false);
        expect(clock.cutoffLocked.value).toBe(false);
        mounted.unmount();
    });
});
