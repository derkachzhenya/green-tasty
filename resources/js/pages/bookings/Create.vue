<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import BookingEmptyState from '@/components/bookings/BookingEmptyState.vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import BookingSearchForm from '@/components/bookings/BookingSearchForm.vue';
import BookingSupport from '@/components/bookings/BookingSupport.vue';
import BookingTableCard from '@/components/bookings/BookingTableCard.vue';
import EditBookingFields from '@/components/bookings/EditBookingFields.vue';
import ReservationFacts from '@/components/bookings/ReservationFacts.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { bookingDate, bookingEnd, bookingGuestError } from '@/lib/booking';
import { availability, resume } from '@/routes/booking';
import { store } from '@/routes/restaurants/bookings';
import type {
    BookingFilters,
    BookingRestaurant,
    BookingRules,
    BookingSearchTable,
} from '@/types/booking';

const props = defineProps<{
    restaurant: BookingRestaurant | null;
    restaurants: BookingRestaurant[];
    bookingRules: BookingRules;
    initialSelection?: Record<string, unknown>;
}>();
const page = usePage();
const hint = props.initialSelection ?? {};
const safeString = (value: unknown) => (typeof value === 'string' ? value : '');
const initialRestaurant = props.restaurant?.slug ?? safeString(hint.restaurant);
const form = useForm({
    restaurant: initialRestaurant,
    date: safeString(hint.date),
    time: safeString(hint.time),
    guest_count: Number(hint.guest_count) || 1,
    restaurant_table_id: null as number | null,
});
const location = computed({
    get: () => form.restaurant,
    set: (value) => {
        form.restaurant = value;
    },
});
const filters = computed<BookingFilters>(() => ({
    restaurant: location.value,
    date: form.date,
    time: form.time,
    guest_count: form.guest_count,
}));
const selectedRestaurant = computed(() =>
    props.restaurants.find((r) => r.slug === location.value),
);
const tables = ref<BookingSearchTable[] | null>(null);
const loading = ref(false);
const error = ref('');
const authBusy = ref(false);
const selected = ref<{ table: BookingSearchTable; time: string } | null>(null);
const modal = ref<'review' | 'edit' | null>(null);
const editTime = ref('');
const editGuests = ref(1);
const end = computed(() =>
    selected.value
        ? bookingEnd(
              form.date,
              selected.value.time,
              props.bookingRules.duration_minutes,
          )
        : null,
);
let controller: AbortController | null = null;
let requestId = 0;

