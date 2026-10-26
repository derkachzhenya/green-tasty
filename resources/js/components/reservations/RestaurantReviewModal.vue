<script setup lang="ts">
import { ref, shallowRef, onMounted, onBeforeUnmount } from 'vue';
import { Check, X } from '@lucide/vue';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
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
const busy = ref(false),
    stale = ref(false),
    error = ref(''),
    notice = ref('');
const controller = new AbortController();
let trigger: HTMLElement | null = null;
const root = `/staff/restaurants/${props.restaurantId}/reservations/${props.reservation.id}/review`;
async function request(
    path = '',
    method: 'get' | 'patch' | 'post' = 'get',
    body?: Record<string, unknown>,
) {
    if (busy.value || (method !== 'get' && stale.value)) return;
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
                class="shrink-0 border-b border-primary/15 px-4 pt-8 pb-5 sm:px-8"
            >
                <div class="flex flex-wrap items-center gap-3 pr-10">
                    <DialogTitle class="text-xl font-medium sm:text-2xl"
                        >Reservation Pre-Order</DialogTitle
                    ><span
                        v-if="review"
                        class="rounded bg-accent px-2 py-1 text-xs"
                        >{{ review.status }}</span
                    >
                </div>
                <DialogDescription class="mt-2 text-xs wrap-anywhere"
                    >Reservation: {{ reservation.code }}</DialogDescription
                >
                <p class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs">
                    <span>{{ reservation.restaurant.address }}</span
                    ><span>{{ reservation.date }}</span
                    ><span
                        >{{ reservation.time }} – {{ reservation.end_time }} ·
                        Asia/Tbilisi</span
                    ><span>{{ reservation.guest_count }} Guests</span>
                </p>
            </header>
            <div
                class="min-h-0 space-y-4 overflow-y-auto p-4 sm:p-8"
                :aria-busy="busy"
            >
                <p v-if="busy" role="status">Saving or loading review…</p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded bg-destructive/10 p-3 text-sm text-destructive"
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
                    class="rounded bg-[#e8ffea] p-3 text-sm"
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
                        class="rounded border border-destructive/10 bg-[#fff6f8] p-3 text-sm"
                    >
                        Some items were rejected.
                        {{
                            review.editable
                                ? 'The owner will be notified after you submit.'
                                : 'The owner can see the published result.'
                        }}
                    </p>
                    <section
                        v-for="participant in review.participants"
                        :key="participant.id"
                        :aria-label="`${participant.is_owner ? 'Owner' : 'Guest'}: ${participant.name}`"
                        class="rounded-xl bg-white p-4"
                    >
                        <h3 class="mb-3 font-medium">
                            {{ participant.is_owner ? 'Owner' : 'Guest' }}:
                            {{ participant.name }}
                        </h3>
                        <p
                            v-if="!participant.items.length"
                            class="text-sm text-muted-foreground"
                        >
                            No submitted dishes
                        </p>
                        <ul>
                            <li
                                v-for="item in participant.items"
                                :key="item.id"
                                class="flex flex-wrap items-center gap-3 border-b border-border/50 py-3 last:border-0"
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
                                <div class="min-w-0 flex-1 basis-28 text-sm">
                                    <p class="wrap-anywhere">{{ item.name }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        Qty: {{ item.quantity }} ·
                                        {{ item.unit_price }} each
                                    </p>
                                    <p v-if="item.notes" class="text-xs">
                                        {{ item.notes }}
                                    </p>
                                    <p class="text-xs">
                                        {{
                                            item.superseded
                                                ? 'Replaced · retained in history'
                                                : item.decision
                                        }}
                                    </p>
                                </div>
                                <span class="text-sm">{{ item.total }}</span>
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
                                            item.published ||
                                            item.superseded
                                        "
                                        class="grid size-11 place-items-center rounded border disabled:cursor-default"
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
                                            item.published ||
                                            item.superseded
                                        "
                                        class="grid size-11 place-items-center rounded border disabled:cursor-default"
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
                    </section>
                </template>
            </div>
            <footer
                v-if="review"
                class="shrink-0 space-y-3 border-t border-primary/15 px-4 py-4 sm:px-8"
            >
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <dt>Requested (active items)</dt>
                    <dd class="text-right">{{ review.totals.requested }}</dd>
                    <dt>Confirmed</dt>
                    <dd class="text-right">{{ review.totals.confirmed }}</dd>
                    <dt>Rejected · excluded</dt>
                    <dd class="text-right">{{ review.totals.rejected }}</dd>
                    <dt>Pending review</dt>
                    <dd class="text-right">{{ review.totals.pending }}</dd>
                </dl>
                <Button
                    class="min-h-12 w-full"
                    :disabled="busy || stale || !review.can_submit"
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
}
</style>
