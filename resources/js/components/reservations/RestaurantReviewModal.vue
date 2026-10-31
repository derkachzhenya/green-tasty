<script setup lang="ts">
import {
    ref,
    shallowRef,
    onMounted,
    onBeforeUnmount,
    computed,
    watch,
} from 'vue';
import { usePreOrderClock } from '@/composables/usePreOrderClock';
import {
    Check,
    X,
    MapPin,
    CalendarDays,
    Clock,
    Users,
    Bell,
    CircleCheck,
} from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import { bookingDate } from '@/lib/booking';
import { dollarPrice } from '@/lib/guestSelection';
import { dishImage, dishImageCropped } from '@/lib/guestMenu';
import type {
    RestaurantReview,
    WaiterReservation,
} from '@/types/restaurant-review';
const props = defineProps<{
    restaurantId: number;
    reservation: WaiterReservation;
}>();
const emit = defineEmits<{ close: []; changed: [] }>();
const review = shallowRef<RestaurantReview | null>(null);
const { cutoffLocked } = usePreOrderClock(computed(() => review.value?.timing));
const participants = computed(() =>
    [...(review.value?.participants ?? [])].sort(
        (a, b) => Number(b.is_owner) - Number(a.is_owner),
    ),
);
const rejectedItems = computed(() =>
    participants.value.flatMap((participant) =>
        participant.items.filter(
            (item) => !item.superseded && item.decision === 'rejected',
        ),
    ),
);
const rejectionWasPublished = computed(
    () =>
        rejectedItems.value.length > 0 &&
        rejectedItems.value.every((item) => item.published),
);
const pendingNames = computed(
    () =>
        review.value?.participants
            .filter((p) => p.items.some((i) => !i.published && !i.superseded))
            .map((p) => p.name) ?? [],
);
const busy = ref(false),
    stale = ref(false),
    error = ref(''),
    notice = ref('');
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
function dismissNotice(): void {
    clearTimeout(noticeTimer);
    noticeTimer = undefined;
    notice.value = '';
}
let refreshAtCutoff = false;
watch(cutoffLocked, (locked) => {
    if (locked && review.value?.timing && !review.value.timing.locked)
        refreshAtCutoff = true;
});
watch([cutoffLocked, busy], () => {
    if (refreshAtCutoff && !busy.value) {
        refreshAtCutoff = false;
        void request();
    }
});
const controller = new AbortController();
let trigger: HTMLElement | null = null;
const root = `/staff/restaurants/${props.restaurantId}/reservations/${props.reservation.id}/review`;
async function request(
    path = '',
    method: 'get' | 'patch' | 'post' = 'get',
    body?: Record<string, unknown>,
) {
    if (
        busy.value ||
        (method !== 'get' &&
            (stale.value || cutoffLocked.value || !review.value?.editable))
    )
        return;
    busy.value = true;
    error.value = '';
    dismissNotice();
    try {
        review.value = (await sessionJson(
            root + path,
            method,
            controller.signal,
            body,
            review.value?.version,
        )) as RestaurantReview;
        stale.value = false;
        if (method !== 'get') emit('changed');
        if (path === '/submit') {
            notice.value = 'Pre-order submitted. The owner can see the result.';
            noticeTimer = setTimeout(dismissNotice, 5000);
        }
    } catch (reason) {
        stale.value = true;
        if (
            reason instanceof SessionRequestError &&
            [401, 403, 404, 419].includes(reason.status)
        )
            review.value = null;
        error.value =
            reason instanceof SessionRequestError && reason.status === 422
                ? Object.values(reason.errors).flat().join(' ')
                : 'The result could not be confirmed. Refresh the review before continuing.';
    } finally {
        busy.value = false;
    }
}
function remember() {
    trigger =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
}
function restore(event: Event) {
    if (trigger?.isConnected) {
        event.preventDefault();
        trigger.focus();
    }
}
onMounted(() => void request());
onBeforeUnmount(() => {
    controller.abort();
    dismissNotice();
});
</script>
<template>
    <div
        v-if="notice"
        role="status"
        class="green-tasty gt-toast flex items-center gap-3 rounded-lg border border-primary bg-[#edffed] px-5 py-4 font-[Poppins,sans-serif] text-foreground shadow-lg"
    >
        <CircleCheck
            class="size-5 shrink-0 fill-primary text-white"
            aria-hidden="true"
        />
        <p class="min-w-0 flex-1 text-sm font-medium">{{ notice }}</p>
        <button
            type="button"
            aria-label="Dismiss notification"
            class="-m-2 rounded-md p-2 focus-visible:outline-2"
            @click="dismissNotice"
        >
            <X class="size-5" aria-hidden="true" />
        </button>
    </div>
    <Dialog :open="true" @update:open="!busy && emit('close')">
        <DialogContent
            :show-close-button="false"
            class="green-tasty restaurant-review-dialog flex max-h-[calc(100dvh-32px)] w-[calc(100%-32px)] max-w-[838px] flex-col gap-0 overflow-hidden rounded-3xl bg-[#f7f7f7] p-0 font-[Poppins,sans-serif] outline-none sm:max-w-[838px]"
            @open-auto-focus="remember"
            @close-auto-focus="restore"
            @escape-key-down="busy && $event.preventDefault()"
            @interact-outside="$event.preventDefault()"
        >
            <button
                type="button"
                aria-label="Close review"
                class="absolute top-3 right-3 grid size-11 place-items-center rounded-lg"
                :disabled="busy"
                @click="emit('close')"
            >
                <X aria-hidden="true" class="size-5" />
            </button>
            <header
                class="mx-4 shrink-0 border-b border-primary/15 pt-6 pb-4 sm:mx-10 sm:pt-8"
            >
                <div class="flex flex-wrap items-center gap-3 pr-10">
                    <DialogTitle class="text-[26px] leading-10 font-medium"
                        >Reservation Pre-Order</DialogTitle
                    ><span
                        v-if="review"
                        class="rounded-md px-3 py-1 text-xs leading-5"
                        :class="
                            review.status === 'Confirmed by Restaurant'
                                ? 'bg-[#e8ffea]'
                                : review.status === 'Updated Pre-Order'
                                  ? 'bg-[#dedede]'
                                  : 'bg-[#fff3d2]'
                        "
                        >{{ review.status }}</span
                    >
                </div>
                <DialogDescription class="mt-2 text-xs wrap-anywhere"
                    >Reservation: {{ reservation.code }}</DialogDescription
                >
                <div
                    class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs leading-5"
                >
                    <span class="inline-flex min-w-0 items-center gap-2"
                        ><MapPin
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />{{ reservation.restaurant.address }}</span
                    >
                    <span
                        class="inline-flex items-center gap-2 whitespace-nowrap"
                        ><CalendarDays
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />{{ bookingDate(reservation.date ?? '') }}</span
                    >
                    <span
                        class="inline-flex items-center gap-2 whitespace-nowrap"
                        ><Clock
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />{{ reservation.time }} –
                        {{ reservation.end_time }}</span
                    >
                    <span
                        class="inline-flex items-center gap-2 whitespace-nowrap"
                        ><Users
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />{{ reservation.guest_count }}
                        {{
                            reservation.guest_count === 1 ? 'Guest' : 'Guests'
                        }}</span
                    >
                </div>
            </header>
            <div
                class="min-h-0 space-y-5 overflow-y-auto overscroll-contain p-4 sm:px-10 sm:py-6"
                :aria-busy="busy"
            >
                <p
                    v-if="busy"
                    role="status"
                    :class="review ? 'sr-only' : 'py-8'"
                >
                    Saving or loading review…
                </p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded bg-destructive/10 p-3 text-xs text-destructive"
                >
                    {{ error }}
                </p>
                <Button
                    v-if="stale"
                    variant="outline"
                    :disabled="busy"
                    @click="request()"
                    >Refresh review</Button
                >
                <template v-if="review">
                    <p
                        v-if="rejectedItems.length"
                        role="status"
                        class="flex items-center gap-2 rounded-md border border-destructive/10 bg-[#fff6f8] p-3 text-xs"
                    >
                        <Bell class="size-4 shrink-0 text-muted-foreground" />
                        <span>
                            Some items were rejected.
                            {{
                                rejectionWasPublished
                                    ? 'Owner was notified about it.'
                                    : 'Owner will be notified after you submit.'
                            }}
                        </span>
                    </p>
                    <p
                        v-if="
                            review.editable &&
                            !cutoffLocked &&
                            review.revision > 1 &&
                            pendingNames.length
                        "
                        role="status"
                        class="flex gap-2 rounded-md border border-amber-100 bg-[#fffced] p-3 text-xs"
                    >
                        <Bell class="size-4 shrink-0" />{{
                            pendingNames.join(', ')
                        }}
                        {{ pendingNames.length === 1 ? 'has' : 'have' }} updated
                        the pre-order. Review all new dishes before submitting.
                    </p>
                    <section
                        v-for="participant in participants"
                        :key="participant.id"
                        :aria-label="`${participant.is_owner ? 'Owner' : 'Guest'}: ${participant.name}`"
                        class="rounded-xl bg-white p-4"
                    >
                        <h3 class="mb-2 text-base font-medium">
                            {{ participant.is_owner ? 'Owner' : 'Guest' }}:
                            {{ participant.name }}
                        </h3>
                        <p
                            v-if="
                                !participant.items.some(
                                    (item) => !item.superseded,
                                )
                            "
                            class="text-sm text-muted-foreground"
                        >
                            No submitted dishes
                        </p>
                        <ul>
                            <li
                                v-for="item in participant.items.filter(
                                    (item) => !item.superseded,
                                )"
                                :key="item.id"
                                class="grid grid-cols-[40px_minmax(0,1fr)_auto] items-center gap-x-2 gap-y-2 border-b border-border/30 px-2 py-3 last:border-0 sm:grid-cols-[44px_minmax(0,1fr)_auto_auto] sm:gap-x-3"
                                :class="
                                    item.decision === 'rejected'
                                        ? 'bg-[#fff6f8]'
                                        : ''
                                "
                            >
                                <img
                                    v-if="dishImage(item.name)"
                                    :src="dishImage(item.name)!"
                                    alt=""
                                    class="size-10 sm:size-11"
                                    :class="
                                        dishImageCropped(item.name)
                                            ? 'rounded-full object-cover'
                                            : 'object-contain'
                                    "
                                />
                                <span
                                    v-else
                                    aria-hidden="true"
                                    class="grid size-10 place-items-center rounded-full bg-[#e8ffea] text-primary sm:size-11"
                                    >{{ item.name[0] }}</span
                                >
                                <div class="col-span-2 min-w-0 sm:col-span-1">
                                    <p class="text-sm leading-5 wrap-anywhere">
                                        {{ item.name }}
                                    </p>
                                    <p
                                        class="text-xs leading-5 text-muted-foreground"
                                    >
                                        Qty: {{ item.quantity
                                        }}<span class="sr-only">
                                            ·
                                            {{ dollarPrice(item.unit_price) }}
                                            each</span
                                        >
                                    </p>
                                    <p
                                        v-if="item.notes"
                                        class="text-xs leading-5 wrap-anywhere text-muted-foreground"
                                    >
                                        {{ item.notes }}
                                    </p>
                                    <p class="sr-only">
                                        {{
                                            item.superseded
                                                ? 'Replaced · retained in history'
                                                : item.decision
                                        }}
                                    </p>
                                </div>
                                <span
                                    class="col-start-2 text-right text-sm font-medium whitespace-nowrap tabular-nums sm:col-auto"
                                    >{{ dollarPrice(item.total) }}</span
                                >
                                <div
                                    class="flex shrink-0 gap-2"
                                    role="group"
                                    :aria-label="`Decision for ${item.name} (${participant.name})`"
                                >
                                    <button
                                        type="button"
                                        :aria-label="`Reject ${item.name} (${participant.name})`"
                                        :aria-pressed="
                                            item.decision === 'rejected'
                                        "
                                        :disabled="
                                            busy ||
                                            stale ||
                                            !review.editable ||
                                            cutoffLocked ||
                                            item.published ||
                                            item.superseded
                                        "
                                        class="grid size-11 place-items-center rounded-md border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-default sm:size-8"
                                        :class="
                                            item.decision === 'rejected'
                                                ? 'border-[#d00000] bg-[#d00000] text-white'
                                                : 'border-[#9b9b9b] bg-white text-[#8b8b8b]'
                                        "
                                        @click="
                                            request(
                                                `/items/${item.id}`,
                                                'patch',
                                                { decision: 'rejected' },
                                            )
                                        "
                                    >
                                        <X class="size-5" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        :aria-label="`Confirm ${item.name} (${participant.name})`"
                                        :aria-pressed="
                                            item.decision === 'confirmed'
                                        "
                                        :disabled="
                                            busy ||
                                            stale ||
                                            !review.editable ||
                                            cutoffLocked ||
                                            item.published ||
                                            item.superseded
                                        "
                                        class="grid size-11 place-items-center rounded-md border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-default sm:size-8"
                                        :class="
                                            item.decision === 'confirmed'
                                                ? 'border-[#0f7a37] bg-[#0f7a37] text-white'
                                                : 'border-[#9b9b9b] bg-white text-[#8b8b8b]'
                                        "
                                        @click="
                                            request(
                                                `/items/${item.id}`,
                                                'patch',
                                                { decision: 'confirmed' },
                                            )
                                        "
                                    >
                                        <Check
                                            class="size-5"
                                            aria-hidden="true"
                                        />
                                    </button>
                                </div>
                            </li>
                        </ul>
                        <details
                            v-if="
                                participant.items.some(
                                    (item) => item.superseded,
                                )
                            "
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            <summary class="min-h-11 cursor-pointer py-3">
                                Earlier replaced or canceled dishes
                            </summary>
                            <p
                                v-for="item in participant.items.filter(
                                    (item) => item.superseded,
                                )"
                                :key="item.id"
                                class="py-2"
                            >
                                {{ item.name }} · Qty: {{ item.quantity }} ·
                                {{ dollarPrice(item.total) }} ·
                                {{
                                    item.cancelled
                                        ? 'Canceled at the 30-minute deadline'
                                        : item.decision
                                }}
                            </p>
                        </details>
                    </section>
                    <dl class="sr-only">
                        <dt>Requested (active items)</dt>
                        <dd class="text-right">
                            {{ dollarPrice(review.totals.requested) }}
                        </dd>
                        <dt>Confirmed</dt>
                        <dd class="text-right">
                            {{ dollarPrice(review.totals.confirmed) }}
                        </dd>
                        <dt>Rejected · excluded</dt>
                        <dd class="text-right">
                            {{ dollarPrice(review.totals.rejected) }}
                        </dd>
                        <dt>Pending review</dt>
                        <dd class="text-right">
                            {{ dollarPrice(review.totals.pending) }}
                        </dd>
                    </dl>
                </template>
            </div>
            <footer
                v-if="review"
                class="mx-4 shrink-0 space-y-3 border-t border-primary/15 py-6 sm:mx-10 sm:pb-10"
            >
                <Button
                    class="min-h-14 w-full disabled:bg-[#8b8b8b]! disabled:bg-none! disabled:text-white! disabled:opacity-100"
                    :disabled="
                        busy ||
                        stale ||
                        !review.editable ||
                        !review.can_submit ||
                        cutoffLocked
                    "
                    @click="request('/submit', 'post')"
                    >Submit Pre-order</Button
                >
            </footer>
        </DialogContent>
    </Dialog>
</template>

<style>
.green-tasty.restaurant-review-dialog[data-slot='dialog-content'] {
    width: calc(100% - 32px);
    max-width: 838px;
    padding: 0;
    gap: 0;
    border: 0;
    box-shadow: 0 12px 40px rgb(0 0 0 / 14%);
}
</style>