function clearResults(): void {
    controller?.abort();
    requestId++;
    tables.value = null;
    selected.value = null;
    modal.value = null;
    form.restaurant_table_id = null;
    loading.value = false;
    error.value = '';
    form.clearErrors();
}
watch(
    () => [form.date, form.time, form.guest_count, location.value],
    clearResults,
);
watch(
    () => props.restaurant?.slug,
    (value) => {
        location.value = value ?? '';
        clearResults();
    },
);
onBeforeUnmount(() => controller?.abort());
function change(value: Partial<BookingFilters>): void {
    if (form.processing) return;
    if (value.restaurant !== undefined) location.value = value.restaurant;
    if (value.date !== undefined) form.date = value.date;
    if (value.time !== undefined) form.time = value.time;
    if (value.guest_count !== undefined) form.guest_count = value.guest_count;
}
async function findTables(): Promise<void> {
    if (form.processing || authBusy.value) return;
    controller?.abort();
    controller = new AbortController();
    const current = ++requestId;
    loading.value = true;
    tables.value = null;
    selected.value = null;
    form.restaurant_table_id = null;
    modal.value = null;
    error.value = '';
    form.clearErrors();
    const guestError = bookingGuestError(
        form.guest_count,
        props.bookingRules.max_guests ?? 255,
    );
    if (guestError) {
        loading.value = false;
        form.setError('guest_count', guestError);
        return;
    }
    try {
        const response = await fetch(
            availability({ query: filters.value }).url,
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                signal: controller.signal,
            },
        );
        const data = (await response.json()) as {
            tables?: BookingSearchTable[];
            errors?: Record<string, string[]>;
        };
        if (current !== requestId) return;
        if (response.status === 422 && data.errors) {
            for (const field of [
                'restaurant',
                'date',
                'time',
                'guest_count',
            ] as const) {
                if (data.errors[field]?.[0])
                    form.setError(field, data.errors[field][0]);
            }
        } else if (!response.ok || !Array.isArray(data.tables)) {
            error.value =
                response.status === 429
                    ? 'Too many searches. Please wait a minute and try again.'
                    : 'We couldn’t check availability. Please try again.';
        } else tables.value = data.tables;
    } catch {
        if (current === requestId && !controller.signal.aborted)
            error.value = 'We couldn’t connect. Please try again.';
    } finally {
        if (current === requestId) loading.value = false;
    }
}
function choose(table: BookingSearchTable, time: string): void {
    if (
        form.processing ||
        !table.slots.some((slot) => slot.time === time && slot.available)
    )
        return;
    selected.value = { table, time };
    form.restaurant_table_id = table.id;
    modal.value = 'review';
}
function edit(): void {
    if (!selected.value) return;
    editTime.value = selected.value.time;
    editGuests.value = form.guest_count;
    modal.value = 'edit';
}
async function saveDraft(): Promise<void> {
    if (!selected.value || loading.value || form.processing) return;
    form.clearErrors();
    const guestError = bookingGuestError(
        editGuests.value,
        Math.min(
            selected.value.table.seats,
            props.bookingRules.max_guests ?? 255,
        ),
    );
    if (guestError) {
        form.setError('guest_count', guestError);
        return;
    }
    const tableId = selected.value.table.id;
    form.time = editTime.value;
    form.guest_count = editGuests.value;
    // Let the input watcher invalidate the old selection before the fresh request.
    await new Promise<void>((resolve) => queueMicrotask(resolve));
    await findTables();
    const table = tables.value?.find((table) => table.id === tableId);
    if (table) choose(table, form.time);
}
function book(): void {
    if (!selected.value || form.processing || authBusy.value) return;
    const { table, time } = selected.value;
    if (!page.props.auth.user || !page.props.auth.user.email_verified_at) {
        authBusy.value = true;
        router.post(
            resume().url,
            {
                restaurant: table.restaurant.slug,
                date: form.date,
                time,
                guest_count: form.guest_count,
                table: table.id,
            },
            {
                onError: () => {
                    clearResults();
                    error.value =
                        'Your selection is no longer valid. Please search for a table again.';
                },
                onNetworkError: () => {
                    clearResults();
                    error.value = 'We couldn’t open sign in. Please try again.';
                    return false;
                },
                onHttpException: () => {
                    clearResults();
                    error.value =
                        'Your session or selection has changed. Refresh and search again.';
                    return false;
                },
                onFinish: () => {
                    authBusy.value = false;
                },
            },
        );
        return;
    }
    // Only supported booking fields are sent; the restaurant is bound by its real slug.
    form.transform((data) => ({
        date: data.date,
        time,
        guest_count: data.guest_count,
        restaurant_table_id: table.id,
    }));
    form.post(store(table.restaurant.slug).url, {
        preserveScroll: true,
        onError: () => {
            tables.value = null;
            selected.value = null;
            modal.value = null;
            form.restaurant_table_id = null;
        },
        onNetworkError: () => {
            clearResults();
            error.value =
                'We couldn’t confirm your booking. Check My Reservations before trying again.';
            return false;
        },
        onHttpException: () => {
            clearResults();
            error.value =
                'We couldn’t confirm your booking. Please refresh and check My Reservations.';
            return false;
        },
    });
}
async function showAll(): Promise<void> {
    if (loading.value || form.processing || authBusy.value) return;
    form.time = 'all';
    await new Promise<void>((resolve) => queueMicrotask(resolve));
    await findTables();
}
onMounted(async () => {
    // Only restore non-secret selection hints; availability is always fetched anew.
    if (
        safeString(hint.date) &&
        safeString(hint.time) &&
        Number(hint.table) > 0
    ) {
        await findTables();
        const table = tables.value?.find(
            (table) =>
                table.id === Number(hint.table) &&
                table.restaurant.slug === location.value,
        );
        if (table) choose(table, form.time);
    }
});
</script>

