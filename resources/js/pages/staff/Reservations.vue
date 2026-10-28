<script setup lang="ts">
import { Head, usePage, router, Link } from '@inertiajs/vue3';
import { ref, shallowRef, computed, onMounted, onBeforeUnmount } from 'vue';
import {
    CalendarDays,
    Clock,
    Users,
    Trash2,
    Pencil,
    Search,
    Bell,
    CircleCheck,
    X,
    UserRound,
    Salad,
    ChevronDown,
} from '@lucide/vue';
import BookingDateInput from '@/components/bookings/BookingDateInput.vue';
import ServiceOrderModal from '@/components/reservations/ServiceOrderModal.vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import RestaurantReviewModal from '@/components/reservations/RestaurantReviewModal.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import { bookingDurations, bookingDate, bookingTimes } from '@/lib/booking';
import type { WaiterReservation } from '@/types/restaurant-review';
defineOptions({ inheritAttrs: false });
const props = defineProps<{
    restaurant: { id: number; name: string; address: string };
    filters: { date: string; time?: string; table?: number };
    tables: { id: number; name: string; seats: number }[];
    reservations: {
        data: WaiterReservation[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    notifications: { reservation_id: number; kind: string; revision: number }[];
}>();
const page = usePage();
const root = computed(
    () => `/staff/restaurants/${props.restaurant.id}/reservations`,
);
const filter = ref({
    ...props.filters,
    time: props.filters.time ?? '',
    table: props.filters.table ?? '',
});
const serving = shallowRef<WaiterReservation | null>(null);
const reviewing = shallowRef<WaiterReservation | null>(null),
    cancelling = shallowRef<WaiterReservation | null>(null),
    editing = shallowRef<WaiterReservation | null>(null);
const formOpen = ref(false),
    busy = ref(false),
    error = ref(''),
    stale = ref(false),
    notice = ref('');
const formTrigger = shallowRef<HTMLElement | null>(null);
const cancelTrigger = shallowRef<HTMLElement | null>(null);
const form = ref({
    owner_email: '',
    date: props.filters.date,
    time: '12:00',
    guest_count: 2,
    duration_minutes: 120,
    table_id: props.tables[0]?.id ?? 0,
});
function refresh() {
    router.reload({ only: ['reservations', 'notifications'] });
}
function search() {
    if (!filter.value.date) return;
    router.get(root.value, { ...filter.value }, { preserveState: true });
}
function openForm(reservation: WaiterReservation | null, event: Event) {
    formTrigger.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    editing.value = reservation;
    error.value = '';
    stale.value = false;
    form.value = {
        owner_email: '',
        date: reservation?.date ?? props.filters.date,
        time: reservation?.time ?? '12:00',
        guest_count: reservation?.guest_count ?? 2,
        duration_minutes: reservation?.duration_minutes ?? 120,
        table_id: reservation?.table_id ?? props.tables[0]?.id ?? 0,
    };
    formOpen.value = true;
}
function openCancel(reservation: WaiterReservation, event: Event) {
    cancelTrigger.value =
        event.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    cancelling.value = reservation;
    error.value = '';
    stale.value = false;
}
async function save(cancel = false) {
    if (busy.value || stale.value) return;
    busy.value = true;
    error.value = '';
    const reservation = cancel ? cancelling.value : editing.value;
    try {
        const { owner_email, ...update } = form.value;
        await sessionJson(
            root.value +
                (reservation
                    ? `/${reservation.id}${cancel ? '/cancel' : ''}`
                    : ''),
            cancel || !reservation ? 'post' : 'patch',
            new AbortController().signal,
            cancel ? {} : reservation ? update : { ...update, owner_email },
            reservation?.version,
        );
        formOpen.value = false;
        cancelling.value = null;
        notice.value = cancel ? 'Reservation Cancelled!' : 'Reservation saved.';
        refresh();
    } catch (reason) {
        stale.value =
            reason instanceof SessionRequestError &&
            [401, 403, 404, 409, 419].includes(reason.status);
        error.value =
            reason instanceof SessionRequestError && reason.status === 422
                ? Object.values(reason.errors).flat().join(' ')
                : 'The result could not be confirmed. Close this dialog and refresh reservations.';
    } finally {
        busy.value = false;
    }
}
let polling: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    polling = setInterval(() => {
        if (
            !formOpen.value &&
            !reviewing.value &&
            !serving.value &&
            !cancelling.value &&
            !busy.value &&
            document.visibilityState === 'visible'
        )
            refresh();
    }, 30000);
});
onBeforeUnmount(() => clearInterval(polling));
function notificationLabel(kind: string) {
    const labels: Record<string, string> = {
        customer_preorder_submitted: 'Customer pre-order submitted',
        customer_preorder_updated: 'Customer updated the pre-order',
        guest_preorder_submitted: 'Guest selections updated',
        guest_removed: 'Guest removed',
        group_submitted: 'Group pre-order submitted',
    };
    return labels[kind] ?? 'Customer pre-order changed';
}
const labels = {
    upcoming: 'Reserved',
    in_progress: 'In Progress',
    completed: 'Completed',
    canceled: 'Cancelled',
};
</script>
<template>
    <Head title="Waiter reservations"
        ><meta name="robots" content="noindex"
    /></Head>
    <div class="bg-accent">
        <SiteContainer
            class="flex min-h-[84px] flex-wrap items-center justify-between gap-3 py-4"
        >
            <h1 class="text-2xl font-medium wrap-anywhere">
                Hello, {{ page.props.auth.user.name }} (Waiter)
            </h1>
            <details class="relative text-sm">
                <summary
                    class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-3"
                    aria-label="Restaurant notifications"
                >
                    <Bell class="size-5" /><span class="sr-only"
                        >Notifications</span
                    ><span
                        v-if="notifications.length"
                        class="rounded-full bg-white px-2 text-xs"
                        >{{ notifications.length }}</span
                    >
                </summary>
                <div
                    class="absolute right-0 z-20 mt-2 max-h-80 w-[min(340px,calc(100vw-32px))] overflow-y-auto rounded-xl border bg-white p-4 shadow-lg"
                >
                    <h2 class="mb-2 font-medium">Restaurant notifications</h2>
                    <p v-if="!notifications.length">
                        No pre-order notifications
                    </p>
                    <ul>
                        <li
                            v-for="(event, index) in notifications"
                            :key="index"
                            class="border-b py-3 last:border-0"
                        >
                            Reservation #{{ event.reservation_id }}<br />{{
                                notificationLabel(event.kind)
                            }}
                        </li>
                    </ul>
                </div>
            </details>
        </SiteContainer>
    </div>
    <SiteContainer class="py-6">
        <p
            v-if="notice"
            role="status"
            class="mb-4 flex items-center gap-3 rounded-lg border border-primary/20 bg-[#e8ffea] p-3 text-sm"
        >
            <CircleCheck class="size-5 shrink-0 text-primary" />{{ notice
            }}<button
                aria-label="Dismiss notification"
                class="ml-auto grid size-11 place-items-center"
                @click="notice = ''"
            >
                <X class="size-5" />
            </button>
        </p>
        <form
            class="mb-6 flex flex-wrap items-end gap-3"
            @submit.prevent="search"
        >
            <div class="w-full sm:w-[192px]">
                <label for="staff-filter-date" class="sr-only">Date</label
                ><BookingDateInput
                    id="staff-filter-date"
                    v-model="filter.date"
                    min="1900-01-01"
                    max="2100-12-31"
                />
            </div>
            <label class="grid w-full sm:w-[172px]"
                ><span class="sr-only">Time</span
                ><select v-model="filter.time" class="booking-input min-h-12">
                    <option value="">All times</option>
                    <option
                        v-for="time in bookingTimes"
                        :key="time"
                        :value="time"
                    >
                        {{ time }}
                    </option>
                </select></label
            >
            <label class="grid w-full sm:w-[160px]"
                ><span class="sr-only">Table</span
                ><select v-model="filter.table" class="booking-input min-h-12">
                    <option value="">All tables</option>
                    <option
                        v-for="table in tables"
                        :key="table.id"
                        :value="table.id"
                    >
                        {{ table.name }}
                    </option>
                </select></label
            >
            <Button
                type="submit"
                variant="outline"
                class="min-h-12"
                aria-label="Search reservations"
                ><Search class="size-5"
            /></Button>
            <Button
                type="button"
                class="min-h-12 sm:ml-auto"
                @click="openForm(null, $event)"
                >+ New Reservation</Button
            >
        </form>
        <p
            v-if="!reservations.data.length"
            role="status"
            class="rounded-xl bg-white p-8 text-center"
        >
            No reservations match these filters.
        </p>
        <ul
            aria-label="Restaurant reservations"
            class="grid items-start gap-6 md:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="reservation in reservations.data"
                :key="reservation.id"
                class="min-w-0 overflow-hidden rounded-3xl bg-white shadow-sm"
            >
                <div
                    class="flex items-center justify-center gap-2 py-2 text-sm"
                    :class="{
                        'bg-[#dfe9ff]': reservation.phase === 'upcoming',
                        'bg-[#fff3d2]': reservation.phase === 'in_progress',
                        'bg-[#e8ffea]': reservation.phase === 'completed',
                        'bg-[#fce9ef]': reservation.phase === 'canceled',
                    }"
                >
                    <span
                        v-if="reservation.updated"
                        aria-label="Updated customer pre-order"
                        class="size-2 rounded-full bg-amber-500"
                    />{{ labels[reservation.phase] }}
                </div>
                <div class="space-y-4 p-5">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b pb-4"
                    >
                        <h2
                            class="flex min-w-0 items-center gap-2 text-sm font-medium wrap-anywhere"
                        >
                            <UserRound class="size-4 shrink-0 text-primary" />{{
                                reservation.owner_name
                            }}
                        </h2>
                        <button
                            v-if="reservation.can_edit"
                            class="min-h-11 rounded-lg border px-3 text-sm"
                            :aria-label="`Change table for ${reservation.code}`"
                            @click="openForm(reservation, $event)"
                        >
                            {{ reservation.table?.name ?? 'Unassigned table'
                            }}<ChevronDown class="ml-3 inline size-4" /></button
                        ><span v-else class="text-sm">{{
                            reservation.table?.name ?? 'Unassigned'
                        }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <dt class="sr-only">Date</dt>
                        <dd class="flex items-center gap-2">
                            <CalendarDays
                                class="size-4 shrink-0 text-primary"
                            />{{ bookingDate(reservation.date) }}
                        </dd>
                        <dt class="sr-only">Guests</dt>
                        <dd
                            class="col-start-2 flex items-center gap-2"
                            :class="
                                reservation.pre_orders &&
                                reservation.phase !== 'canceled'
                                    ? 'row-start-2'
                                    : 'row-start-1'
                            "
                        >
                            <Users class="size-4 shrink-0 text-primary" />{{
                                reservation.guest_count
                            }}
                            {{
                                reservation.guest_count === 1
                                    ? 'Guest'
                                    : 'Guests'
                            }}
                        </dd>
                        <dt class="sr-only">Time</dt>
                        <dd
                            class="col-start-1 row-start-2 flex items-center gap-2"
                        >
                            <Clock class="size-4 shrink-0 text-primary" />{{
                                reservation.time
                            }}
                            – {{ reservation.end_time }}
                        </dd>
                        <template
                            v-if="
                                reservation.pre_orders &&
                                reservation.phase !== 'canceled'
                            "
                            ><dt class="sr-only">Pre-orders</dt>
                            <dd
                                class="col-start-2 row-start-1 flex items-center gap-2"
                            >
                                <Salad
                                    class="size-4 shrink-0 text-primary"
                                />Pre-orders: {{ reservation.pre_orders }}/{{
                                    reservation.guest_count
                                }}
                            </dd></template
                        >
                    </dl>
                    <div
                        class="flex flex-wrap items-center gap-1 border-t pt-3"
                    >
                        <button
                            v-if="reservation.can_cancel"
                            class="grid size-11 place-items-center rounded focus-visible:outline-2"
                            :aria-label="`Cancel reservation ${reservation.code}`"
                            @click="openCancel(reservation, $event)"
                        >
                            <Trash2 class="size-5" />
                        </button>
                        <button
                            v-if="reservation.can_edit"
                            class="grid size-11 place-items-center rounded focus-visible:outline-2"
                            :aria-label="`Edit reservation ${reservation.code}`"
                            @click="openForm(reservation, $event)"
                        >
                            <Pencil class="size-5" />
                        </button>
                        <Button
                            v-if="
                                reservation.has_pre_order &&
                                (reservation.phase === 'upcoming' ||
                                    (reservation.phase === 'in_progress' &&
                                        reservation.needs_review))
                            "
                            :variant="
                                reservation.needs_review && reservation.can_edit
                                    ? 'default'
                                    : 'outline'
                            "
                            class="ml-auto min-h-11 text-xs"
                            @click="reviewing = reservation"
                            >{{
                                reservation.needs_review && reservation.can_edit
                                    ? 'Submit Pre-order'
                                    : 'View Pre-order'
                            }}</Button
                        >
                        <Button
                            v-if="
                                reservation.phase === 'in_progress' ||
                                reservation.phase === 'completed'
                            "
                            :variant="
                                reservation.phase === 'in_progress' &&
                                !reservation.has_service_order &&
                                !reservation.has_pre_order
                                    ? 'default'
                                    : 'outline'
                            "
                            class="ml-auto min-h-11 text-xs"
                            @click="serving = reservation"
                            >{{
                                reservation.phase === 'completed'
                                    ? 'View Order'
                                    : reservation.has_service_order ||
                                        reservation.has_pre_order
                                      ? 'Edit Order'
                                      : 'Create Order'
                            }}</Button
                        >
                    </div>
                </div>
            </li>
        </ul>
        <nav
            v-if="reservations.links.filter((link) => link.url).length > 1"
            aria-label="Reservation pages"
            class="mt-6 flex flex-wrap gap-2"
        >
            <template v-for="(link, index) in reservations.links" :key="index"
                ><Link
                    v-if="link.url"
                    :href="link.url"
                    :aria-current="link.active ? 'page' : undefined"
                    class="inline-flex min-h-11 items-center rounded border px-3"
                    >{{
                        link.label
                            .replaceAll('&laquo;', '«')
                            .replaceAll('&raquo;', '»')
                    }}</Link
                ></template
            >
        </nav>
    </SiteContainer>
    <ServiceOrderModal
        v-if="serving"
        :restaurant-id="restaurant.id"
        :reservation="serving"
        @close="serving = null"
        @changed="refresh"
    />
    <RestaurantReviewModal
        v-if="reviewing"
        :restaurant-id="restaurant.id"
        :reservation="reviewing"
        @close="reviewing = null"
        @changed="refresh"
    />
    <BookingModal
        :open="cancelling !== null"
        title="Cancel Reservation"
        description="Confirm reservation cancellation"
        :busy="busy"
        :return-focus-target="cancelTrigger"
        @update:open="!$event && (cancelling = null)"
    >
        <p class="my-6 text-sm leading-6">
            Cancel reservation {{ cancelling?.code }}? Invitation access and
            further order changes will stop. Existing pre-orders and their
            historical prices will be retained. This cannot be undone here.
        </p>
        <p v-if="error" role="alert" class="mb-4 text-sm text-destructive">
            {{ error }}
        </p>
        <div class="flex flex-wrap justify-end gap-3">
            <Button variant="ghost" :disabled="busy" @click="cancelling = null"
                >Keep it</Button
            ><Button
                variant="outline"
                class="min-h-11 border-destructive! text-destructive!"
                :disabled="busy || stale"
                @click="save(true)"
                >Cancel</Button
            >
        </div>
    </BookingModal>
    <BookingModal
        :open="formOpen"
        :title="editing ? 'Edit Reservation' : 'New Reservation'"
        description="Reservation details in Asia/Tbilisi"
        :busy="busy"
        :return-focus-target="formTrigger"
        @update:open="!$event && (formOpen = false)"
    >
        <form class="mt-5 grid gap-4" @submit.prevent="save()">
            <label v-if="!editing" class="grid gap-1 text-sm"
                >Customer email<input
                    v-model="form.owner_email"
                    type="email"
                    required
                    class="min-h-11 min-w-0 rounded-lg border px-3"
            /></label>
            <div class="grid gap-1 text-sm">
                <label for="staff-reservation-date">Date</label
                ><BookingDateInput
                    id="staff-reservation-date"
                    v-model="form.date"
                    min="1900-01-01"
                    max="2100-12-31"
                    :disabled="editing?.phase === 'in_progress'"
                />
            </div>
            <label class="grid gap-1 text-sm"
                >Time · Asia/Tbilisi<input
                    v-model="form.time"
                    type="time"
                    step="1800"
                    :disabled="editing?.phase === 'in_progress'"
                    required
                    class="min-h-11 min-w-0 rounded-lg border px-3"
            /></label>
            <label class="grid gap-1 text-sm"
                >How long would you like to stay?<select
                    v-model.number="form.duration_minutes"
                    :disabled="editing?.phase === 'in_progress'"
                    class="booking-input min-h-11 min-w-0"
                >
                    <option
                        v-for="minutes in bookingDurations"
                        :key="minutes"
                        :value="minutes"
                    >
                        {{ minutes / 60 }} hours
                    </option>
                </select></label
            >
            <label class="grid gap-1 text-sm"
                >Guests, including owner<input
                    v-model.number="form.guest_count"
                    type="number"
                    min="1"
                    max="255"
                    required
                    class="min-h-11 min-w-0 rounded-lg border px-3"
            /></label>
            <label class="grid gap-1 text-sm"
                >Table<select
                    v-model.number="form.table_id"
                    required
                    class="booking-input min-h-11 min-w-0"
                >
                    <option
                        v-for="table in tables"
                        :key="table.id"
                        :value="table.id"
                    >
                        {{ table.name }} · {{ table.seats }} seats
                    </option>
                </select></label
            >
            <p v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>
            <Button type="submit" class="min-h-12" :disabled="busy || stale"
                >Save reservation</Button
            >
        </form>
    </BookingModal>
</template>
