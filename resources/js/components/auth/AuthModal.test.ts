import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { h, nextTick, type VNodeChild } from 'vue';
import {
    findAll,
    fire,
    flush,
    mountComponent,
    textContent,
} from '@/test-support/renderComponent';
import AuthModal from './AuthModal.vue';
import AuthField from './AuthField.vue';

const navigation = vi.hoisted(() => ({
    visit: vi.fn(),
    clearHistory: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({ router: navigation }));
vi.mock('@/components/ui/button', () => ({
    Button: {
        setup:
            (_: unknown, { slots }: { slots: { default: () => VNodeChild } }) =>
            () =>
                h('button', {}, [slots.default()]),
    },
}));
vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h } = await import('vue');
    const box = (tag: string) =>
        defineComponent({
            setup:
                (_, { slots }) =>
                () =>
                    h(tag, {}, slots.default?.()),
        });
    return {
        Dialog: box('section'),
        DialogContent: box('article'),
        DialogTitle: box('h2'),
        DialogDescription: box('p'),
    };
});
let mounted: ReturnType<typeof mountComponent>;
let fetchMock: ReturnType<typeof vi.fn>;
let pageWindow: EventTarget;
function response(value: unknown, status = 200, retry = '') {
    return new Response(JSON.stringify(value), {
        status,
        headers: { 'Content-Type': 'application/json', 'Retry-After': retry },
    });
}
const challenge = {
    purpose: 'registration',
    email: 'test@example.com',
    resend_after: 60,
    verified: false,
};
function mount(initialStep = 'login', context: unknown = null) {
    mounted = mountComponent(AuthModal, { initialStep, challenge: context });
}
function button(label: string) {
    const node = findAll(
        mounted.root,
        (n) => n.tag === 'button' && textContent(n).trim() === label,
    )[0];
    if (!node) throw new Error(`Missing ${label}`);
    return node;
}
function input(field: string) {
    return findAll(
        mounted.root,
        (n) => n.tag === 'input' && n.props.id === `auth-${field}`,
    )[0];
}
async function fill(field: string, value: string) {
    const target = input(field);
    await fire(target, 'onKeydown');
    target.value = value;
    await fire(target, 'onUpdate:modelValue', value);
    // The minimal renderer intentionally doesn't implement browser DOM event dispatch.
    const fieldNode = findAll(
        mounted.root,
        (n) =>
            n.props.id === `auth-${field}` && n.props.modelValue !== undefined,
    )[0];
    if (fieldNode) await fire(fieldNode, 'onUpdate:modelValue', value);
    const directive = target.props['onUpdate:modelValue'];
    expect(directive).toBeDefined();
}
async function submit() {
    await fire(findAll(mounted.root, (n) => n.tag === 'form')[0], 'onSubmit');
    await flush();
}
async function loginFields() {
    await fill('email', 'test@example.com');
    await fill('password', 'existing password');
}
async function registerFields() {
    await fill('first_name', 'Test');
    await fill('last_name', "O'Brien");
    await fill('email', 'test@example.com');
    await fill('password', 'Secure9!Pass');
    await fill('password_confirmation', 'Secure9!Pass');
}
beforeEach(() => {
    pageWindow = new EventTarget();
    Object.assign(pageWindow, {
        location: { origin: 'https://green-tasty.test', reload: vi.fn() },
    });
    vi.stubGlobal('window', pageWindow);
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=encrypted%2Bcookie%3D',
        getElementById: vi.fn(),
        querySelector: vi.fn(),
    });
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    fetchMock = vi
        .fn()
        .mockResolvedValue(response({ next: 'verify', challenge }));
    vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
    mounted?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('authentication modal flows', () => {
    it('keeps empty sign in disabled and shows required errors on blur', async () => {
        mount();
        expect(button('Sign In').props.disabled).toBe(true);
        await fire(input('email'), 'onKeydown');
        await fire(input('password'), 'onKeydown');
        await fire(input('email'), 'onBlur');
        await fire(input('password'), 'onBlur');
        expect(textContent(mounted.root)).toContain(
            'Email address is required',
        );
        expect(textContent(mounted.root)).toContain('Password is required');
        expect(fetchMock).not.toHaveBeenCalled();
    });
    it('switches locally between login registration and recovery and wipes passwords', async () => {
        mount();
        await loginFields();
        expect(input('password').value).toBe('existing password');
        await fire(button('Create an Account'), 'onClick');
        expect(textContent(mounted.root)).toContain('First Name');
        expect(input('password').value).toBe('');
        await fire(button('Sign In'), 'onClick');
        await fire(button('Forgot password?'), 'onClick');
        expect(textContent(mounted.root)).toContain('Request Code');
        await fire(button('Back'), 'onClick');
        expect(textContent(mounted.root)).toContain('Sign In to Your Account');
        expect(navigation.visit).not.toHaveBeenCalled();
    });
    it('validates names and matching strong passwords and submits only account fields', async () => {
        mount('register');
        await registerFields();
        expect(button('Create an Account').props.disabled).toBe(false);
        await fill('first_name', 'Bad@');
        await fire(input('first_name'), 'onBlur');
        expect(button('Create an Account').props.disabled).toBe(true);
        expect(textContent(mounted.root)).toContain('Use letters only');
        await fill('first_name', 'Test');
        await fill('password_confirmation', 'different');
        await fire(input('password_confirmation'), 'onBlur');
        expect(textContent(mounted.root)).toContain('must match');
        await fill('password_confirmation', 'Secure9!Pass');
        await submit();
        const options = fetchMock.mock.calls[0][1];
        expect(fetchMock.mock.calls[0][0]).toBe('/register');
        expect(options.headers['X-XSRF-TOKEN']).toBe('encrypted+cookie=');
        expect(JSON.parse(options.body)).toEqual({
            first_name: 'Test',
            last_name: "O'Brien",
            email: 'test@example.com',
            password: 'Secure9!Pass',
            password_confirmation: 'Secure9!Pass',
        });
        expect(textContent(mounted.root)).toContain('Enter Verification Code');
        expect(textContent(mounted.root)).toContain('60 seconds');
    });
    it('verifies the purpose-bound code then returns to sign in with a success notification', async () => {
        mount('verify', challenge);
        fetchMock.mockResolvedValue(response({ next: 'login' }));
        await fill('code', 'actual-email-code');
        await submit();
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            purpose: 'registration',
            code: 'actual-email-code',
        });
        expect(textContent(mounted.root)).toContain(
            'account was created successfully',
        );
        expect(textContent(mounted.root)).toContain('Sign In to Your Account');
    });
    it('respects the resend countdown and replaces expired-code errors on a new request', async () => {
        vi.useFakeTimers();
        mount('verify', challenge);
        expect(
            findAll(
                mounted.root,
                (n) =>
                    n.tag === 'button' &&
                    textContent(n).trim() === 'Resend Code',
            ),
        ).toHaveLength(0);
        await vi.advanceTimersByTimeAsync(60000);
        await nextTick();
        await fire(button('Resend Code'), 'onClick');
        expect(fetchMock.mock.calls[0][0]).toBe('/auth/code/resend');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            purpose: 'registration',
        });
        expect(textContent(mounted.root)).toContain('60 seconds');
    });
    it('shows incorrect credentials and a five-minute lockout supplied by the server', async () => {
        mount();
        await loginFields();
        fetchMock.mockResolvedValueOnce(
            response(
                {
                    errors: {
                        email: ['These credentials do not match our records.'],
                    },
                },
                422,
            ),
        );
        await submit();
        expect(textContent(mounted.root)).toContain(
            'Incorrect email or password',
        );
        fetchMock.mockResolvedValueOnce(
            response({ reason: 'login-lockout' }, 429, '300'),
        );
        await submit();
        expect(textContent(mounted.root)).toContain('try again in 5 minutes');
        expect(button('Sign In').props.disabled).toBe(true);
    });
    it('prevents duplicate requests and recovers from a network failure', async () => {
        mount();
        await loginFields();
        let reject!: (error: Error) => void;
        fetchMock.mockImplementation(
            () =>
                new Promise((_resolve, fail) => {
                    reject = fail;
                }),
        );
        void fire(
            findAll(mounted.root, (n) => n.tag === 'form')[0],
            'onSubmit',
        );
        await nextTick();
        void fire(
            findAll(mounted.root, (n) => n.tag === 'form')[0],
            'onSubmit',
        );
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(button('Please wait…').props.disabled).toBe(true);
        reject(new Error('offline'));
        await flush();
        expect(textContent(mounted.root)).toContain('Check your connection');
        expect(button('Sign In').props.disabled).toBe(false);
    });
    it('shows invalid or expired codes and never navigates on failure', async () => {
        mount('verify', challenge);
        await fill('code', 'expired');
        fetchMock.mockResolvedValue(
            response(
                {
                    errors: {
                        code: [
                            'The verification code is invalid or has expired.',
                        ],
                    },
                },
                422,
            ),
        );
        await submit();
        expect(textContent(mounted.root)).toContain('invalid or has expired');
        expect(navigation.visit).not.toHaveBeenCalled();
    });
    it('completes recovery using only passwords after the server verifies the recovery code', async () => {
        mount('forgot');
        await fill('email', 'test@example.com');
        fetchMock.mockResolvedValueOnce(
            response({
                next: 'verify',
                challenge: { ...challenge, purpose: 'recovery' },
            }),
        );
        await submit();
        expect(textContent(mounted.root)).toContain('If an account exists');
        fetchMock.mockResolvedValueOnce(response({ next: 'reset' }));
        await fill('code', 'mail-code');
        await submit();
        await fill('password', 'NewSecure9!Pass');
        await fill('password_confirmation', 'NewSecure9!Pass');
        fetchMock.mockResolvedValueOnce(response({ next: 'login' }));
        await submit();
        expect(JSON.parse(fetchMock.mock.calls[2][1].body)).toEqual({
            password: 'NewSecure9!Pass',
            password_confirmation: 'NewSecure9!Pass',
        });
        expect(textContent(mounted.root)).toContain(
            'password was changed successfully',
        );
    });
    it('uses the session-bound pending-account update after Back instead of creating duplicates', async () => {
        mount('verify', { ...challenge, resend_after: 0 });
        await fire(button('Back'), 'onClick');
        await registerFields();
        await submit();
        expect(fetchMock.mock.calls[0][0]).toBe('/auth/registration');
        expect(fetchMock.mock.calls[0][1].method).toBe('PATCH');
    });
    it('accepts only same-origin authenticated redirects and clears private history', async () => {
        mount();
        await loginFields();
        fetchMock.mockResolvedValueOnce(
            response({
                next: 'authenticated',
                redirect: 'https://other.test/private',
            }),
        );
        await submit();
        expect(navigation.visit).not.toHaveBeenCalled();
        fetchMock.mockResolvedValueOnce(
            response({
                next: 'authenticated',
                redirect: 'https://green-tasty.test/dashboard',
            }),
        );
        await submit();
        expect(navigation.clearHistory).toHaveBeenCalledOnce();
        expect(navigation.visit).toHaveBeenCalledWith('/dashboard', {
            replace: true,
        });
    });
    it('reports expired sessions and aborts in-flight requests on page hide', async () => {
        mount();
        await loginFields();
        fetchMock.mockResolvedValueOnce(response({}, 419));
        await submit();
        expect(textContent(mounted.root)).toContain(
            'session has changed or expired',
        );
        fetchMock.mockImplementation(() => new Promise(() => {}));
        void fire(
            findAll(mounted.root, (n) => n.tag === 'form')[0],
            'onSubmit',
        );
        await nextTick();
        const signal = fetchMock.mock.calls[1][1].signal as AbortSignal;
        pageWindow.dispatchEvent(new Event('pagehide'));
        expect(signal.aborted).toBe(true);
    });
    it('closes with Escape and prevents closing during a pending request', async () => {
        mount();
        const content = findAll(mounted.root, (n) =>
            Boolean(n.props.onKeydown),
        )[0];
        const escape = {
            key: 'Escape',
            stopPropagation() {},
            preventDefault() {},
        };
        await fire(content, 'onKeydown', escape);
        expect(navigation.visit).toHaveBeenCalledWith(
            '/',
            expect.objectContaining({ replace: true }),
        );
        navigation.visit.mockClear();
        await loginFields();
        fetchMock.mockImplementation(() => new Promise(() => {}));
        void fire(
            findAll(mounted.root, (n) => n.tag === 'form')[0],
            'onSubmit',
        );
        await nextTick();
        await fire(content, 'onKeydown', escape);
        expect(navigation.visit).not.toHaveBeenCalled();
    });
    it('requests verification from an authenticated notice only after the explicit POST action', async () => {
        mounted = mountComponent(AuthModal, {
            initialStep: 'verify',
            challenge,
            verificationRequired: true,
        });
        expect(input('code')).toBeUndefined();
        expect(textContent(mounted.root)).toContain(
            'Verify your email to continue',
        );
        expect(fetchMock).not.toHaveBeenCalled();
        await submit();
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/email/verification-notification',
        );
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({});
        expect(input('code')).toBeDefined();
        expect(textContent(mounted.root)).toContain(
            'Verification code has been sent',
        );
    });
    it('shows delivery configuration errors even when the verification screen has no email field', async () => {
        mount('verify', { ...challenge, resend_after: 0 });
        fetchMock.mockResolvedValueOnce(
            response(
                {
                    errors: {
                        email: ['Verification delivery is not configured.'],
                    },
                },
                422,
            ),
        );
        await fire(button('Resend Code'), 'onClick');
        expect(textContent(mounted.root)).toContain(
            'Verification delivery is not configured.',
        );
        expect(
            findAll(mounted.root, (n) => n.props.role === 'alert').length,
        ).toBeGreaterThan(0);
    });
    it('exposes password visibility as an accessible toggle', async () => {
        mounted = mountComponent(AuthField, {
            id: 'password',
            label: 'Password',
            type: 'password',
            modelValue: 'private-value',
        });
        const toggle = findAll(
            mounted.root,
            (n) => n.props['aria-label'] === 'Show password',
        )[0];
        expect(
            findAll(mounted.root, (n) => n.tag === 'input')[0].props.type,
        ).toBe('password');
        await fire(toggle, 'onClick');
        expect(toggle.props['aria-pressed']).toBe(true);
        expect(toggle.props['aria-label']).toBe('Hide password');
        expect(
            findAll(mounted.root, (n) => n.tag === 'input')[0].props.type,
        ).toBe('text');
    });
});