<template>
    <Head title="Book a Table" />
    <div
        class="booking-page mx-auto grid min-h-[calc(100dvh-68px)] max-w-[1440px] bg-background lg:grid-cols-[512px_minmax(0,1fr)]"
    >
        <aside
            class="min-w-0 space-y-6 bg-accent px-4 py-8 sm:px-8 lg:px-10 lg:py-12"
        >
            <BookingSearchForm
                :filters="filters"
                :restaurants="restaurants"
                :rules="bookingRules"
                :errors="form.errors"
                :busy="loading || form.processing"
                @change="change"
                @search="findTables"
            />
            <BookingSupport :phone="selectedRestaurant?.phone" />
        </aside>
        <section
            class="min-w-0"
            aria-label="Table availability"
            :aria-busy="loading"
        >
            <img
                v-if="tables === null && !loading && !error"
                src="/images/green-tasty/locations/Restaurant Image.png"
                alt="A restaurant table set with flowers and a reserved sign"
                width="928"
                height="832"
                class="h-auto w-full object-cover lg:h-[calc(100dvh-68px)] lg:min-h-[720px]"
            />
            <div
                v-if="
                    loading ||
                    error ||
                    tables !== null ||
                    Object.keys(form.errors).length
                "
                class="space-y-6 p-4 sm:p-6"
            >
                <p v-if="loading" role="status">Checking live availability…</p>
                <p
                    v-if="error"
                    role="alert"
                    class="rounded-lg border border-destructive/30 bg-card p-4 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <InputError
                    :message="form.errors.restaurant_table_id"
                    role="alert"
                />
                <template v-if="tables !== null">
                    <div role="status" class="space-y-2 text-sm">
                        <h2 class="font-medium">
                            {{
                                tables.length
                                    ? `${tables.length} tables available`
                                    : 'No tables Available'
                            }}
                        </h2>
                        <p
                            class="text-xs leading-5 wrap-anywhere text-muted-foreground"
                        >
                            {{ selectedRestaurant?.address ?? 'All Locations' }}
                            · {{ bookingDate(form.date) }} ·
                            {{ form.time === 'all' ? 'All times' : form.time }}
                            · {{ form.guest_count }} guests
                        </p>
                    </div>
                    <BookingEmptyState
                        v-if="!tables.length"
                        :date="bookingDate(form.date)"
                        @show-all="showAll"
                    />
                    <div
                        v-else
                        class="grid items-start gap-6 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2"
                    >
                        <BookingTableCard
                            v-for="table in tables"
                            :key="table.id"
                            :table="table"
                            :busy="form.processing"
                            @select="choose(table, $event)"
                        />
                    </div>
                </template>
            </div>
        </section>
    </div>
    <BookingModal
        :open="modal !== null"
        :title="
            modal === 'edit'
                ? 'Edit Reservation'
                : 'Review & Confirm Reservation'
        "
        description="Review your selected restaurant, table, date, time and guests before confirming."
        :busy="form.processing || loading || authBusy"
        @update:open="!$event && (modal = null)"
    >
        <template v-if="selected && end">
            <ReservationFacts
                :address="selected.table.restaurant.address"
                :table="selected.table.name"
                :date="form.date"
                :time="selected.time"
                :end-date="end.date"
                :end-time="end.time"
                :guests="form.guest_count"
                :timezone="bookingRules.timezone"
            />
            <template v-if="modal === 'review'">
                <p
                    class="mb-4 rounded-lg border border-primary/15 bg-[#f0fff0] p-3 text-xs leading-5"
                >
                    Your table is reserved only after confirmation. Availability
                    is checked again when you confirm.
                </p>
                <div class="flex gap-4">
                    <Button
                        variant="outline"
                        :disabled="form.processing || authBusy"
                        class="h-14 bg-card"
                        @click="edit"
                        >Edit</Button
                    ><Button
                        class="h-14 flex-1"
                        :disabled="form.processing || authBusy"
                        @click="book"
                        >{{
                            form.processing
                                ? 'Confirming reservation…'
                                : page.props.auth.user?.email_verified_at
                                  ? 'Confirm'
                                  : 'Sign In to Confirm'
                        }}</Button
                    >
                </div>
            </template>
            <EditBookingFields
                v-else
                v-model:time="editTime"
                v-model:guests="editGuests"
                :capacity="selected.table.seats"
                :duration="bookingRules.duration_minutes"
                :busy="loading"
                :errors="form.errors"
                @save="saveDraft"
            />
        </template>
    </BookingModal>
</template>

<style scoped>
@media (min-width: 1024px) and (max-width: 1279px) {
    .booking-page {
        grid-template-columns: 40% minmax(0, 1fr);
    }
}
</style>
