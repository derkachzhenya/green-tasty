import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { h, type VNodeChild } from 'vue';
import SiteHeader from '@/components/site/SiteHeader.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
    type TestNode,
} from '@/test-support/renderComponent';
import type { MyReservationsPage, OwnedReservation } from '@/types/reservation';
import Dashboard from './Dashboard.vue';

const navigation = vi.hoisted(() => ({
    reload: vi.fn(),
    clearHistory: vi.fn(),
    setDeferred: (_value: 'loading' | 'loaded' | 'error') => {},
    setUser: (_value: boolean) => {},
}));
vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h, reactive, ref } = await import('vue');
    const mode = ref('loaded');
    const page = reactive({
        component: 'Dashboard',
        props: {
            auth: {
                user: { name: 'Journey Owner' } as { name: string } | null,
            },
        },
    });
    navigation.setDeferred = (value) => {
        mode.value = value;
    };
    navigation.setUser = (value) => {
        page.props.auth.user = value ? { name: 'Journey Owner' } : null;
    };
    return {
        usePage: () => page,
        router: {
            reload: navigation.reload,
            clearHistory: navigation.clearHistory,
        },
        Head: { render: () => null },
        Link: defineComponent({
            inheritAttrs: false,
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
        Deferred: defineComponent({
            props: ['data'],
            setup:
                (_props, { slots }) =>
                () =>
                    mode.value === 'loading'
                        ? slots.fallback?.()
                        : mode.value === 'error'
                          ? slots.rescue?.()
                          : slots.default?.(),
        }),
    };
});
vi.mock('@/components/ui/button', () => ({
    Button: {
        setup:
            (
                _props: unknown,
                { slots }: { slots: { default: () => VNodeChild } },
            ) =>
            () =>
                h('button', {}, [slots.default()]),
    },
}));

vi.mock('@/components/UserInfo.vue', () => ({
    default: { render: () => null },
}));
vi.mock('@/components/ui/dropdown-menu', () => {
    const box = {
        setup:
            (
                _props: unknown,
                { slots }: { slots: { default?: () => VNodeChild } },
            ) =>
            () =>
                h('div', {}, [slots.default?.()]),
    };
    return {
        DropdownMenuGroup: box,
        DropdownMenuItem: box,
        DropdownMenuLabel: box,
        DropdownMenuSeparator: box,
    };
});

let mounted: ReturnType<typeof mountComponent>;
function reservation(
    overrides: Partial<OwnedReservation> = {},
): OwnedReservation {
    return {
        id: 17,
        code: 'GT-TEST',
        status: 'confirmed',
        guest_count: 3,
        restaurant: {
            name: 'Green Tasty Garden',
            slug: 'garden',
            address: 'Garden address',
        },
        table: null,
        date: '2026-10-24',
        time: '12:00',
        end_date: '2026-10-24',
        end_time: '14:00',
        timezone: 'Asia/Tbilisi',
        pre_order_status: null,
        ...overrides,
    };
}
function result(
    overrides: Partial<MyReservationsPage> = {},
): MyReservationsPage {
    return {
        data: [reservation()],
        current_page: 1,
        last_page: 1,
        per_page: 6,
        total: 1,
        from: 1,
        to: 1,
        prev_page_url: null,
        next_page_url: null,
        ...overrides,
    };
}
function text() {
    return textContent(mounted.root);
}
function links() {
    return findAll(mounted.root, (node) => node.tag === 'a');
}
function link(label: string): TestNode {
    const target = links().find(
        (node) =>
            node.props['aria-label'] === label ||
            textContent(node).trim() === label,
    );
    if (!target) throw new Error(`Missing link: ${label}`);
    return target;
}
beforeEach(() => {
    navigation.setDeferred('loaded');
    navigation.setUser(true);
    navigation.reload
        .mockReset()
        .mockImplementation((options) => options.onStart?.());
    mounted = mountComponent(Dashboard, { reservations: result() });
});
afterEach(() => {
    mounted.unmount();
    vi.clearAllMocks();
});

