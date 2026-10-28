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
import StaffRegistrationModal from './StaffRegistrationModal.vue';

const navigation = vi.hoisted(() => ({
    visit: vi.fn(),
    clearHistory: vi.fn(),
}));
vi.mock('@inertiajs/vue3', () => ({ router: navigation }));
vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        setup:
            (_, { attrs, slots }) =>
            () =>
                h('button', attrs, slots.default?.()),
    }),
}));
vi.mock('@/components/ui/dialog', () => {
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
let request: ReturnType<typeof vi.fn>;
let closed: ReturnType<typeof vi.fn>;
const restaurant = { name: 'Green Tasty', address: '48 Rustaveli Avenue' };
function reply(body: unknown, status = 201, retry = '') {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json', 'Retry-After': retry },
    });
}
function input(field: string) {
    return findAll(
        mounted.root,
        (n) => n.tag === 'input' && n.props.id === `staff-register-${field}`,
    )[0];
}
function button(label = 'Create staff account') {
    return findAll(
        mounted.root,
        (n) => n.tag === 'button' && textContent(n).trim() === label,
    )[0];
}
async function fill(field: string, value: string) {
    await fire(input(field), 'onUpdate:modelValue', value);
}
async function fillForm() {
    await fill('first_name', 'New');
    await fill('last_name', 'Waiter');
    await fill('email', 'new.staff@example.test');
    await fill('password', 'Staff9!Pass');
    await fill('password_confirmation', 'Staff9!Pass');
}
async function submit() {
    await fire(findAll(mounted.root, (n) => n.tag === 'form')[0], 'onSubmit');
    await flush();
}
beforeEach(() => {
    vi.stubGlobal(
        'window',
        Object.assign(new EventTarget(), {
            location: { origin: 'https://green-tasty.test' },
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
    closed = vi.fn();
    mounted = mountComponent(StaffRegistrationModal, {
        enabled: true,
        restaurant,
        onClose: closed,
    });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('staff registration modal', () => {
    it('registers through a separate protected endpoint and opens the staff workspace', async () => {
        expect(button().props.disabled).toBe(true);
        expect(textContent(mounted.root)).toContain(restaurant.address);
        await fillForm();
        expect(button().props.disabled).toBe(false);
        await submit();
        expect(request.mock.calls[0][0]).toBe('/staff/register');
        expect(request.mock.calls[0][1].headers['X-XSRF-TOKEN']).toBe(
            'encrypted+token',
        );
        expect(JSON.parse(request.mock.calls[0][1].body)).toEqual({
            first_name: 'New',
            last_name: 'Waiter',
            email: 'new.staff@example.test',
            password: 'Staff9!Pass',
            password_confirmation: 'Staff9!Pass',
        });
        expect(navigation.visit).toHaveBeenCalledWith('/staff/enter', {
            replace: true,
        });
        expect(input('password').value).toBe('');
        expect(input('password_confirmation').value).toBe('');
    });
    it('rejects mismatched passwords locally and shows server field errors without navigation', async () => {
        await fillForm();
        await fill('password_confirmation', 'Other9!Pass');
        expect(button().props.disabled).toBe(true);
        await submit();
        expect(request).not.toHaveBeenCalled();
        await fill('password_confirmation', 'Staff9!Pass');
        request.mockResolvedValue(
            reply(
                { errors: { email: ['The email has already been taken.'] } },
                422,
            ),
        );
        await submit();
        expect(textContent(mounted.root)).toContain(
            'The email has already been taken.',
        );
        expect(navigation.visit).not.toHaveBeenCalled();
        expect(input('password').value).toBe('');
    });
    it('honors lockouts and never submits a second registration while pending', async () => {
        await fillForm();
        let resolve!: (value: Response) => void;
        request.mockReturnValue(
            new Promise<Response>((done) => {
                resolve = done;
            }),
        );
        const first = submit();
        await flush();
        expect(button('Creating account…').props.disabled).toBe(true);
        await submit();
        expect(request).toHaveBeenCalledOnce();
        resolve(reply({}, 429, '60'));
        await first;
        expect(textContent(mounted.root)).toContain('Try again in 60 seconds.');
        await fill('password', 'Staff9!Pass');
        await fill('password_confirmation', 'Staff9!Pass');
        expect(button().props.disabled).toBe(true);
    });
    it('clears secrets on close and ignores a late registration response', async () => {
        await fillForm();
        let resolve!: (value: Response) => void;
        request.mockReturnValue(
            new Promise<Response>((done) => {
                resolve = done;
            }),
        );
        const pending = submit();
        await flush();
        await fire(button('Sign in'), 'onClick');
        expect(closed).toHaveBeenCalledOnce();
        expect(input('password').value).toBe('');
        expect(input('password_confirmation').value).toBe('');
        expect(request.mock.calls[0][1].signal.aborted).toBe(true);
        resolve(reply({ next: 'authenticated', redirect: '/staff/enter' }));
        await pending;
        expect(navigation.visit).not.toHaveBeenCalled();
    });
    it('does not present a form when registration or its workplace is unavailable', () => {
        mounted.unmount();
        mounted = mountComponent(StaffRegistrationModal, {
            enabled: true,
            restaurant: null,
            onClose: closed,
        });
        expect(textContent(mounted.root)).toContain(
            'Staff registration is currently unavailable.',
        );
        expect(findAll(mounted.root, (n) => n.tag === 'form')).toHaveLength(0);
    });
});
