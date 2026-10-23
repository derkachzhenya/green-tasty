<script setup lang="ts">
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { orderLocked, submittedOrder } from '@/lib/groupPreOrder';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import { show } from '@/routes/reservations';
import { submit as submissionRoute } from '@/routes/reservations/pre-order';
import type { DashboardOrder, SubmittedGroupOrder } from '@/types/reservation';

const props = defineProps<{
    reservationId: number;
    reservationStatus: string;
    order: DashboardOrder;
    canSubmit: boolean;
    refreshing: boolean;
    refreshVersion: number;
}>();
const emit = defineEmits<{
    submitted: [order: SubmittedGroupOrder];
    refresh: [];
    busy: [value: boolean];
    confirming: [value: boolean];
}>();
const opened = ref(false);
const busy = ref(false);
const confirmed = ref(false);
const needsRefresh = ref(false);
const accessLost = ref(false);
const error = ref('');
const validation = ref<string[]>([]);
const opener = shallowRef<HTMLElement | null>(null);
let controller: AbortController | undefined;
let sequence = 0;
const locked = computed(() => confirmed.value || orderLocked(props.order));
const terminal = computed(() =>
    ['cancelled', 'completed'].includes(props.reservationStatus),
);
const empty = computed(
    () =>
        !props.order.summary.items.length ||
        props.order.summary.total_quantity < 1,
);
const allowed = computed(
    () =>
        props.canSubmit &&
        !locked.value &&
        !terminal.value &&
        props.order.status === 'draft' &&
        !empty.value &&
        !accessLost.value &&
        !needsRefresh.value &&
        !props.refreshing,
);

function setOpen(value: boolean) {
    opened.value = value;
    emit('confirming', value);
}
function begin(event: MouseEvent) {
    if (!allowed.value || busy.value) return;
    error.value = '';
    validation.value = [];
    opener.value = event.currentTarget as HTMLElement;
    setOpen(true);
}
function close(value: boolean) {
    if (!value && !busy.value) setOpen(false);
}
function focusCancel(event: Event) {
    event.preventDefault();
    document.getElementById('cancel-group-submission')?.focus();
}
function restoreFocus(event: Event) {
    event.preventDefault();
    // Success can replace the entire deferred section; wait for its new heading.
    void nextTick(() => {
        if (!confirmed.value && opener.value?.isConnected) opener.value.focus();
        else document.getElementById('group-order-heading')?.focus();
    });
}
async function submit() {
    if (!opened.value || busy.value || !allowed.value) return;
    busy.value = true;
    emit('busy', true);
    error.value = '';
    validation.value = [];
    controller = new AbortController();
    const current = ++sequence;
    const reservationId = props.reservationId;
    try {
        const result = submittedOrder(
            await sessionJson(
                submissionRoute(reservationId).url,
                'post',
                controller.signal,
            ),
            reservationId,
        );
        if (sequence !== current) return;
        confirmed.value = true;
        setOpen(false);
        emit('submitted', result);
    } catch (failure) {
        if (sequence !== current) return;
        if (
            failure instanceof SessionRequestError &&
            [401, 403, 419].includes(failure.status)
        ) {
            accessLost.value = true;
            error.value =
                'Your session or permission has changed. Reload the dashboard before continuing.';
        } else if (
            failure instanceof SessionRequestError &&
            failure.status === 404
        ) {
            accessLost.value = true;
            error.value =
                'This reservation is no longer available. Reload the dashboard.';
        } else if (
            failure instanceof SessionRequestError &&
            failure.status === 429
        ) {
            error.value =
                'Too many submission attempts. Wait a minute, then try again.';
        } else {
            needsRefresh.value = true;
            if (
                failure instanceof SessionRequestError &&
                failure.status === 422
            ) {
                error.value =
                    'The group pre-order could not be submitted. Refresh and review the saved selections.';
                validation.value = Object.values(failure.errors).flat();
            } else if (
                failure instanceof SessionRequestError &&
                failure.status === 409
            ) {
                error.value =
                    'The order or reservation can no longer be submitted from its current state. Refresh the summary.';
            } else {
                error.value =
                    'The result could not be confirmed. The order may already be submitted. Refresh the summary before trying again.';
            }
        }
        if (needsRefresh.value || accessLost.value) setOpen(false);
    } finally {
        if (sequence === current) {
            busy.value = false;
            emit('busy', false);
        }
    }
}
function invalidate() {
    sequence++;
    controller?.abort();
    if (busy.value) {
        needsRefresh.value = true;
        error.value =
            'Submission was interrupted. Refresh the summary to check whether it completed.';
    }
    busy.value = false;
    emit('busy', false);
    setOpen(false);
}
watch(
    () => props.refreshVersion,
    () => {
        if (!accessLost.value) {
            needsRefresh.value = false;
            error.value = '';
            validation.value = [];
        }
    },
);
watch(
    () => props.order,
    () => {
        if (opened.value && !busy.value) {
            setOpen(false);
            error.value = locked.value
                ? ''
                : 'Saved selections changed. Review the updated summary before confirming.';
        }
    },
);
watch(
    () => props.canSubmit,
    (value) => {
        if (!value) invalidate();
    },
);
watch(
    () => props.reservationId,
    () => {
        invalidate();
        confirmed.value = false;
        needsRefresh.value = false;
        accessLost.value = false;
        error.value = '';
        validation.value = [];
    },
);
function pageShow(event: PageTransitionEvent) {
    if (event.persisted) emit('refresh');
}
onMounted(() => {
    window.addEventListener('pagehide', invalidate);
    window.addEventListener('pageshow', pageShow);
});
onBeforeUnmount(() => {
    invalidate();
    window.removeEventListener('pagehide', invalidate);
    window.removeEventListener('pageshow', pageShow);
});
</script>

