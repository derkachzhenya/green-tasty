import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { defineComponent, h } from 'vue';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import Login from './Login.vue';

const navigation = vi.hoisted(() => ({
    visit: vi.fn(),
    clearHistory: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({
    router: navigation,
    Head: { render: () => null },
    Link: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('a', attrs, slots.default?.()),
    }),
}));
vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('button', attrs, slots.default?.()),
    }),
}));
vi.mock('@/components/auth/StaffRegistrationModal.vue', () => ({
    default: defineComponent({
        props: ['enabled', 'restaurant'],
        emits: ['close'],
        setup:
            (props, { emit }) =>
            () =>
                h('article', {}, [
                    String(props.restaurant.address),
                    h(
                        'button',
                        { onClick: () => emit('close') },
                        'Close registration',
                    ),
                ]),
    }),
}));

let mounted: ReturnType<typeof mountComponent>;
let request: ReturnType<typeof vi.fn>;
function reply(body: unknown, status = 200, retry = '') {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', 'Retry-After': retry },
    });
}
function input(field: string) {
    return findAll(
        mounted.root,
        (n) => n.tag === 'input' && n.props.id === `staff-${field}`,
    )[0];
}
async function fill(field: string, value: string) {
    await fire(input(field), 'onUpdate:modelValue', value);
}
async function submit() {
    await fire(findAll(mounted.root, (n) => n.tag === 'form')[0], 'onSubmit');
    await flush();
}
function submitButton() {
    return findAll(
        mounted.root,
        (n) => n.tag === 'button' && n.props.type === 'submit',
    )[0];
}

beforeEach(() => {
    vi.stubGlobal(
        'window',
        Object.assign(new EventTarget(), {
            location: { origin: 'https://green-tasty.test', reload: vi.fn() },
        }),
    );
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=encrypted%2Btoken',
        getElementById: vi.fn(),
    });
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    request = vi
        .fn()
        .mockResolvedValue(
            reply({ next: 'authenticated', redirect: '/staff/enter' }),
        );
    vi.stubGlobal('fetch', request);
    mounted = mountComponent(Login, {
        registration: {
            enabled: true,
            restaurant: { name: 'Green Tasty', address: '48 Rustaveli Avenue' },
        },
    });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('separate staff sign-in', () => {
    it('opens staff registration locally and clears the sign-in password', async () => {
        await fill('password', 'PrivateStaffPassword');
        const link = findAll(
            mounted.root,
            (n) =>
                n.tag === 'button' &&
                textContent(n).trim() === 'Create a staff account',
        )[0];
        await fire(link, 'onClick');
        expect(textContent(mounted.root)).toContain('Close registration');
        expect(textContent(mounted.root)).toContain('48 Rustaveli Avenue');
        expect(input('password').value).toBe('');
        expect(navigation.visit).not.toHaveBeenCalled();
        expect(request).not.toHaveBeenCalled();
        await fire(
            findAll(
                mounted.root,
                (n) =>
                    n.tag === 'button' &&
                    textContent(n) === 'Close registration',
            )[0],
            'onClick',
        );
        expect(textContent(mounted.root)).not.toContain('Close registration');
    });
    it('offers visitor navigation and only submits staff credentials with CSRF protection', async () => {
        expect(submitButton().props.disabled).toBe(true);
        expect(textContent(mounted.root)).toContain('Visitor sign in');
        expect(textContent(mounted.root)).not.toContain('Create an Account');
        await fill('email', 'staff@example.test');
        await fill('password', 'PrivateStaffPassword');
        expect(submitButton().props.disabled).toBe(false);
        await submit();
        expect(request.mock.calls[0][0]).toBe('/staff/login');
        expect(request.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBe(
            'encrypted+token',
        );
        expect(JSON.parse(request.mock.calls[0][1].body)).toEqual({
            email: 'staff@example.test',
            password: 'PrivateStaffPassword',
        });
        expect(input('password').value).toBe('');
        expect(navigation.clearHistory).toHaveBeenCalledOnce();
        expect(navigation.visit).toHaveBeenCalledWith('/staff/enter', {
            replace: true,
        });
    });

    it('shows denied credentials without navigating and clears the password', async () => {
        request.mockResolvedValue(
            reply(
                {
                    errors: {
                        email: [
                            'These credentials do not match an active staff account.',
                        ],
                    },
                },
                422,
            ),
        );
        await fill('email', 'customer@example.test');
        await fill('password', 'CustomerPassword');
        await submit();
        expect(textContent(mounted.root)).toContain(
            'These credentials do not match an active staff account.',
        );
        expect(input('password').value).toBe('');
        expect(navigation.visit).not.toHaveBeenCalled();
    });

    it('respects the server lockout and prevents resubmission during its countdown', async () => {
        request.mockResolvedValue(
            reply({ reason: 'login-lockout' }, 429, '300'),
        );
        await fill('email', 'staff@example.test');
        await fill('password', 'WrongPassword');
        await submit();
        expect(textContent(mounted.root)).toContain(
            'Too many sign-in attempts',
        );
        await fill('password', 'AnotherPassword');
        expect(submitButton().props.disabled).toBe(true);
        await submit();
        expect(request).toHaveBeenCalledOnce();
    });
});
