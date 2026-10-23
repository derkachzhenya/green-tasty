import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import {
    InvitationApiError,
    invitationRequest,
    issuedInvitation,
} from '@/lib/invitationApi';
import { destroy, regenerate, store } from '@/routes/reservations/invitations';
import type { IssuedInvitation } from '@/types/invitation';
import type { ReservationParticipant } from '@/types/reservation';

type Action = 'create' | 'regenerate' | 'revoke';

export function useGuestInvitations(
    reservationId: () => number,
    changed: () => void,
) {
    const action = ref<Action | null>(null);
    const selected = shallowRef<ReservationParticipant | null>(null);
    const issued = shallowRef<IssuedInvitation | null>(null);
    const name = ref('');
    const nameError = ref('');
    const error = ref('');
    const success = ref('');
    const copying = ref(false);
    const copyMessage = ref('');
    const busy = ref(false);
    const uncertain = ref(false);
    let controller: AbortController | null = null;
    let sequence = 0;

    function clear(): void {
        sequence++;
        controller?.abort();
        issued.value = null;
        action.value = null;
        selected.value = null;
        name.value = '';
        nameError.value = '';
        error.value = '';
        copyMessage.value = '';
        busy.value = false;
        copying.value = false;
        uncertain.value = false;
    }

    function open(
        next: Action,
        guest: ReservationParticipant | null = null,
    ): void {
        if (busy.value || guest?.is_owner) return;
        clear();
        success.value = '';
        action.value = next;
        selected.value = guest;
    }

    async function submit(): Promise<void> {
        if (!action.value || busy.value || uncertain.value || issued.value)
            return;
        const operation = action.value;
        const guest = selected.value;
        if (operation !== 'create' && (!guest || guest.is_owner)) return;
        busy.value = true;
        nameError.value = '';
        error.value = '';
        issued.value = null;
        controller = new AbortController();
        const current = ++sequence;
        try {
            const route =
                operation === 'create'
                    ? store(reservationId())
                    : operation === 'regenerate'
                      ? regenerate([reservationId(), guest!.id])
                      : destroy([reservationId(), guest!.id]);
            const result = await invitationRequest(
                route.url,
                operation === 'revoke' ? 'delete' : 'post',
                controller.signal,
                operation === 'create' ? { name: name.value } : undefined,
            );
            if (current !== sequence) return;
            if (operation === 'revoke') {
                success.value = `Invitation for ${guest!.name} revoked.`;
                clear();
            } else {
                issued.value = issuedInvitation(result, window.location.origin);
                success.value =
                    operation === 'create'
                        ? `Invitation for ${issued.value.name} created.`
                        : `Invitation for ${issued.value.name} regenerated. The previous link no longer works.`;
            }
            changed();
        } catch (failure) {
            if (current !== sequence) return;
            if (
                failure instanceof InvitationApiError &&
                failure.status === 422
            ) {
                nameError.value = failure.errors.name?.[0] ?? '';
                error.value = nameError.value
                    ? ''
                    : 'Please check the invitation details and try again.';
            } else if (
                failure instanceof InvitationApiError &&
                [401, 403, 419].includes(failure.status)
            ) {
                error.value =
                    'Your session or permission has changed. Sign in and reload the dashboard.';
            } else if (
                failure instanceof InvitationApiError &&
                failure.status === 404
            ) {
                error.value =
                    'This guest or reservation is unavailable. Reload the dashboard.';
            } else if (
                failure instanceof InvitationApiError &&
                failure.status === 429
            ) {
                error.value =
                    'Too many invitation requests. Please wait and try again.';
            } else {
                uncertain.value = true;
                error.value =
                    'The result could not be confirmed. Close this dialog and refresh the dashboard before trying again.';
            }
        } finally {
            if (current === sequence) busy.value = false;
        }
    }

    async function copy(): Promise<void> {
        if (!issued.value || copying.value) return;
        const current = sequence;
        copying.value = true;
        copyMessage.value = '';
        try {
            await navigator.clipboard.writeText(issued.value.invitation_url);
            if (current === sequence)
                copyMessage.value = 'Invitation link copied.';
        } catch {
            if (current === sequence)
                copyMessage.value =
                    'Copy was unavailable. Select the link and copy it manually.';
        } finally {
            if (current === sequence) copying.value = false;
        }
    }

    watch(reservationId, () => {
        clear();
        success.value = '';
    });
    let removeNavigationListener: (() => void) | undefined;
    onMounted(() => {
        window.addEventListener('pagehide', clear);
        window.addEventListener('pageshow', clear);
        window.addEventListener('popstate', clear);
        removeNavigationListener = router.on('before', (event) => {
            if (event.detail.visit.url.pathname !== window.location.pathname)
                clear();
        });
    });
    onBeforeUnmount(() => {
        clear();
        window.removeEventListener('pagehide', clear);
        window.removeEventListener('pageshow', clear);
        window.removeEventListener('popstate', clear);
        removeNavigationListener?.();
    });

    return {
        action,
        selected,
        issued,
        name,
        nameError,
        error,
        success,
        busy,
        uncertain,
        copying,
        copyMessage,
        open,
        clear,
        submit,
        copy,
    };
}