<template>
    <section
        v-if="canSubmit && !locked"
        aria-label="Group pre-order submission"
        class="space-y-4 rounded-2xl border border-border bg-card p-5 sm:p-8"
    >
        <div class="flex flex-wrap items-center justify-between gap-5">
            <div class="max-w-xl min-w-0">
                <h3 class="text-lg font-medium">Ready to submit?</h3>
                <p class="mt-2 text-sm leading-6 text-muted-foreground">
                    Review the dishes and each participant’s selections above.
                    Submitting locks selections for everyone.
                </p>
            </div>
            <Button
                type="button"
                class="min-h-11 w-full sm:w-auto"
                :disabled="busy || !allowed"
                @click="begin"
                >{{
                    busy
                        ? 'Submitting group pre-order…'
                        : 'Submit Group Pre-Order'
                }}</Button
            >
        </div>
        <p v-if="terminal" class="text-sm text-muted-foreground">
            This reservation is {{ reservationStatus }} and cannot accept a
            pre-order submission.
        </p>
        <p v-else-if="empty" class="text-sm text-muted-foreground">
            Add at least one saved dish before submitting the group pre-order.
        </p>
        <template v-if="!opened">
            <p
                v-if="error"
                role="alert"
                class="text-sm leading-6 text-destructive"
            >
                {{ error }}
            </p>
            <ul
                v-if="validation.length"
                class="list-inside list-disc space-y-2 text-sm text-destructive"
            >
                <li v-for="message in validation" :key="message">
                    {{ message }}
                </li>
            </ul>
            <Button
                v-if="needsRefresh"
                type="button"
                variant="outline"
                class="min-h-11"
                :disabled="refreshing || busy"
                @click="$emit('refresh')"
                >{{
                    refreshing ? 'Refreshing…' : 'Refresh order status'
                }}</Button
            >
            <a
                v-if="accessLost"
                :href="show(reservationId).url"
                class="inline-flex min-h-11 items-center rounded-sm text-sm text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >Reload dashboard</a
            >
        </template>
        <Dialog :open="opened" @update:open="close">
            <DialogContent
                aria-modal="true"
                class="green-tasty max-h-[calc(100dvh-2rem)] overflow-y-auto bg-card font-[Poppins,sans-serif] text-foreground"
                :show-close-button="!busy"
                @open-auto-focus="focusCancel"
                @close-auto-focus="restoreFocus"
                @escape-key-down="busy && $event.preventDefault()"
                @interact-outside="busy && $event.preventDefault()"
            >
                <DialogHeader>
                    <DialogTitle>Submit group pre-order?</DialogTitle>
                    <DialogDescription
                        >This submits the latest saved selections for everyone.
                        Guests will no longer be able to add, change or remove
                        dishes.</DialogDescription
                    >
                </DialogHeader>
                <dl class="space-y-3 rounded-xl bg-accent p-4 text-sm">
                    <div class="flex flex-wrap justify-between gap-3">
                        <dt>Selected across the group</dt>
                        <dd class="font-medium">
                            {{ order.summary.total_quantity }}
                        </dd>
                    </div>
                    <div class="flex flex-wrap justify-between gap-3">
                        <dt>Grand total</dt>
                        <dd
                            class="font-medium wrap-anywhere text-primary tabular-nums"
                        >
                            {{ order.summary.grand_total }}
                        </dd>
                    </div>
                </dl>
                <p class="text-sm leading-6 text-muted-foreground">
                    Totals use saved unit prices. Guest selections may change
                    until submission.
                </p>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <form :aria-busy="busy" @submit.prevent="submit">
                    <DialogFooter>
                        <Button
                            id="cancel-group-submission"
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            :disabled="busy"
                            @click="close(false)"
                            >Keep reviewing</Button
                        >
                        <Button
                            type="submit"
                            class="min-h-11"
                            :disabled="busy || !allowed"
                            >{{
                                busy ? 'Submitting…' : 'Confirm submission'
                            }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
