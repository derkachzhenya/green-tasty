<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import {
    Plus,
    Trash2,
    X,
    MapPin,
    CalendarDays,
    Clock,
    Users,
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
import { cents, decimal, dollarPrice } from '@/lib/guestSelection';
import { dishImage } from '@/lib/guestMenu';
import type {
    ServiceOrder,
    ServiceOrderLine,
    WaiterReservation,
} from '@/types/restaurant-review';
const props = defineProps<{
    restaurantId: number;
    reservation: WaiterReservation;
}>();
const emit = defineEmits<{ close: []; changed: [] }>();
const order = shallowRef<ServiceOrder | null>(null);
const lines = ref<ServiceOrderLine[]>([]);
const dishId = ref('');
const busy = ref(false),
    stale = ref(false),
    error = ref(''),
    notice = ref('');
const controller = new AbortController();
const root = `/staff/restaurants/${props.restaurantId}/reservations/${props.reservation.id}/order`;
const editable = computed(
    () => !!order.value?.editable && !busy.value && !stale.value,
);
const total = computed(() =>
    decimal(
        lines.value.reduce(
            (sum, line) => sum + cents(line.unit_price) * BigInt(line.quantity),
            0n,
        ),
    ),
);
const changed = computed(
    () =>
        JSON.stringify(lines.value) !==
        JSON.stringify(order.value?.items ?? []),
);
const title = computed(() =>
    !order.value?.editable
        ? 'View Order'
        : order.value.exists || order.value.items.length
          ? 'Edit Order'
          : 'Create Order',
);
let trigger: HTMLElement | null = null;
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
function cancelChanges() {
    if (!order.value) return;
    lines.value = structuredClone(order.value.items);
    notice.value = '';
}
function addDish() {
    if (!editable.value) return;
    const dish = order.value?.catalog.find(
        (item) => item.id === Number(dishId.value) && item.is_available,
    );
    if (!dish) return;
    const key = `new-${dish.id}`,
        line = lines.value.find((item) => item.line_key === key);
    if (line) {
        if (line.quantity < 99) line.quantity++;
    } else
        lines.value.push({
            line_key: key,
            menu_item_id: dish.id,
            name: dish.name,
            quantity: 1,
            unit_price: dish.price,
            notes: null,
            total: dish.price,
        });
    dishId.value = '';
}
async function request(save = false) {
    if (busy.value || (save && (!editable.value || !lines.value.length)))
        return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const result = (await sessionJson(
            root,
            save ? 'put' : 'get',
            controller.signal,
            save
                ? {
                      items: lines.value.map(
                          ({ line_key, menu_item_id, quantity, notes }) => ({
                              line_key,
                              menu_item_id,
                              quantity,
                              notes,
                          }),
                      ),
                  }
                : undefined,
            save ? order.value?.version : undefined,
        )) as ServiceOrder;
        order.value = result;
        lines.value = structuredClone(result.items);
        stale.value = false;
        if (save) {
            notice.value = 'Order saved.';
            emit('changed');
        }
    } catch (reason) {
        stale.value = !(
            reason instanceof SessionRequestError && reason.status === 422
        );
        if (
            reason instanceof SessionRequestError &&
            [401, 403, 404, 419].includes(reason.status)
        ) {
            order.value = null;
            lines.value = [];
        }
        error.value =
            reason instanceof SessionRequestError && reason.status === 422
                ? Object.values(reason.errors).flat().join(' ')
                : 'The order could not be confirmed. Refresh before continuing. Unsaved changes have not been submitted again.';
    } finally {
        busy.value = false;
    }
}
onMounted(() => void request());
onBeforeUnmount(() => controller.abort());
</script>
<template>
    <Dialog :open="true" @update:open="!busy && emit('close')">
        <DialogContent
            :show-close-button="false"
            class="green-tasty service-order-dialog flex max-h-[calc(100dvh-32px)] w-[calc(100%-32px)] flex-col gap-0 overflow-hidden rounded-3xl bg-[#f7f7f7] p-0 font-[Poppins,sans-serif] sm:max-w-[760px]"
            @open-auto-focus="remember"
            @close-auto-focus="restore"
            @escape-key-down="busy && $event.preventDefault()"
            @interact-outside="$event.preventDefault()"
        >
            <button
                type="button"
                aria-label="Close order"
                class="absolute top-3 right-3 grid size-11 place-items-center rounded-lg"
                :disabled="busy"
                @click="emit('close')"
            >
                <X class="size-5" />
            </button>
            <header
                class="shrink-0 border-b border-primary/15 px-4 pt-8 pb-5 sm:px-8"
            >
                <DialogTitle class="pr-10 text-2xl font-medium">{{
                    title
                }}</DialogTitle>
                <DialogDescription class="mt-2 text-xs wrap-anywhere"
                    >Reservation: {{ reservation.code }} ·
                    {{ reservation.owner_name }}</DialogDescription
                >
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs">
                    <span class="inline-flex items-center gap-1"
                        ><MapPin class="size-4 text-primary" />{{
                            reservation.restaurant.address
                        }}
                        · {{ reservation.table?.name }}</span
                    >
                    <span class="inline-flex items-center gap-1"
                        ><CalendarDays class="size-4 text-primary" />{{
                            bookingDate(reservation.date)
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
                class="min-h-0 space-y-4 overflow-y-auto p-4 sm:p-8"
                :aria-busy="busy"
            >
                <p v-if="busy" role="status">Loading or saving order…</p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <Button
                    v-if="stale"
                    variant="outline"
                    :disabled="busy"
                    @click="request()"
                    >Refresh order</Button
                >
                <p
                    v-if="notice"
                    role="status"
                    class="rounded-lg bg-[#e8ffea] p-3 text-sm"
                >
                    {{ notice }}
                </p>
                <template v-if="order">
                    <p
                        v-if="!order.editable"
                        class="text-sm text-muted-foreground"
                    >
                        {{
                            order.blocked_reason ??
                            'This order is read-only. Orders can be changed while the visit is in progress.'
                        }}
                    </p>
                    <p
                        v-else-if="!order.exists && order.items.length"
                        class="text-sm text-muted-foreground"
                    >
                        Confirmed pre-order dishes are included in this order.
                    </p>
                    <p
                        v-if="!lines.length"
                        class="rounded-xl bg-white p-6 text-center text-sm"
                    >
                        No dishes in this order.
                    </p>
                    <ul aria-label="Service order dishes" class="space-y-3">
                        <li
                            v-for="line in lines"
                            :key="line.line_key"
                            class="rounded-xl bg-white p-4"
                        >
                            <div class="flex items-start gap-3">
                                <img
                                    v-if="dishImage(line.name)"
                                    :src="dishImage(line.name)!"
                                    alt=""
                                    class="size-12 shrink-0 rounded-full object-contain"
                                />
                                <span
                                    v-else
                                    aria-hidden="true"
                                    class="grid size-12 shrink-0 place-items-center rounded-full bg-accent"
                                    >{{ line.name[0] }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <h3
                                        class="text-sm font-medium wrap-anywhere"
                                    >
                                        {{ line.name }}
                                    </h3>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        {{ dollarPrice(line.unit_price) }} each
                                    </p>
                                </div>
                                <button
                                    v-if="order.editable"
                                    type="button"
                                    :aria-label="`Remove ${line.name}`"
                                    :disabled="!editable"
                                    class="grid size-11 shrink-0 place-items-center rounded-lg disabled:opacity-50"
                                    @click="
                                        lines = lines.filter(
                                            (item) =>
                                                item.line_key !== line.line_key,
                                        )
                                    "
                                >
                                    <Trash2 class="size-5" />
                                </button>
                            </div>
                            <div
                                class="mt-3 flex flex-wrap items-center justify-between gap-3"
                            >
                                <div
                                    v-if="order.editable"
                                    class="inline-flex items-center gap-3"
                                    role="group"
                                    :aria-label="`Quantity for ${line.name}`"
                                >
                                    <Button
                                        variant="outline"
                                        :aria-label="`Decrease ${line.name}`"
                                        :disabled="
                                            !editable || line.quantity <= 1
                                        "
                                        @click="line.quantity--"
                                        >−</Button
                                    >
                                    <span>{{ line.quantity }}</span>
                                    <Button
                                        variant="outline"
                                        :aria-label="`Increase ${line.name}`"
                                        :disabled="
                                            !editable || line.quantity >= 99
                                        "
                                        @click="line.quantity++"
                                        >+</Button
                                    >
                                </div>
                                <span v-else class="text-sm"
                                    >Qty: {{ line.quantity }}</span
                                >
                                <span
                                    class="text-sm font-medium text-primary"
                                    >{{
                                        dollarPrice(
                                            decimal(
                                                cents(line.unit_price) *
                                                    BigInt(line.quantity),
                                            ),
                                        )
                                    }}</span
                                >
                            </div>
                            <label
                                v-if="order.editable"
                                class="mt-3 grid gap-1 text-xs"
                                >Notes for {{ line.name
                                }}<input
                                    v-model="line.notes"
                                    maxlength="500"
                                    :disabled="!editable"
                                    class="min-h-11 min-w-0 rounded-lg border px-3 text-sm"
                            /></label>
                            <p v-else-if="line.notes" class="mt-2 text-xs">
                                {{ line.notes }}
                            </p>
                        </li>
                    </ul>
                    <form
                        v-if="order.editable"
                        class="grid gap-3 rounded-xl bg-white p-4"
                        @submit.prevent="addDish"
                    >
                        <label class="grid gap-2 text-sm"
                            >Add a dish<select
                                v-model="dishId"
                                class="booking-input min-h-12 w-full"
                                :disabled="!editable"
                            >
                                <option value="">Choose a dish</option>
                                <option
                                    v-for="dish in order.catalog"
                                    :key="dish.id"
                                    :value="dish.id"
                                    :disabled="!dish.is_available"
                                >
                                    {{ dish.name }} ·
                                    {{ dollarPrice(dish.price)
                                    }}{{
                                        dish.is_available ? '' : ' · Sold Out'
                                    }}
                                </option>
                            </select></label
                        >
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="!editable || !dishId"
                            ><Plus class="size-4" /> Add to order</Button
                        >
                    </form>
                </template>
            </div>
            <footer
                v-if="order"
                class="shrink-0 space-y-4 border-t border-primary/15 px-4 py-4 sm:px-8"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="font-medium">Grand Total</span
                    ><strong class="text-xl font-medium text-primary">{{
                        dollarPrice(total)
                    }}</strong>
                </div>
                <div v-if="order.editable" class="flex flex-wrap gap-3">
                    <Button
                        variant="outline"
                        class="min-h-12 flex-1"
                        :disabled="!editable || !changed"
                        @click="cancelChanges"
                        >Cancel Changes</Button
                    >
                    <Button
                        class="min-h-12 flex-1"
                        :disabled="
                            !editable ||
                            !lines.length ||
                            (order.exists && !changed)
                        "
                        @click="request(true)"
                        >Save Order</Button
                    >
                </div>
            </footer>
        </DialogContent>
    </Dialog>
</template>
<style>
.green-tasty.service-order-dialog[data-slot='dialog-content'] {
    width: calc(100% - 32px);
    max-width: 760px;
    padding: 0;
}
</style>