describe('My reservations', () => {
    it('replaces the starter placeholder with real reservation information and navigation', () => {
        expect(text()).toContain('My reservations');
        expect(text()).toContain('Hello, Journey Owner');
        expect(text()).toContain('Green Tasty Garden');
        expect(text()).toContain('Garden address');
        expect(text()).toContain('2026-10-24');
        expect(text()).toContain('12:00 – 14:00');
        expect(text()).toContain('Asia/Tbilisi');
        expect(text()).toContain('3 Guests');
        expect(text()).toContain('Pre-orders: 0/3');
        expect(link('Upcoming').props.href).toBe('/dashboard?status=upcoming');
        expect(
            link(
                'View reservation at Green Tasty Garden on 2026-10-24 at 12:00',
            ).props.href,
        ).toBe('/reservations/17');
        expect(
            links().some((node) =>
                String(node.props.href).includes('github.com'),
            ),
        ).toBe(false);
    });
    it.each([
        [
            'upcoming',
            'Reserved',
            {
                can_add_pre_order: true,
                can_invite: true,
                submitted_pre_orders: 1,
            },
            ['Add Pre-Order', 'Invite Guests', 'Pre-orders: 1/3'],
        ],
        [
            'upcoming',
            'Reserved',
            { can_view_group: true, submitted_pre_orders: 3 },
            ['Group Pre-Order', 'Pre-orders: 3/3'],
        ],
        [
            'in_progress',
            'In Progress',
            { feedback_action: 'leave' },
            ['Leave Feedback'],
        ],
        [
            'completed',
            'Completed',
            { feedback_action: 'update' },
            ['Update Feedback'],
        ],
        ['canceled', 'Cancelled', {}, []],
    ] as const)(
        'renders server-authorized %s card actions',
        async (phase, label, extra, actions) => {
            mounted.setProps({
                reservations: result({
                    data: [reservation({ phase, ...extra })],
                }),
            });
            await flush();
            expect(text()).toContain(label);
            for (const action of actions) expect(text()).toContain(action);
            if (phase !== 'upcoming') {
                expect(text()).not.toContain('Invite Guests');
                expect(text()).not.toContain('Add Pre-Order');
                expect(text()).not.toContain('Group Pre-Order');
            }
        },
    );
    it('renders global counters independently of filtered page size and identifies the active filter', async () => {
        mounted.setProps({
            activeStatus: 'completed',
            reservationCounts: {
                all: 12,
                upcoming: 6,
                in_progress: 2,
                completed: 3,
                canceled: 1,
            },
        });
        await flush();
        const completed = links().find(
            (node) => node.props.href === '/dashboard?status=completed',
        )!;
        expect(textContent(completed)).toContain('Completed3');
        expect(completed.props['aria-current']).toBe('page');
        expect(text()).toContain('All12');
    });
    it('shows an empty state with a real location browsing path', async () => {
        mounted.setProps({
            reservations: result({ data: [], total: 0, from: null, to: null }),
        });
        await flush();
        expect(text()).toContain('No Reservations');
        expect(link('Book a Table').props.href).toBe('/book');
        expect(
            findAll(mounted.root, (node) => node.tag === 'img').some((node) =>
                decodeURIComponent(String(node.props.src)).includes(
                    'Empty State - No reservations.png',
                ),
            ),
        ).toBe(true);
        expect(
            links().filter((node) => textContent(node) === 'View reservation'),
        ).toHaveLength(0);
        expect(
            findAll(
                mounted.root,
                (node) => node.props['aria-label'] === 'Reservation pages',
            ),
        ).toHaveLength(0);
    });
    it('distinguishes an out-of-range page from having no bookings', async () => {
        mounted.setProps({
            reservations: result({
                data: [],
                total: 7,
                current_page: 3,
                last_page: 2,
                from: null,
                to: null,
                prev_page_url: '/dashboard?page=2',
            }),
        });
        await flush();
        expect(text()).toContain('No reservations on this page');
        expect(text()).not.toContain('No reservations yet');
        expect(link('Back to first page').props.href).toBe('/dashboard');
    });
    it('shows a loading state before the deferred owner data arrives', async () => {
        navigation.setDeferred('loading');
        mounted.setProps({ reservations: undefined });
        await flush();
        expect(text()).toContain('Loading your reservations…');
        expect(text()).not.toContain('No reservations yet');
        expect(text()).not.toContain('Green Tasty Garden');
    });
    it('offers a retry for rescued loading failures and recovers when data arrives', async () => {
        navigation.setDeferred('error');
        await flush();
        expect(text()).toContain('We couldn’t load your reservations');
        const retry = findAll(
            mounted.root,
            (node) =>
                node.tag === 'button' && textContent(node) === 'Try again',
        )[0];
        await fire(retry, 'onClick');
        expect(navigation.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['reservations'] }),
        );
        expect(text()).toContain('Trying again…');
        const options = navigation.reload.mock.calls[0][0];
        options.onNetworkError();
        options.onFinish();
        await flush();
        expect(text()).toContain('Still unable to connect');
        navigation.setDeferred('loaded');
        await flush();
        expect(text()).toContain('Green Tasty Garden');
        expect(text()).not.toContain('We couldn’t load your reservations');
    });
    it('renders actual server statuses including submitted orders without edit or submit controls', async () => {
        mounted.setProps({
            reservations: result({
                data: [
                    reservation({
                        pre_order_status: 'submitted',
                        status: 'completed',
                    }),
                ],
            }),
        });
        await flush();
        expect(text()).toContain('Completed');
        expect(text()).toContain('Pre-orders: 0/3');
        expect(text()).not.toContain('Confirm submission');
        expect(text()).not.toContain('Invite guests');
        expect(
            findAll(mounted.root, (node) => node.tag === 'form'),
        ).toHaveLength(0);
    });
    it('keeps dates supplied by the backend and labels a midnight-crossing interval', async () => {
        mounted.setProps({
            reservations: result({
                data: [
                    reservation({
                        guest_count: 1,
                        time: '23:30',
                        end_time: '01:30',
                        end_date: '2026-10-25',
                    }),
                ],
            }),
        });
        await flush();
        expect(text()).toContain('23:30 – 01:30(2026-10-25)');
        expect(text()).toContain('1 Guest');
        expect(text()).not.toContain('1 Guests');
    });
    it('renders untrusted names as text, with unique headings for multiple cards', async () => {
        mounted.setProps({
            reservations: result({
                data: [
                    reservation({
                        restaurant: {
                            name: '<script>bad()</script>',
                            slug: 'garden',
                            address: 'Address',
                        },
                    }),
                    reservation({ id: 18 }),
                ],
            }),
        });
        await flush();
        expect(
            findAll(mounted.root, (node) => node.tag === 'script'),
        ).toHaveLength(0);
        expect(text()).toContain('<script>bad()</script>');
        const ids = findAll(mounted.root, (node) => node.tag === 'h2').map(
            (node) => node.props.id,
        );
        expect(new Set(ids).size).toBe(ids.length);
    });
});

