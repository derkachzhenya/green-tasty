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
    type TestNode,
} from '@/test-support/renderComponent';
import type { ReservationParticipant } from '@/types/reservation';
import GuestInvitationManager from './GuestInvitationManager.vue';

const navigation = vi.hoisted(() => ({
    before: undefined as
        | undefined
        | ((event: { detail: { visit: { url: URL } } }) => void),
}));
vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: vi.fn((_name, callback) => {
            navigation.before = callback;
            return () => {
                navigation.before = undefined;
            };
        }),
    },
}));
vi.mock('@/components/ui/button', () => ({
    Button: {
        inheritAttrs: true,
        setup:
            (
                _props: unknown,
                { slots }: { slots: { default: () => VNodeChild } },
            ) =>
            () =>
                h('button', {}, [slots.default()]),
    },
}));
vi.mock('@/components/ui/dialog', async () => {
    const { defineComponent, h } = await import('vue');
    const box = (tag: string) =>
        defineComponent({
            setup:
                (_props, { slots }) =>
                () =>
                    h(tag, slots.default?.()),
        });
    return {
        Dialog: defineComponent({
            props: { open: Boolean },
            setup:
                (props, { slots }) =>
                () =>
                    props.open
                        ? h('section', { role: 'dialog' }, slots.default?.())
                        : null,
        }),
        DialogContent: box('div'),
        DialogHeader: box('header'),
        DialogTitle: box('h2'),
        DialogDescription: box('p'),
        DialogFooter: box('footer'),
    };
});

const alice: ReservationParticipant = {
    id: 2,
    name: 'Alice',
    is_owner: false,
    response_status: 'pending',
    invitation_status: 'active',
    invitation_expires_at: '2026-10-24T08:00:00+00:00',
};
const owner: ReservationParticipant = {
    ...alice,
    id: 1,
    name: 'Owner',
    is_owner: true,
    invitation_status: 'owner',
    invitation_expires_at: null,
};
const invitation = {
    guest_id: 2,
    name: 'Alice',
    expires_at: '2026-10-30T08:00:00+00:00',
    invitation_url: `http://localhost/invitations/${'b'.repeat(64)}`,
};
let mounted: ReturnType<typeof mountComponent>;
let changed: ReturnType<typeof vi.fn>;
let fetchMock: ReturnType<typeof vi.fn>;
let copyMock: ReturnType<typeof vi.fn>;
let pageWindow: EventTarget;

