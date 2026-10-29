<script setup lang="ts">
import { usePreOrderClock } from '@/composables/usePreOrderClock';
import {
    computed,
    ref,
    shallowRef,
    watch,
    nextTick,
    onBeforeUnmount,
} from 'vue';
import GuestRemovalModal from './GuestRemovalModal.vue';
import OwnerRestaurantReview from './OwnerRestaurantReview.vue';
import type { OwnerGroupParticipant } from '@/types/owner-group-order';
import {
    MapPin,
    CalendarDays,
    Clock,
    Users,
    Trash2,
    X,
    Bell,
    CircleCheck,
} from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import QuantityControl from '@/components/guest/QuantityControl.vue';
import { dollarPrice } from '@/lib/guestSelection';
import { Button } from '@/components/ui/button';
import { useOwnerGroupOrder } from '@/composables/useOwnerGroupOrder';
import { bookingDate } from '@/lib/booking';
import { dishImage, dishImageCropped } from '@/lib/guestMenu';
import type { ReservationDetails } from '@/types/reservation';
const props = defineProps<{ reservation: ReservationDetails }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const { order, loading, busy, stale, error, submitted, load, mutate } =
    useOwnerGroupOrder(props.reservation.id, () => emit('changed'));
const { cutoffLocked, warning, minutes } = usePreOrderClock(
    computed(() => order.value?.timing),
);
let refreshAtCutoff = false;
watch(cutoffLocked, (locked) => {
    if (locked && order.value?.timing && !order.value.timing.locked)
        refreshAtCutoff = true;
});
watch([cutoffLocked, loading, busy], () => {
    if (refreshAtCutoff && !loading.value && !busy.value) {
        refreshAtCutoff = false;
        void load();
    }
});
const confirming = ref<'submit' | null>(null);
const removing = shallowRef<OwnerGroupParticipant | null>(null);
const removalTrigger = shallowRef<HTMLElement | null>(null);
const groupClose = ref<HTMLButtonElement | null>(null);
const scrollArea = ref<HTMLElement | null>(null);
const removalNotice = ref(false);
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(noticeTimer));
function askRemoval(guest: OwnerGroupParticipant, event: Event) {
    removalTrigger.value = event.currentTarget as HTMLElement;
    removing.value = guest;
}
async function removeGuest() {
    if (!removing.value || busy.value || stale.value || cutoffLocked.value)
        return;
    const top = scrollArea.value?.scrollTop ?? 0;
    if (await mutate(`/guests/${removing.value.id}`, 'delete')) {
        removalTrigger.value = groupClose.value;
        removing.value = null;
        removalNotice.value = true;
        clearTimeout(noticeTimer);
        noticeTimer = setTimeout(() => (removalNotice.value = false), 6000);
        await nextTick();
        if (scrollArea.value) scrollArea.value.scrollTop = top;
    }
}
const participants = computed(() =>
    [...(order.value?.participants ?? [])].sort(
        (a, b) => Number(b.is_owner) - Number(a.is_owner),
    ),
);
const ownerPresent = computed(() => participants.value.some((p) => p.is_owner));
const pending = computed(() =>
    order.value?.editable
        ? participants.value
              .filter((p) => !p.is_owner && p.confirmed && p.items.length)
              .map((p) => p.name)
        : [],
);
const status = computed(() =>
    order.value?.restaurant_review?.status === 'Confirmed by Restaurant'
        ? 'Confirmed by Restaurant'
        : order.value?.restaurant_review?.status === 'Updated Pre-Order'
          ? 'Updated Pre-Order'
          : order.value?.status === 'submitted'
            ? 'Sent to Restaurant'
            : order.value?.status === 'draft' || order.value?.status == null
              ? 'Created New Pre-Order'
              : `Stored status: ${order.value.status}`,
);
watch(confirming, async (value) => {
    await nextTick();
    document
        .getElementById(
            value === null ? 'owner-group-submit' : 'owner-group-cancel',
        )
        ?.focus();
});
let opener: HTMLElement | null = null;
function remember() {
    opener =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
}
function restore(event: Event) {
    const target = opener?.isConnected
        ? opener
        : document.querySelector<HTMLElement>(
              `a[href="/reservations/${props.reservation.id}/owner-pre-order"], a[href="/reservations/${props.reservation.id}"]`,
          );
    if (target) {
        event.preventDefault();
        target.focus();
    }
}
function close() {
    if (!busy.value) emit('close');
}
async function changeQuantity(itemId: number, quantity: number) {
    const top = scrollArea.value?.scrollTop ?? 0;
    const focused = document.activeElement as HTMLElement | null;
    const keepFocus = focused && scrollArea.value?.contains?.(focused);
    if (await mutate(`/items/${itemId}`, 'patch', { quantity })) {
        await nextTick();
        if (keepFocus && focused.isConnected)
            focused.focus({ preventScroll: true });
        if (scrollArea.value) scrollArea.value.scrollTop = top;
    }
}
async function confirm() {
    if (confirming.value === null) return;
    if (await mutate('/submit', 'post')) confirming.value = null;
}
</script>
<template>
    <Dialog :open="true" @update:open="!$event && close()">
        <DialogContent
            :show-close-button="false"
            class="green-tasty owner-group-dialog flex max-h-[calc(100dvh-32px)] w-[calc(100%_-_32px)] max-w-[838px] flex-col gap-0 overflow-hidden rounded-3xl bg-[#f7f7f7] p-0 font-[Poppins,sans-serif] text-foreground sm:max-w-[838px]"
            @open-auto-focus="remember"
            @close-auto-focus="restore"
            @escape-key-down="(busy || removing) && $event.preventDefault()"
            @interact-outside="$event.preventDefault()"
        >
            <div
                v-if="removalNotice"
                role="status"
                class="absolute top-3 right-16 z-[60] flex max-w-[calc(100%-80px)] items-center gap-3 rounded-lg border border-primary/40 bg-[#e8ffea] px-4 py-3 text-sm shadow-md"
            >
                <CircleCheck
                    class="size-5 shrink-0 text-primary"
                    aria-hidden="true"
                />
                Guest Removed
                <button
                    type="button"
                    aria-label="Dismiss guest removed notification"
                    class="grid size-8 place-items-center rounded focus-visible:outline-2"
                    @click="removalNotice = false"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </div>
            <header
                class="relative shrink-0 border-b border-primary/15 px-4 pt-6 pb-4 sm:px-10 sm:pt-8"
            >
                <div class="flex flex-wrap items-center gap-3 pr-10">
                    <DialogTitle class="text-[26px] leading-10 font-medium"
                        >Group Pre-Order</DialogTitle
                    ><span
                        v-if="order"
                        class="rounded-md px-3 py-1 text-xs leading-5"
                        :class="
                            status === 'Confirmed by Restaurant'
                                ? 'bg-[#e8ffea]'
                                : order.status === 'submitted'
                                  ? 'bg-[#fff3d1]'
                                  : 'bg-[#e4e4e4]'
                        "
                        >{{ status }}</span
                    >
                </div>
                <DialogDescription class="sr-only"
                    >Review saved participant selections and manage permitted
                    changes for this reservation.</DialogDescription
                >
                <dl
                    class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs leading-5"
                >
                    <div class="flex items-start gap-2">
                        <MapPin
                            class="size-4 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <dt class="sr-only">Location</dt>
                        <dd class="wrap-anywhere">
                            {{ reservation.restaurant.address }}
                        </dd>
                    </div>
                    <div class="flex items-center gap-2">
                        <CalendarDays
                            class="size-4 text-primary"
                            aria-hidden="true"
                        />
                        <dt class="sr-only">Date</dt>
                        <dd>{{ bookingDate(reservation.date) }}</dd>
                    </div>
                    <div class="flex items-center gap-2">
                        <Clock class="size-4 text-primary" aria-hidden="true" />
                        <dt class="sr-only">Time</dt>
                        <dd>
                            {{ reservation.time }} – {{ reservation.end_time
                            }}<span
                                v-if="reservation.end_date !== reservation.date"
                            >
                                ({{ reservation.end_date }})</span
                            ><span class="sr-only">
                                {{ reservation.timezone }}</span
                            >
                        </dd>
                    </div>
                    <div class="flex items-center gap-2">
                        <Users class="size-4 text-primary" aria-hidden="true" />
                        <dt class="sr-only">Guests</dt>
                        <dd>
                            {{ reservation.guest_count }} Guests<span
                                v-if="order?.removed_participants"
                                class="block text-muted-foreground"
                                >{{ order.active_participants }} active
                                participants · booked places unchanged</span
                            >
                        </dd>
                    </div>
                </dl>
                <button
                    type="button"
                    ref="groupClose"
                    aria-label="Close Group Pre-Order"
                    :disabled="busy"
                    class="absolute top-2 right-2 grid size-11 place-items-center rounded-md focus-visible:outline-2"
                    @click="close"
                >
                    <X class="size-5" aria-hidden="true" />
                </button>
            </header>
            <div
                ref="scrollArea"
                class="min-h-0 overflow-y-auto overscroll-contain px-4 py-5 sm:px-10"
                :aria-busy="loading || busy"
            >
                <OwnerRestaurantReview
                    v-if="order?.restaurant_review"
                    :review="order.restaurant_review"
                    :reservation-id="reservation.id"
                    :locked="cutoffLocked"
                    class="mb-5"
                    @changed="
                        load();
                        emit('changed');
                    "
                />
                <p v-if="loading" role="status" class="py-8">
                    Loading group pre-order…
                </p>
                <div
                    v-if="error"
                    role="alert"
                    class="mb-4 rounded-lg border border-destructive/20 bg-[#fff5f7] p-4 text-sm leading-6"
                >
                    {{ error }}
                    <button
                        type="button"
                        :disabled="busy || loading"
                        class="min-h-11 rounded px-3 font-medium underline focus-visible:outline-2"
                        @click="load"
                    >
                        Refresh order
                    </button>
                </div>
                <template v-if="submitted"
                    ><section
                        class="rounded-xl bg-card p-6 text-center"
                        role="status"
                    >
                        <h2 class="text-xl font-medium">Pre-Order Submitted</h2>
                        <p class="my-4 text-sm leading-6">
                            Your pre-order was sent successfully. Wait for
                            confirmation from the restaurant.
                        </p>
                        <Button class="min-h-11" @click="close"
                            >View Reservations</Button
                        >
                    </section></template
                >
                <template v-else-if="order">
                    <p
                        v-if="!order.editable || cutoffLocked"
                        role="status"
                        class="mb-4 flex gap-2 rounded-lg bg-[#fff5f7] p-3 text-sm leading-6"
                    >
                        <Bell
                            class="mt-1 size-4 shrink-0"
                            aria-hidden="true"
                        />{{
                            cutoffLocked
                                ? 'Less than 30 minutes before the reservation time. No changes can be applied.'
                                : 'This group pre-order is read-only. Saved quantities and prices are preserved.'
                        }}
                    </p>
                    <p
                        v-else-if="warning"
                        role="status"
                        class="mb-4 flex gap-2 rounded-lg bg-[#fff5f7] p-3 text-sm leading-6"
                    >
                        <Bell
                            class="mt-1 size-4 shrink-0"
                            aria-hidden="true"
                        />You have unconfirmed items in your pre-order. They
                        will be canceled in {{ minutes }}
                        {{ minutes === 1 ? 'minute' : 'minutes' }}.
                    </p>
                    <p
                        v-if="
                            order.status === 'submitted' &&
                            order.editable &&
                            !cutoffLocked
                        "
                        class="mb-4 text-sm leading-6 text-muted-foreground"
                    >
                        Changes are sent to the restaurant for review. Previous
                        versions are kept.
                    </p>
                    <p
                        v-else-if="pending.length"
                        role="status"
                        class="mb-4 flex gap-2 rounded-lg bg-[#fffbea] p-3 text-sm leading-6"
                    >
                        <Bell
                            class="mt-1 size-4 shrink-0"
                            aria-hidden="true"
                        />{{ pending.join(', ') }}
                        {{ pending.length === 1 ? 'has' : 'have' }} confirmed a
                        pre-order and
                        {{ pending.length === 1 ? 'is' : 'are' }} waiting for
                        you to submit the group.
                    </p>
                    <details
                        v-if="order.cancelled_items?.length"
                        class="mb-5 rounded-xl bg-card p-4 text-sm"
                    >
                        <summary
                            class="min-h-11 cursor-pointer py-3 font-medium"
                        >
                            Canceled at the 30-minute deadline
                        </summary>
                        <p class="mb-3 text-muted-foreground">
                            These dishes were not confirmed by the restaurant
                            and are excluded from the total.
                        </p>
                        <p
                            v-for="item in order.cancelled_items"
                            :key="item.id"
                            class="py-2"
                        >
                            {{ item.participant }} · {{ item.name }} ×
                            {{ item.quantity }} ·
                            {{ dollarPrice(item.unit_price) }} each
                            <span v-if="item.notes"> · {{ item.notes }}</span>
                        </p>
                    </details>
                    <section
                        v-if="!ownerPresent"
                        class="mb-5 rounded-xl bg-card p-4"
                    >
                        <h2 class="font-medium">My Pre-Order</h2>
                        <p class="mt-3 text-sm text-muted-foreground">
                            No saved owner pre-order.
                        </p>
                    </section>
                    <section
                        v-for="participant in participants"
                        :key="participant.id"
                        :aria-label="
                            participant.is_owner
                                ? 'My Pre-Order'
                                : `Guest: ${participant.name}`
                        "
                        class="mb-5 rounded-xl bg-card p-4 last:mb-0"
                    >
                        <div
                            class="mb-3 flex flex-wrap items-center justify-between gap-2"
                        >
                            <h2
                                class="min-w-0 text-base font-medium wrap-anywhere"
                            >
                                {{
                                    participant.is_owner
                                        ? 'My Pre-Order'
                                        : `Guest: ${participant.name}`
                                }}
                            </h2>
                            <a
                                v-if="
                                    participant.is_owner &&
                                    participant.editable &&
                                    !cutoffLocked
                                "
                                :href="`/reservations/${reservation.id}/owner-pre-order`"
                                class="inline-flex min-h-11 items-center rounded text-sm font-medium underline underline-offset-8 focus-visible:outline-2"
                                :aria-disabled="busy || undefined"
                                @click="busy && $event.preventDefault()"
                                >{{
                                    participant.items.length
                                        ? 'Edit my Pre-Order'
                                        : 'Edit Pre-Order'
                                }}</a
                            ><button
                                v-if="participant.can_remove && !cutoffLocked"
                                type="button"
                                :disabled="busy || stale"
                                class="min-h-11 rounded text-sm font-medium underline underline-offset-8 focus-visible:outline-2"
                                @click="askRemoval(participant, $event)"
                            >
                                Remove Guest<span class="sr-only">
                                    {{ participant.name }}</span
                                >
                            </button>
                        </div>
                        <p
                            v-if="!participant.items.length"
                            class="pb-4 text-sm text-muted-foreground"
                        >
                            {{
                                participant.revoked
                                    ? 'Invitation revoked. No saved dishes.'
                                    : 'No saved dishes yet.'
                            }}
                        </p>
                        <ul
                            v-else
                            class="divide-y divide-border/40"
                            :aria-label="`Saved dishes for ${participant.name}`"
                        >
                            <li
                                v-for="item in participant.items"
                                :key="item.display_key ?? item.id"
                                class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-center gap-3 py-3 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto]"
                            >
                                <div class="flex items-center gap-1">
                                    <button
                                        v-if="
                                            participant.editable &&
                                            !cutoffLocked
                                        "
                                        type="button"
                                        :aria-label="`Remove ${item.name} for ${participant.name}`"
                                        :disabled="busy || stale"
                                        class="grid size-11 shrink-0 place-items-center rounded focus-visible:outline-2"
                                        @click="
                                            mutate(
                                                `/items/${item.id}`,
                                                'patch',
                                                { quantity: 0 },
                                            )
                                        "
                                    >
                                        <Trash2
                                            class="size-5 text-muted-foreground"
                                            aria-hidden="true"
                                        /></button
                                    ><img
                                        v-if="dishImage(item.name)"
                                        :src="dishImage(item.name)!"
                                        :alt="item.name"
                                        width="44"
                                        height="44"
                                        class="size-11"
                                        :class="
                                            dishImageCropped(item.name)
                                                ? 'rounded-full object-cover'
                                                : 'object-contain'
                                        "
                                    /><span
                                        v-else
                                        aria-hidden="true"
                                        class="grid size-11 place-items-center rounded-full bg-[#e8ffea] text-primary"
                                        >{{ item.name.charAt(0) }}</span
                                    >
                                </div>
                                <div class="min-w-0">
                                    <p
                                        class="text-sm font-medium wrap-anywhere"
                                    >
                                        {{ item.name }}
                                        <span
                                            v-if="item.decision === 'rejected'"
                                            class="ml-2 rounded bg-[#fce8ee] px-2 py-1 text-xs font-normal"
                                            >Rejected</span
                                        >
                                    </p>
                                    <p
                                        v-if="item.notes"
                                        class="mt-1 text-xs leading-5 wrap-anywhere text-muted-foreground"
                                    >
                                        {{ item.notes }}
                                    </p>
                                    <p class="sr-only">
                                        Saved unit price:
                                        {{ dollarPrice(item.unit_price) }}
                                    </p>
                                </div>
                                <QuantityControl
                                    v-if="participant.editable && !cutoffLocked"
                                    :name="`${item.name} for ${participant.name}`"
                                    :quantity="item.quantity"
                                    :disabled="busy || stale"
                                    class="col-start-2 sm:col-start-auto"
                                    @change="changeQuantity(item.id, $event)"
                                />
                                <p
                                    v-else
                                    class="col-start-2 text-xs text-muted-foreground sm:col-start-auto"
                                >
                                    Qty: {{ item.quantity }}
                                </p>
                                <p
                                    class="col-start-2 text-right text-sm font-medium tabular-nums sm:col-start-auto sm:w-24"
                                >
                                    {{ dollarPrice(item.subtotal) }}
                                </p>
                            </li>
                        </ul>
                        <div
                            class="mt-3 flex justify-between gap-4 border-t border-border/40 pt-4 text-sm font-medium"
                        >
                            <span>Subtotal</span
                            ><span class="tabular-nums">{{
                                dollarPrice(participant.subtotal)
                            }}</span>
                        </div>
                    </section>
                </template>
            </div>
            <footer
                v-if="order && !submitted"
                class="max-h-[45dvh] shrink-0 overflow-y-auto border-t border-primary/15 bg-[#f7f7f7] px-4 py-4 sm:px-10 sm:pb-8"
            >
                <div
                    v-if="confirming !== null && !cutoffLocked"
                    role="group"
                    aria-label="Confirm group action"
                    class="mt-4 rounded-xl border border-primary/20 bg-accent p-4"
                >
                    <p class="text-sm leading-6">
                        Send all saved selections to the restaurant for review?
                        You can make changes until 30 minutes before your
                        reservation.
                    </p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <Button
                            id="owner-group-cancel"
                            class="min-h-11"
                            variant="outline"
                            :disabled="busy"
                            @click="confirming = null"
                            >Keep reviewing</Button
                        ><Button
                            class="min-h-11"
                            :disabled="busy || stale"
                            @click="confirm"
                            >{{
                                busy ? 'Saving…' : 'Confirm submission'
                            }}</Button
                        >
                    </div>
                </div>
                <div
                    class="mb-4 flex items-center justify-between gap-4 text-xl font-medium"
                >
                    <span>{{
                        order?.restaurant_review
                            ? 'Requested total (includes rejections)'
                            : 'Grand Total'
                    }}</span
                    ><output
                        aria-label="Group pre-order total"
                        class="text-primary tabular-nums"
                        >{{ dollarPrice(order.summary.grand_total) }}</output
                    >
                </div>
                <Button
                    v-if="!cutoffLocked"
                    id="owner-group-submit"
                    class="min-h-14 w-full"
                    :disabled="
                        cutoffLocked ||
                        !order.can_submit ||
                        busy ||
                        stale ||
                        loading ||
                        confirming !== null
                    "
                    @click="confirming = 'submit'"
                    >{{ busy ? 'Saving…' : 'Submit Pre-Order' }}</Button
                >
            </footer>
        </DialogContent>
    </Dialog>
    <GuestRemovalModal
        :guest-name="removing?.name ?? ''"
        :open="removing !== null"
        :busy="busy"
        :blocked="stale || cutoffLocked"
        :error="error"
        :return-focus-target="removalTrigger"
        @close="removing = null"
        @confirm="removeGuest"
    />
</template>

<style>
.green-tasty.owner-group-dialog[data-slot='dialog-content'] {
    width: calc(100% - 32px);
    max-width: 838px;
    padding: 0;
    gap: 0;
}
</style>
