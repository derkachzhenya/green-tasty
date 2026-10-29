import { usePreOrderClock } from './usePreOrderClock';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import {
    cents,
    decimal,
    draftDishes,
    selectionKey,
} from '@/lib/guestSelection';
import {
    GuestSelectionError,
    guestMenu,
    guestAction,
    guestRequest,
    selectionSummary,
} from '@/lib/guestSelectionApi';
import type { GuestMenu, SelectionInput } from '@/types/guest-selection';

export function useGuestSelections(
    initiallyInvalid: boolean,
    ownerReservationId?: number,
) {
    const menu = shallowRef<GuestMenu | null>(null);
    const input = ref<SelectionInput[]>([]);
    const invalid = ref(initiallyInvalid);
    const loading = ref(false);
    const saving = ref(false);
    const submitting = ref(false);
    const identifying = ref(false);
    const starting = ref(false);
    const error = ref('');
    const validation = ref<string[]>([]);
    const success = ref('');
    const conflict = ref(false);
    let path = '';
    let controller: AbortController | undefined;
    let generation = 0;
    const rows = computed(() =>
        menu.value ? draftDishes(menu.value, input.value) : [],
    );
    const total = computed(() =>
        decimal(rows.value.reduce((sum, row) => sum + cents(row.subtotal), 0n)),
    );
    const count = computed(() =>
        input.value.reduce((sum, item) => sum + item.quantity, 0),
    );
    const dirty = computed(
        () =>
            Boolean(menu.value) &&
            selectionKey(input.value) !==
                selectionKey(menu.value!.selections.items),
    );
    const { cutoffLocked, warning, minutes } = usePreOrderClock(
        computed(() => menu.value?.timing),
    );
    const locked = computed(
        () =>
            cutoffLocked.value ||
            conflict.value ||
            menu.value?.order.editable === false,
    );
    const busy = computed(
        () =>
            loading.value ||
            saving.value ||
            submitting.value ||
            identifying.value ||
            starting.value,
    );
    const unavailable = computed(() =>
        rows.value.some((row) => !row.available),
    );

    function restoreSaved() {
        input.value =
            menu.value?.selections.items.map(({ menu_item_id, quantity }) => ({
                menu_item_id,
                quantity,
            })) ?? [];
    }
    watch(cutoffLocked, (value) => {
        if (value && dirty.value) {
            restoreSaved();
            error.value =
                'The cutoff was reached. Unsaved changes were discarded.';
        }
    });
    let refreshAtCutoff = false;
    watch(cutoffLocked, (value) => {
        if (value && menu.value?.timing && !menu.value.timing.locked)
            refreshAtCutoff = true;
    });
    watch([cutoffLocked, busy], () => {
        if (refreshAtCutoff && !busy.value) {
            refreshAtCutoff = false;
            void load();
        }
    });
    function clear() {
        generation++;
        controller?.abort();
        menu.value = null;
        input.value = [];
        success.value = '';
        error.value = '';
        validation.value = [];
        loading.value = false;
        saving.value = false;
        submitting.value = false;
        identifying.value = false;
        starting.value = false;
    }
    function failure(reason: unknown) {
        if (reason instanceof GuestSelectionError && reason.status === 404) {
            clear();
            invalid.value = true;
        } else if (
            reason instanceof GuestSelectionError &&
            reason.status === 429
        ) {
            error.value = 'Too many requests. Wait a minute, then try again.';
        } else {
            error.value =
                'Unable to connect. Your changes have not been confirmed. Try again.';
        }
    }
    async function load(preserveDraft = false) {
        if (busy.value || invalid.value) return;
        loading.value = true;
        error.value = '';
        const current = ++generation;
        controller = new AbortController();
        try {
            const data = guestMenu(
                await guestRequest(`${path}/menu`, controller.signal),
            );
            if (current !== generation) return;
            const keep = preserveDraft && dirty.value && data.order.editable;
            menu.value = data;
            if (!keep)
                input.value = data.selections.items.map(
                    ({ menu_item_id, quantity }) => ({
                        menu_item_id,
                        quantity,
                    }),
                );
            conflict.value = !data.order.editable;
        } catch (reason) {
            if (current === generation) failure(reason);
        } finally {
            if (current === generation) loading.value = false;
        }
    }
    function setQuantity(id: number, quantity: number) {
        if (busy.value || locked.value || invalid.value || !menu.value) return;
        if (!Number.isInteger(quantity) || quantity < 0 || quantity > 99)
            return;
        const existing = input.value.find((item) => item.menu_item_id === id);
        if (quantity > 0 && !existing && input.value.length >= 100) {
            error.value = 'Select up to 100 different dishes.';
            return;
        }
        const available = menu.value.invitation.menu_categories.some(
            (category) => category.dishes.some((dish) => dish.id === id),
        );
        if (quantity > 0 && !available) return;
        input.value = input.value.filter((item) => item.menu_item_id !== id);
        if (quantity > 0) input.value.push({ menu_item_id: id, quantity });
        success.value = '';
        validation.value = [];
    }
    async function save() {
        if (
            busy.value ||
            locked.value ||
            invalid.value ||
            !dirty.value ||
            unavailable.value
        )
            return false;
        saving.value = true;
        error.value = '';
        success.value = '';
        validation.value = [];
        const current = ++generation;
        controller = new AbortController();
        try {
            const saved = selectionSummary(
                await guestRequest(
                    `${path}/selections`,
                    controller.signal,
                    input.value.map(({ menu_item_id, quantity }) => ({
                        menu_item_id,
                        quantity,
                    })),
                    menu.value?.guest_state.version,
                ),
            );
            if (current !== generation || !menu.value) return;
            const refreshed = guestMenu(
                await guestRequest(`${path}/menu`, controller.signal),
            );
            if (current !== generation) return false;
            menu.value = refreshed;
            input.value = refreshed.selections.items.map(
                ({ menu_item_id, quantity }) => ({ menu_item_id, quantity }),
            );
            if (
                JSON.stringify(saved) !==
                    JSON.stringify(refreshed.selections) ||
                !refreshed.order.editable
            ) {
                error.value =
                    'Your cart or order status changed. Review the saved selections before submitting.';
                return false;
            }
            success.value = 'Your cart is saved.';
            return true;
        } catch (reason) {
            if (current !== generation) return;
            if (
                reason instanceof GuestSelectionError &&
                reason.status === 409
            ) {
                conflict.value = true;
                // Keep the last confirmed snapshot until the guest refreshes.
                input.value =
                    menu.value?.selections.items.map(
                        ({ menu_item_id, quantity }) => ({
                            menu_item_id,
                            quantity,
                        }),
                    ) ?? [];
                error.value =
                    'Your cart or order status changed. Refresh before making more changes.';
            } else if (
                reason instanceof GuestSelectionError &&
                reason.status === 422
            ) {
                validation.value = reason.messages.length
                    ? reason.messages
                    : [
                          'Check the selected dishes and quantities, then try again.',
                      ];
            } else failure(reason);
        } finally {
            if (current === generation) saving.value = false;
        }
    }
    async function identify(name: string): Promise<boolean> {
        if (busy.value || invalid.value || !menu.value) return false;
        identifying.value = true;
        error.value = '';
        validation.value = [];
        const current = ++generation;
        controller = new AbortController();
        try {
            const data = guestMenu(
                await guestAction(`${path}/identity`, controller.signal, {
                    name,
                }),
            );
            if (current !== generation) return false;
            menu.value = data;
            input.value = data.selections.items.map(
                ({ menu_item_id, quantity }) => ({ menu_item_id, quantity }),
            );
            return true;
        } catch (reason) {
            if (current === generation) {
                if (
                    reason instanceof GuestSelectionError &&
                    reason.status === 422
                )
                    validation.value = reason.messages;
                else if (
                    reason instanceof GuestSelectionError &&
                    reason.status === 409
                )
                    error.value =
                        'This pre-order is no longer editable. Refresh to see its saved status.';
                else failure(reason);
            }
            return false;
        } finally {
            if (current === generation) identifying.value = false;
        }
    }
    async function startNew(): Promise<boolean> {
        if (
            busy.value ||
            invalid.value ||
            cutoffLocked.value ||
            !menu.value?.guest_state.can_start_new ||
            ownerReservationId
        )
            return false;
        starting.value = true;
        error.value = '';
        const current = ++generation;
        controller = new AbortController();
        try {
            const data = guestMenu(
                await guestAction(
                    `${path}/start`,
                    controller.signal,
                    {},
                    menu.value.guest_state.version,
                ),
            );
            if (current !== generation) return false;
            menu.value = data;
            restoreSaved();
            conflict.value = !data.order.editable;
            return data.guest_state.draft_round != null;
        } catch (reason) {
            if (current === generation) {
                if (
                    reason instanceof GuestSelectionError &&
                    reason.status === 409
                )
                    error.value =
                        'The order changed or is now closed. Refresh before starting a new pre-order.';
                else failure(reason);
            }
            return false;
        } finally {
            if (current === generation) starting.value = false;
        }
    }
    async function submit(): Promise<boolean> {
        if (
            busy.value ||
            locked.value ||
            invalid.value ||
            !menu.value ||
            (!input.value.length && !(ownerReservationId && dirty.value)) ||
            unavailable.value
        )
            return false;
        if (!ownerReservationId && dirty.value && !(await save())) return false;
        if (
            ownerReservationId &&
            !dirty.value &&
            menu.value.guest_state.submitted_at
        )
            return false;
        submitting.value = true;
        error.value = '';
        validation.value = [];
        const current = ++generation;
        controller = new AbortController();
        try {
            const data = guestMenu(
                await guestAction(
                    `${path}/submit`,
                    controller.signal,
                    { items: input.value },
                    menu.value.guest_state.version,
                ),
            );
            if (current !== generation) return false;
            menu.value = data;
            input.value = data.selections.items.map(
                ({ menu_item_id, quantity }) => ({ menu_item_id, quantity }),
            );
            return (
                data.guest_state.submitted_at !== null ||
                Boolean(ownerReservationId && !data.selections.items.length)
            );
        } catch (reason) {
            if (current === generation) {
                if (
                    reason instanceof GuestSelectionError &&
                    reason.status === 422
                )
                    validation.value = reason.messages;
                else if (
                    reason instanceof GuestSelectionError &&
                    reason.status === 409
                ) {
                    conflict.value = true;
                    restoreSaved();
                    error.value =
                        'Your cart or order status changed. Refresh before submitting.';
                } else failure(reason);
            }
            return false;
        } finally {
            if (current === generation) submitting.value = false;
        }
    }
    function cancelChanges() {
        if (busy.value || !menu.value) return;
        input.value = menu.value.selections.items.map(
            ({ menu_item_id, quantity }) => ({ menu_item_id, quantity }),
        );
        validation.value = [];
        error.value = '';
    }
    function beforeUnload(event: BeforeUnloadEvent) {
        if (dirty.value) {
            event.preventDefault();
            event.returnValue = '';
        }
    }
    function pageHide() {
        clear();
    }
    function pageShow(event: PageTransitionEvent) {
        if (event.persisted) void load();
    }
    onMounted(() => {
        // The bearer remains in memory/address bar, never in session, Inertia history or browser storage.
        path = window.location.pathname.replace(/\/$/, '');
        if (ownerReservationId)
            path = `/reservations/${ownerReservationId}/owner-pre-order`;
        else if (!/^\/invitations\/[a-f0-9]{64}$/.test(path))
            invalid.value = true;
        window.addEventListener('pagehide', pageHide);
        window.addEventListener('pageshow', pageShow);
        window.addEventListener('beforeunload', beforeUnload);
        void load();
    });
    onBeforeUnmount(() => {
        clear();
        path = '';
        window.removeEventListener('pagehide', pageHide);
        window.removeEventListener('pageshow', pageShow);
        window.removeEventListener('beforeunload', beforeUnload);
    });
    return {
        menu,
        cutoffLocked,
        warning,
        minutes,
        cancelChanges,
        rows,
        total,
        count,
        dirty,
        locked,
        busy,
        loading,
        saving,
        invalid,
        error,
        validation,
        success,
        unavailable,
        load,
        save,
        setQuantity,
        identify,
        submit,
        submitting,
        identifying,
        startNew,
        starting,
    };
}
