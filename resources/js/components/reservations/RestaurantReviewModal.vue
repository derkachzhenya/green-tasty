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
import { dishImage } from '@/lib/guestMenu';
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
    if (busy.value || (method !== 'get' && (stale.value || cutoffLocked.value)))
        return;
    busy.value = true;
    error.value = '';
    notice.value = '';
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
        if (path === '/submit')
            notice.value =
                'Restaurant review saved. The owner can see the result.';
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
onBeforeUnmount(() => controller.abort());
</script>
<template>
    <Dialog :open="true" @update:open="!busy && emit('close')">
        <DialogContent
            :show-close-button="false"
            class="green-tasty restaurant-review-dialog flex max-h-[calc(100dvh-32px)] w-[calc(100%-32px)] max-w-[838px] flex-col gap-0 overflow-hidden rounded-3xl bg-[#f7f7f7] p-0 font-[Poppins,sans-serif] sm:max-w-[838px]"
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
                class="mx-4 shrink-0 border-b border-primary/15 pt-8 pb-4 sm:mx-10"
            >
                <div class="flex flex-wrap items-center gap-3 pr-10">
                    <DialogTitle class="text-xl font-medium sm:text-2xl"
                        >Reservation Pre-Order</DialogTitle
                    ><span
                        v-if="review"
                        class="rounded px-2 py-1 text-xs"
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
                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs">
                    <span class="inline-flex items-center gap-1"
                        ><MapPin class="size-4 text-primary" />{{
                            reservation.restaurant.address
                        }}</span
                    >
                    <span class="inline-flex items-center gap-1"
                        ><CalendarDays class="size-4 text-primary" />{{
                            bookingDate(reservation.date ?? '')
                        }}</span
                    >
                    <span class="inline-flex items-center gap-1"
                        ><Clock class="size-4 text-primary" />{{
                            reservation.time
                        }}
                        – {{ reservation.end_time }}</span
                    >
                    <span class="inline-flex items-center gap-1"
                        ><Users class="size-4 text-primary" />{{
                            reservation.guest_count
                        }}
                        Guests</span
                    >
                </div>
            </header>
            <div
                class="min-h-0 space-y-6 overflow-y-auto p-4 sm:px-10 sm:py-6"
                :aria-busy="busy"
            >
                <p v-if="busy" role="status">Saving or loading review…</p>
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
                <p
                    v-if="notice"
                    role="status"
                    class="rounded bg-[#e8ffea] p-3 text-xs"
                >
                    {{ notice }}
                </p>
                <template v-if="review">
                    <p
                        v-if="
                            review.participants.some((p) =>
                                p.items.some(
                                    (i) =>
                                        i.decision === 'rejected' &&
                                        !i.superseded,
                                ),
                            )
                        "
                        role="status"
                        class="flex items-center gap-2 rounded-md border border-destructive/10 bg-[#fff6f8] p-3 text-xs"
                    >
                        <Bell class="size-4 shrink-0 text-muted-foreground" />
                        <span>
                            Some items were rejected.
                            {{
                                review.editable
                                    ? 'Owner will be notified after you submit.'
                                    : 'Owner was notified about it.'
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
                        v-for="participant in review.participants"
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
                                class="flex flex-wrap items-center gap-3 border-b border-border/30 px-1 py-2 last:border-0"
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
                                    class="size-10 rounded-full object-contain"
                                />
                                <span
                                    v-else
                                    aria-hidden="true"
                                    class="grid size-10 place-items-center rounded-full bg-accent"
                                    >{{ item.name[0] }}</span
                                >
                                <div class="min-w-0 flex-1 basis-28 text-xs">
                                    <p class="wrap-anywhere">{{ item.name }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        Qty: {{ item.quantity
                                        }}<span class="sr-only">
                                            ·
                                            {{ dollarPrice(item.unit_price) }}
                                            each</span
                                        >
                                    </p>
                                    <p v-if="item.notes" class="text-xs">
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
                                <span class="text-sm font-medium">{{
                                    dollarPrice(item.total)
                                }}</span>
                                <div
                                    class="ml-auto flex gap-1"
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
                                        class="grid size-11 place-items-center rounded-md border disabled:cursor-default sm:size-8"
                                        :class="
                                            item.decision === 'rejected'
                                                ? 'border-destructive bg-destructive text-white'
                                                : 'border-border'
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
                                        class="grid size-11 place-items-center rounded-md border disabled:cursor-default sm:size-8"
                                        :class="
                                            item.decision === 'confirmed'
                                                ? 'border-primary bg-primary text-white'
                                                : 'border-border'
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
                    <details class="text-sm">
                        <summary class="min-h-11 cursor-pointer py-3">
                            Review totals
                        </summary>
                        <dl class="grid grid-cols-2 gap-2">
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
                    </details>
                </template>
            </div>
            <footer
                v-if="review"
                class="mx-4 shrink-0 space-y-3 border-t border-primary/15 py-6 sm:mx-10 sm:pb-10"
            >
                <Button
                    class="min-h-12 w-full disabled:bg-[#8b8b8b]! disabled:bg-none! disabled:text-white! disabled:opacity-100"
                    :disabled="
                        busy || stale || !review.can_submit || cutoffLocked
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
