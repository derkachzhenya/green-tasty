import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import {
    cents,
    decimal,
    draftDishes,
    selectionKey,
} from '@/lib/guestSelection';
import {
    GuestSelectionError,
    guestMenu,
    guestRequest,
    selectionSummary,
} from '@/lib/guestSelectionApi';
import type { GuestMenu, SelectionInput } from '@/types/guest-selection';

export function useGuestSelections(initiallyInvalid: boolean) {
    const menu = shallowRef<GuestMenu | null>(null);
    const input = ref<SelectionInput[]>([]);
    const invalid = ref(initiallyInvalid);
    const loading = ref(false);
    const saving = ref(false);
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
    const locked = computed(
        () => conflict.value || menu.value?.order.editable === false,
    );
    const busy = computed(() => loading.value || saving.value);
    const unavailable = computed(() =>
        rows.value.some((row) => !row.available),
    );

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
            return;
        saving.value = true;
        error.value = '';
        success.value = '';
        validation.value = [];
        const current = ++generation;
        controller = new AbortController();
        try {
            const selections = selectionSummary(
                await guestRequest(
                    `${path}/selections`,
                    controller.signal,
                    input.value.map(({ menu_item_id, quantity }) => ({
                        menu_item_id,
                        quantity,
                    })),
                ),
            );
            if (current !== generation || !menu.value) return;
            menu.value = { ...menu.value, selections };
            input.value = selections.items.map(
                ({ menu_item_id, quantity }) => ({ menu_item_id, quantity }),
            );
            success.value =
                'Your selections are saved. The reservation owner submits the group pre-order.';
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
                    'The group pre-order is locked. Your changes were not saved.';
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
        if (!/^\/invitations\/[a-f0-9]{64}$/.test(path)) invalid.value = true;
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
    };
}