function button(label: string, submit = false): TestNode {
    const found = findAll(
        mounted.root,
        (element) =>
            element.tag === 'button' &&
            (element.props['aria-label'] === label ||
                textContent(element) === label) &&
            (!submit || element.props.type === 'submit'),
    )[0];
    if (!found) throw new Error(`Button not found: ${label}`);
    return found;
}
function input(id: string): TestNode {
    const found = findAll(
        mounted.root,
        (element) => element.tag === 'input' && element.props.id === id,
    )[0];
    if (!found) throw new Error(`Input not found: ${id}`);
    return found;
}
function submit(): Promise<void> {
    return fire(
        findAll(mounted.root, (element) => element.tag === 'form')[0],
        'onSubmit',
    );
}
async function create(): Promise<void> {
    await fire(button('Create invitation'), 'onClick');
    await fire(input('invitation-guest-name'), 'onUpdate:modelValue', 'Alice');
    await submit();
    await flush();
}
function response(data: unknown = invitation, status = 201): Response {
    return new Response(status === 204 ? null : JSON.stringify(data), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

beforeEach(() => {
    vi.stubGlobal('Document', class {});
    vi.stubGlobal('ShadowRoot', class {});
    pageWindow = Object.assign(new EventTarget(), {
        location: { origin: 'http://localhost', pathname: '/reservations/1' },
    });
    vi.stubGlobal('window', pageWindow);
    vi.stubGlobal('document', {
        cookie: 'XSRF-TOKEN=encrypted%2Bcookie%3D',
        activeElement: null,
        getElementById: vi.fn(),
    });
    copyMock = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { clipboard: { writeText: copyMock } });
    fetchMock = vi.fn().mockResolvedValue(response());
    vi.stubGlobal('fetch', fetchMock);
    changed = vi.fn();
    mounted = mountComponent(GuestInvitationManager, {
        reservationId: 1,
        participants: [owner, alice],
        selections: [],
        timezone: 'Asia/Tbilisi',
        onChanged: changed,
    });
});
afterEach(() => {
    mounted.unmount();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

describe('guest invitation management interactions', () => {
    it('offers creation from an empty participant state', async () => {
        mounted.setProps({ participants: [] });
        await flush();
        expect(textContent(mounted.root)).toContain('No participants yet');
        expect(button('Create invitation').props.disabled).toBe(false);
    });

    it.each(['expired', 'revoked', 'not_invited'] as const)(
        'offers a fresh link for a guest whose status is %s',
        async (status) => {
            mounted.setProps({
                participants: [{ ...alice, invitation_status: status }],
            });
            await flush();
            const label =
                status === 'not_invited'
                    ? 'Generate invitation for Alice'
                    : 'Regenerate invitation for Alice';
            await fire(button(label), 'onClick');
            expect(textContent(mounted.root)).toContain(
                'The previous link will stop working.',
            );
            await submit();
            expect(input('issued-invitation-link').props.value).toBe(
                invitation.invitation_url,
            );
        },
    );

    it('clears a newly issued link when the reservation context changes', async () => {
        await create();
        mounted.setProps({ reservationId: 9 });
        await flush();
        expect(
            findAll(
                mounted.root,
                (element) => element.props.id === 'issued-invitation-link',
            ),
        ).toHaveLength(0);
        await create();
        expect(fetchMock.mock.calls[1][0]).toBe('/reservations/9/invitations');
    });
    it('shows expiration in restaurant time, hides owner controls, and exposes no historical raw link', () => {
        expect(textContent(mounted.root)).toContain('24 Oct 2026, 12:00');
        expect(
            findAll(
                mounted.root,
                (element) =>
                    element.props['aria-label'] ===
                    'Revoke invitation for Owner',
            ),
        ).toHaveLength(0);
        expect(textContent(mounted.root)).not.toContain(
            invitation.invitation_url,
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('creates through the existing endpoint with XSRF credentials and only displays the newly returned URL', async () => {
        await create();
        const [url, request] = fetchMock.mock.calls[0];
        expect(url).toBe('/reservations/1/invitations');
        expect(request).toMatchObject({
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            redirect: 'error',
            body: JSON.stringify({ name: 'Alice' }),
        });
        expect(request.headers['X-XSRF-TOKEN']).toBe('encrypted+cookie=');
        expect(input('issued-invitation-link').props.value).toBe(
            invitation.invitation_url,
        );
        expect(
            findAll(
                mounted.root,
                (element) =>
                    element.tag === 'a' &&
                    element.props.href === invitation.invitation_url,
            ),
        ).toHaveLength(0);
        expect(changed).toHaveBeenCalledOnce();
        expect(copyMock).not.toHaveBeenCalled();
        await fire(button('Copy link'), 'onClick');
        expect(copyMock).toHaveBeenCalledWith(invitation.invitation_url);
        expect(textContent(mounted.root)).toContain('Invitation link copied.');
        await fire(button('Done'), 'onClick');
        expect(
            findAll(
                mounted.root,
                (element) => element.props.id === 'issued-invitation-link',
            ),
        ).toHaveLength(0);
    });

    it('retains field validation errors and permits correction without claiming success', async () => {
        fetchMock.mockResolvedValueOnce(
            response(
                { errors: { name: ['The name field is required.'] } },
                422,
            ),
        );
        await create();
        expect(textContent(mounted.root)).toContain(
            'The name field is required.',
        );
        expect(input('invitation-guest-name').props['aria-invalid']).toBe(true);
        expect(changed).not.toHaveBeenCalled();
        await fire(
            input('invitation-guest-name'),
            'onUpdate:modelValue',
            'Alice',
        );
        await submit();
        expect(input('issued-invitation-link').props.value).toBe(
            invitation.invitation_url,
        );
    });

    it('disables duplicate requests while loading', async () => {
        let resolve: (value: Response) => void = () => {};
        fetchMock.mockReturnValueOnce(
            new Promise<Response>((done) => {
                resolve = done;
            }),
        );
        await fire(button('Create invitation'), 'onClick');
        const pending = submit();
        await nextTick();
        expect(button('Please wait…').props.disabled).toBe(true);
        expect(textContent(mounted.root)).toContain('Updating invitation…');
        await submit();
        expect(fetchMock).toHaveBeenCalledOnce();
        resolve(response());
        await pending;
        await flush();
        expect(input('issued-invitation-link').props.value).toBe(
            invitation.invitation_url,
        );
    });

    it('requires explicit regeneration confirmation and allows cancelling without a request', async () => {
        await fire(button('Regenerate invitation for Alice'), 'onClick');
        expect(textContent(mounted.root)).toContain(
            'The previous link will stop working.',
        );
        expect(fetchMock).not.toHaveBeenCalled();
        await fire(button('Cancel'), 'onClick');
        expect(fetchMock).not.toHaveBeenCalled();
        await fire(button('Regenerate invitation for Alice'), 'onClick');
        await submit();
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/reservations/1/invitations/2/regenerate',
        );
        expect(textContent(mounted.root)).toContain(
            'The previous link no longer works.',
        );
        expect(input('issued-invitation-link').props.value).toBe(
            invitation.invitation_url,
        );
    });

    it('confirms revocation and retains no raw invitation URL after success', async () => {
        fetchMock.mockResolvedValue(response(null, 204));
        await fire(button('Revoke invitation for Alice'), 'onClick');
        expect(textContent(mounted.root)).toContain(
            'The guest and saved selections will remain.',
        );
        expect(fetchMock).not.toHaveBeenCalled();
        await submit();
        expect(fetchMock.mock.calls[0][1].method).toBe('DELETE');
        expect(textContent(mounted.root)).toContain(
            'Invitation for Alice revoked.',
        );
        expect(changed).toHaveBeenCalledOnce();
        expect(
            findAll(
                mounted.root,
                (element) => element.props.id === 'issued-invitation-link',
            ),
        ).toHaveLength(0);
    });

    it('offers manual copying when clipboard permission is unavailable', async () => {
        copyMock.mockRejectedValue(new Error('Permission denied'));
        await create();
        await fire(button('Copy link'), 'onClick');
        expect(textContent(mounted.root)).toContain('copy it manually');
        expect(input('issued-invitation-link').props.readonly).toBe('');
        expect(textContent(mounted.root)).not.toContain('Permission denied');
    });

    it.each([401, 403, 419, 404, 429])(
        'handles HTTP %s without showing a link or exception details',
        async (status) => {
            fetchMock.mockResolvedValue(
                response({ message: 'Private server details' }, status),
            );
            await create();
            expect(
                findAll(
                    mounted.root,
                    (element) => element.props.role === 'alert',
                ).some((element) => textContent(element).length > 0),
            ).toBe(true);
            expect(
                findAll(
                    mounted.root,
                    (element) => element.props.id === 'issued-invitation-link',
                ),
            ).toHaveLength(0);
            expect(textContent(mounted.root)).not.toContain(
                'Private server details',
            );
            expect(changed).not.toHaveBeenCalled();
        },
    );

    it('blocks unsafe repeat submission after an uncertain network result', async () => {
        fetchMock.mockRejectedValue(new Error('Private network details'));
        await create();
        expect(textContent(mounted.root)).toContain(
            'result could not be confirmed',
        );
        expect(button('Create invitation', true).props.disabled).toBe(true);
        await submit();
        expect(fetchMock).toHaveBeenCalledOnce();
        expect(textContent(mounted.root)).not.toContain(
            'Private network details',
        );
    });

    it('rejects a malformed or cross-origin issued URL', async () => {
        fetchMock.mockResolvedValue(
            response({
                ...invitation,
                invitation_url:
                    'https://untrusted.example/invitations/' + 'a'.repeat(64),
            }),
        );
        await create();
        expect(textContent(mounted.root)).toContain(
            'result could not be confirmed',
        );
        expect(
            findAll(
                mounted.root,
                (element) => element.props.id === 'issued-invitation-link',
            ),
        ).toHaveLength(0);
    });

    it.each(['pagehide', 'pageshow', 'popstate'])(
        'discards the issued secret on %s',
        async (event) => {
            await create();
            pageWindow.dispatchEvent(new Event(event));
            await flush();
            expect(
                findAll(
                    mounted.root,
                    (element) => element.props.id === 'issued-invitation-link',
                ),
            ).toHaveLength(0);
        },
    );

    it('keeps the new link during a same-page refresh and clears it on navigation', async () => {
        await create();
        navigation.before?.({
            detail: {
                visit: { url: new URL('http://localhost/reservations/1') },
            },
        });
        await flush();
        expect(input('issued-invitation-link').props.value).toBe(
            invitation.invitation_url,
        );
        navigation.before?.({
            detail: { visit: { url: new URL('http://localhost/restaurants') } },
        });
        await flush();
        expect(
            findAll(
                mounted.root,
                (element) => element.props.id === 'issued-invitation-link',
            ),
        ).toHaveLength(0);
    });

    it('aborts a pending request on unmount and ignores late responses', async () => {
        let resolve: (value: Response) => void = () => {};
        fetchMock.mockReturnValueOnce(
            new Promise<Response>((done) => {
                resolve = done;
            }),
        );
        await fire(button('Create invitation'), 'onClick');
        const pending = submit();
        await nextTick();
        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        mounted.unmount();
        expect(signal.aborted).toBe(true);
        resolve(response());
        await pending;
        expect(changed).not.toHaveBeenCalled();
        expect(mounted.root.children).toHaveLength(0);
    });
});
