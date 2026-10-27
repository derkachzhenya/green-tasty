<script setup lang="ts">
import { Head, usePage, router, Link } from '@inertiajs/vue3';
import { ref, shallowRef, computed } from 'vue';
import {
    CalendarDays,
    Clock,
    Users,
    Trash2,
    Pencil,
    Search,
    Bell,
} from '@lucide/vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import RestaurantReviewModal from '@/components/reservations/RestaurantReviewModal.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import { bookingDurations } from '@/lib/booking';
import type { WaiterReservation } from '@/types/restaurant-review';
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
const filter = ref({ ...props.filters });
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
        <SiteContainer class="py-5"
            ><h1 class="text-2xl font-medium wrap-anywhere">
                Hello, {{ page.props.auth.user.name }} (Waiter)
            </h1>
            <p class="mt-1 text-sm">
                {{ restaurant.name }} · {{ restaurant.address }}
            </p></SiteContainer
        >
    </div>
    <SiteContainer class="py-6">
        <p
            v-if="notice"
            role="status"
            class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-[#e8ffea] p-4"
        >
            {{ notice
            }}<button class="min-h-11 px-2 underline" @click="notice = ''">
                Dismiss
            </button>
        </p>
        <details class="mb-5 text-sm">
            <summary class="flex min-h-11 cursor-pointer items-center gap-2">
                <Bell class="size-5" />Restaurant notifications ({{
                    notifications.length
                }})
            </summary>
            <p v-if="!notifications.length" class="py-2">
                No pre-order notifications
            </p>
            <ul>
                <li
                    v-for="(event, index) in notifications"
                    :key="index"
                    class="py-2"
                >
                    Reservation {{ event.reservation_id }}:
                    {{ event.kind.replaceAll('_', ' ') }} · revision
                    {{ event.revision }}
                </li>
            </ul>
        </details>
        <form
            class="mb-6 flex flex-wrap items-end gap-3"
            @submit.prevent="search"
        >
            <label class="grid min-w-0 flex-1 basis-36 gap-1 text-sm"
                >Date<input
                    v-model="filter.date"
                    type="date"
                    required
                    class="min-h-12 w-full rounded-lg border bg-white px-3"
            /></label>
            <label class="grid min-w-0 flex-1 basis-28 gap-1 text-sm"
                >Time<input
                    v-model="filter.time"
                    type="time"
                    class="min-h-12 w-full rounded-lg border bg-white px-3"
            /></label>
            <label class="grid min-w-0 flex-1 basis-32 gap-1 text-sm"
                >Table<select
                    v-model="filter.table"
                    class="min-h-12 rounded-lg border bg-white px-3"
                >
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
                        <h2 class="min-w-0 font-medium wrap-anywhere">
                            {{ reservation.owner_name }}
                        </h2>
                        <button
                            v-if="reservation.can_edit"
                            class="min-h-11 rounded-lg border px-3 text-sm"
                            :aria-label="`Change table for ${reservation.code}`"
                            @click="openForm(reservation, $event)"
                        >
                            {{
                                reservation.table?.name ?? 'Unassigned table'
                            }}⌄</button
                        ><span v-else class="text-sm">{{
                            reservation.table?.name ?? 'Unassigned'
                        }}</span>
                    </div>
                    <dl class="grid grid-cols-2 gap-3 text-xs">
                        <dt class="sr-only">Date</dt>
                        <dd class="flex items-center gap-2">
                            <CalendarDays
                                class="size-4 shrink-0 text-primary"
                            />{{ reservation.date }}
                        </dd>
                        <dt class="sr-only">Pre-orders</dt>
                        <dd v-if="reservation.pre_orders">
                            Pre-orders: {{ reservation.pre_orders }}/{{
                                reservation.guest_count
                            }}
                        </dd>
                        <dd v-else>No pre-order</dd>
                        <dt class="sr-only">Time</dt>
                        <dd class="flex items-center gap-2">
                            <Clock class="size-4 shrink-0 text-primary" />{{
                                reservation.time
                            }}
                            – {{ reservation.end_time }}
                        </dd>
                        <dt class="sr-only">Guests</dt>
                        <dd class="flex items-center gap-2">
                            <Users class="size-4 shrink-0 text-primary" />{{
                                reservation.guest_count
                            }}
                            Guests
                        </dd>
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
                            v-if="reservation.has_pre_order"
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
                        <p
                            v-else-if="
                                reservation.phase === 'in_progress' ||
                                reservation.phase === 'completed'
                            "
                            class="text-xs text-muted-foreground"
                        >
                            No service order recorded.
                        </p>
                    </div>
                </div>
            </li>
        </ul>
        <nav aria-label="Reservation pages" class="mt-6 flex flex-wrap gap-2">
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
            <label class="grid gap-1 text-sm"
                >Date<input
                    v-model="form.date"
                    type="date"
                    required
                    class="min-h-11 min-w-0 rounded-lg border px-3"
            /></label>
            <label class="grid gap-1 text-sm"
                >Time · Asia/Tbilisi<input
                    v-model="form.time"
                    type="time"
                    step="1800"
                    required
                    class="min-h-11 min-w-0 rounded-lg border px-3"
            /></label>
            <label class="grid gap-1 text-sm"
                >How long would you like to stay?<select
                    v-model.number="form.duration_minutes"
                    :disabled="editing?.phase === 'in_progress'"
                    class="min-h-11 min-w-0 rounded-lg border px-3"
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
                    class="min-h-11 min-w-0 rounded-lg border px-3"
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