describe('reservation pagination', () => {
    it('uses server page links and removes unavailable links from keyboard navigation', async () => {
        mounted.setProps({
            reservations: result({
                last_page: 2,
                total: 7,
                next_page_url: '/dashboard?page=2',
            }),
        });
        await flush();
        expect(text()).toContain('Page 1 of 2');
        expect(link('Next reservations page').props.href).toBe(
            '/dashboard?page=2',
        );
        expect(links().some((node) => textContent(node) === 'Previous')).toBe(
            false,
        );
        mounted.setProps({
            reservations: result({
                current_page: 2,
                last_page: 2,
                total: 7,
                prev_page_url: '/dashboard?page=1',
            }),
        });
        await flush();
        expect(link('Previous reservations page').props.href).toBe(
            '/dashboard?page=1',
        );
        expect(links().some((node) => textContent(node) === 'Next')).toBe(
            false,
        );
    });
    it('announces navigation and guards repeated requests until completion', async () => {
        mounted.setProps({
            reservations: result({
                last_page: 2,
                total: 7,
                next_page_url: '/dashboard?page=2',
            }),
        });
        await flush();
        await fire(link('Next reservations page'), 'onStart');
        expect(text()).toContain('Loading reservations…');
        const next = link('Next reservations page');
        expect(next.props['aria-disabled']).toBe(true);
        expect((next.props.onBefore as () => boolean)()).toBe(false);
        await fire(next, 'onFinish');
        expect(
            (link('Next reservations page').props.onBefore as () => boolean)(),
        ).toBe(true);
    });
    it('keeps the current page visible and permits retry after a network failure', async () => {
        mounted.setProps({
            reservations: result({
                last_page: 2,
                total: 7,
                next_page_url: '/dashboard?page=2',
            }),
        });
        await flush();
        await fire(link('Next reservations page'), 'onStart');
        const next = link('Next reservations page');
        expect((next.props.onNetworkError as () => boolean)()).toBe(false);
        await fire(next, 'onFinish');
        expect(text()).toContain(
            'We couldn’t load that page. Please try again.',
        );
        expect(text()).toContain('Green Tasty Garden');
        await fire(link('Next reservations page'), 'onStart');
        expect(text()).not.toContain('We couldn’t load that page');
    });
});

describe('site reservation navigation', () => {
    it('invalidates encrypted history and private prefetch data through the existing logout control', async () => {
        mounted.unmount();
        mounted = mountComponent(UserMenuContent, {
            user: {
                id: 1,
                name: 'Owner',
                email: 'owner@example.test',
                email_verified_at: null,
                created_at: '',
                updated_at: '',
            },
        });
        const logout = findAll(
            mounted.root,
            (node) => node.props['data-test'] === 'logout-button',
        )[0];
        expect(logout.props.href).toBe('/logout');
        await fire(logout, 'onClick');
        expect(navigation.clearHistory).toHaveBeenCalledOnce();
    });
    it('links authenticated owners to My reservations and marks the current page', () => {
        mounted.unmount();
        mounted = mountComponent(SiteHeader, {});
        expect(link('Reservations').props.href).toBe('/dashboard');
        expect(link('Reservations').props['aria-current']).toBe('page');
        expect(link('Book a Table').props.href).toBe('/book');
    });
    it('keeps the sign-in path for unauthenticated visitors', () => {
        mounted.unmount();
        navigation.setUser(false);
        mounted = mountComponent(SiteHeader, {});
        expect(link('Sign In').props.href).toBe('/login');
        expect(text()).not.toContain('My reservations');
    });
});
