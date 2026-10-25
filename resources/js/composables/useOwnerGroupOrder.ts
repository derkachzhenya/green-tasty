import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import type { OwnerGroupOrder } from '@/types/owner-group-order';

export function useOwnerGroupOrder(reservationId: number, changed: () => void) {
    const order = shallowRef<OwnerGroupOrder | null>(null);
    const loading = ref(false);
    const busy = ref(false);
    const stale = ref(false);
    const error = ref('');
    const submitted = ref(false);
    const root = `/reservations/${reservationId}/pre-order/owner-view`;
    let controller: AbortController | undefined;
    let sequence = 0;
    function parse(value: unknown): OwnerGroupOrder {
        const data = value as OwnerGroupOrder;
        if (
            !data ||
            data.reservation_id !== reservationId ||
            !/^"[a-f0-9]{64}"$/.test(data.version) ||
            !Array.isArray(data.participants) ||
            !data.summary ||
            typeof data.can_submit !== 'boolean' ||
            typeof data.editable !== 'boolean'
        )
            throw new Error('Invalid group pre-order response.');
        return data;
    }
    function failure(reason: unknown) {
        stale.value = true;
        if (
            reason instanceof SessionRequestError &&
            [401, 403, 404, 419].includes(reason.status)
        ) {
            order.value = null;
            error.value =
                'Your session or access has changed. Reload Reservations before continuing.';
        } else if (
            reason instanceof SessionRequestError &&
            reason.status === 409
        ) {
            error.value =
                'The group pre-order or its permissions changed. Refresh and review the saved order.';
        } else if (
            reason instanceof SessionRequestError &&
            reason.status === 422
        ) {
            error.value =
                Object.values(reason.errors).flat().join(' ') ||
                'This change is not allowed. Refresh and review the order.';
        } else if (
            reason instanceof SessionRequestError &&
            reason.status === 429
        ) {
            error.value =
                'Too many requests. Wait a minute and refresh the order.';
        } else
            error.value =
                'The result could not be confirmed. Refresh the saved order before trying again.';
    }
    async function load() {
        if (loading.value || busy.value) return;
        loading.value = true;
        error.value = '';
        const current = ++sequence;
        controller?.abort();
        controller = new AbortController();
        try {
            const result = parse(
                await sessionJson(root, 'get', controller.signal),
            );
            if (current !== sequence) return;
            order.value = result;
            stale.value = false;
        } catch (reason) {
            if (current === sequence) failure(reason);
        } finally {
            if (current === sequence) loading.value = false;
        }
    }
    async function mutate(
        path: string,
        method: 'patch' | 'delete' | 'post',
        body?: Record<string, unknown>,
    ): Promise<boolean> {
        if (!order.value || loading.value || busy.value || stale.value)
            return false;
        busy.value = true;
        error.value = '';
        const current = ++sequence;
        controller = new AbortController();
        try {
            const result = parse(
                await sessionJson(
                    `${root}${path}`,
                    method,
                    controller.signal,
                    body,
                    order.value.version,
                ),
            );
            if (current !== sequence) return false;
            order.value = result;
            if (path === '/submit') submitted.value = true;
            changed();
            return true;
        } catch (reason) {
            if (current === sequence) failure(reason);
            return false;
        } finally {
            if (current === sequence) busy.value = false;
        }
    }
    function clear() {
        sequence++;
        controller?.abort();
        order.value = null;
    }
    onMounted(() => {
        window.addEventListener('pagehide', clear);
        void load();
    });
    onBeforeUnmount(() => {
        clear();
        window.removeEventListener('pagehide', clear);
    });
    return { order, loading, busy, stale, error, submitted, load, mutate };
}
