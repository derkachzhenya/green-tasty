<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ContentState from '@/components/site/ContentState.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { availability, show } from '@/routes/restaurants';
import { store } from '@/routes/restaurants/bookings';
import type {
    BookingRestaurant,
    BookingRules,
    BookingTable,
} from '@/types/booking';

const props = defineProps<{
    restaurant: BookingRestaurant;
    bookingRules: BookingRules;
}>();
const form = useForm({
    date: props.bookingRules.default_date,
    time: '12:00',
    guest_count: 2,
    restaurant_table_id: null as number | null,
});
const tables = ref<BookingTable[] | null>(null);
const loading = ref(false);
const availabilityError = ref('');
const submissionError = ref('');
let controller: AbortController | null = null;
let requestId = 0;

watch(
    () => [form.date, form.time, form.guest_count],
    () => {
        controller?.abort();
        requestId++;
        tables.value = null;
        form.restaurant_table_id = null;
        loading.value = false;
        availabilityError.value = '';
        form.clearErrors();
    },
);
onBeforeUnmount(() => controller?.abort());

async function findTables(): Promise<void> {
    controller?.abort();
    controller = new AbortController();
    const current = ++requestId;
    loading.value = true;
    tables.value = null;
    form.restaurant_table_id = null;
    availabilityError.value = '';
    submissionError.value = '';
    form.clearErrors();
    try {
        const response = await fetch(
            availability(props.restaurant.slug, {
                query: {
                    date: form.date,
                    time: form.time,
                    guest_count: form.guest_count,
                },
            }).url,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            },
        );
        const data = (await response.json()) as {
            tables?: BookingTable[];
            errors?: Record<string, string[]>;
        };
        if (current !== requestId) return;
        if (response.status === 422 && data.errors) {
            for (const field of ['date', 'time', 'guest_count'] as const) {
                if (data.errors[field]?.[0])
                    form.setError(field, data.errors[field][0]);
            }
        } else if (!response.ok || !data.tables) {
            availabilityError.value =
                response.status === 401
                    ? 'Your session expired. Please sign in again.'
                    : 'We couldn’t check availability. Please try again.';
        } else {
            tables.value = data.tables;
        }
    } catch {
        if (current === requestId && !controller.signal.aborted)
            availabilityError.value = 'We couldn’t connect. Please try again.';
    } finally {
        if (current === requestId) loading.value = false;
    }
}

function book(): void {
    if (!form.restaurant_table_id || form.processing) return;
    submissionError.value = '';
    form.post(store(props.restaurant.slug).url, {
        preserveScroll: true,
        onError: () => {
            tables.value = null;
            form.restaurant_table_id = null;
        },
        onNetworkError: () => {
            submissionError.value =
                'We couldn’t confirm your booking. Check availability before trying again.';
            tables.value = null;
            form.restaurant_table_id = null;
            return false;
        },
        onHttpException: () => {
            submissionError.value =
                'We couldn’t confirm your booking. Please refresh the page and try again.';
            return false;
        },
    });
}
</script>

<template>
    <Head :title="`Book a table — ${restaurant.name}`" />
    <div class="bg-accent">
        <SiteContainer class="min-h-[calc(100dvh-68px)]">
            <Link
                :href="show(restaurant.slug)"
                class="my-5 inline-flex min-h-11 items-center rounded-sm text-sm text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                >Back to restaurant</Link
            >
            <h1 class="mb-4 text-3xl leading-10 font-medium">Book a Table</h1>
            <p class="mb-8 text-sm leading-6">
                {{ restaurant.name }} · {{ restaurant.address }}
            </p>
            <div
                class="grid items-start gap-8 pb-12 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]"
            >
                <form
                    class="gt-card min-w-0 space-y-6 bg-card p-6 sm:p-8"
                    @submit.prevent="findTables"
                >
                    <p
                        id="booking-rules"
                        class="text-sm leading-6 text-muted-foreground"
                    >
                        {{ bookingRules.duration_minutes / 60 }}-hour bookings.
                        All times are in {{ bookingRules.timezone }}. Guest
                        count includes you.
                    </p>
                    <div class="space-y-2">
                        <label for="booking-date" class="text-sm font-medium"
                            >Date</label
                        >
                        <input
                            id="booking-date"
                            v-model="form.date"
                            type="date"
                            required
                            :min="bookingRules.min_date"
                            :max="bookingRules.max_date"
                            :disabled="form.processing"
                            :aria-invalid="!!form.errors.date"
                            aria-describedby="date-error booking-rules"
                            class="min-h-11 w-full min-w-0 rounded-md border border-primary/40 bg-card px-3 focus-visible:outline-2 focus-visible:outline-ring"
                        />
                        <InputError
                            id="date-error"
                            :message="form.errors.date"
                        />
                    </div>
                    <div
                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2"
                    >
                        <div class="space-y-2">
                            <label
                                for="booking-time"
                                class="text-sm font-medium"
                                >Time</label
                            >
                            <input
                                id="booking-time"
                                v-model="form.time"
                                type="time"
                                required
                                :step="bookingRules.slot_minutes * 60"
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.time"
                                aria-describedby="time-error booking-rules"
                                class="min-h-11 w-full min-w-0 rounded-md border border-primary/40 bg-card px-3 focus-visible:outline-2 focus-visible:outline-ring"
                            />
                            <InputError
                                id="time-error"
                                :message="form.errors.time"
                            />
                        </div>
                        <div class="space-y-2">
                            <label for="guest-count" class="text-sm font-medium"
                                >Guests</label
                            >
                            <input
                                id="guest-count"
                                v-model.number="form.guest_count"
                                type="number"
                                min="1"
                                max="255"
                                required
                                :disabled="form.processing"
                                :aria-invalid="!!form.errors.guest_count"
                                aria-describedby="guests-error booking-rules"
                                class="min-h-11 w-full min-w-0 rounded-md border border-primary/40 bg-card px-3 focus-visible:outline-2 focus-visible:outline-ring"
                            />
                            <InputError
                                id="guests-error"
                                :message="form.errors.guest_count"
                            />
                        </div>
                    </div>
                    <Button
                        type="submit"
                        :disabled="loading || form.processing"
                        class="min-h-11 w-full"
                        >{{
                            loading ? 'Checking availability…' : 'Find a Table'
                        }}</Button
                    >
                </form>
                <section
                    aria-labelledby="available-heading"
                    :aria-busy="loading"
                    class="min-w-0 space-y-6"
                >
                    <h2
                        id="available-heading"
                        class="text-2xl leading-8 font-medium"
                    >
                        Available tables
                    </h2>
                    <p v-if="loading" role="status">
                        Checking live availability…
                    </p>
                    <ContentState
                        v-else-if="availabilityError"
                        error
                        title="Availability unavailable"
                        :description="availabilityError"
                    />
                    <ContentState
                        v-else-if="tables === null"
                        title="Find your table"
                        description="Choose a date, time and guest count, then check availability."
                    />
                    <ContentState
                        v-else-if="!tables.length"
                        title="No tables available"
                        description="Try a different time, date or guest count."
                    />
                    <fieldset v-else class="space-y-4">
                        <legend class="sr-only">
                            Choose an available table
                        </legend>
                        <label
                            v-for="table in tables"
                            :key="table.id"
                            class="flex min-h-20 cursor-pointer items-center gap-4 rounded-2xl border border-primary/40 bg-card p-6 has-checked:border-primary has-checked:bg-accent"
                        >
                            <input
                                v-model="form.restaurant_table_id"
                                type="radio"
                                name="restaurant_table_id"
                                :value="table.id"
                                :disabled="form.processing"
                                :aria-invalid="
                                    !!form.errors.restaurant_table_id
                                "
                                aria-describedby="table-error"
                                class="size-5 shrink-0 accent-primary"
                            />
                            <span class="min-w-0 wrap-anywhere"
                                ><span class="block font-medium">{{
                                    table.name
                                }}</span
                                ><span class="text-sm"
                                    >Up to {{ table.seats }} guests</span
                                ></span
                            >
                        </label>
                    </fieldset>
                    <InputError
                        id="table-error"
                        :message="form.errors.restaurant_table_id"
                        role="alert"
                    />
                    <p
                        v-if="submissionError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ submissionError }}
                    </p>
                    <template v-if="tables?.length">
                        <p class="text-sm leading-6 text-muted-foreground">
                            Availability can change. Your table is reserved only
                            after confirmation.
                        </p>
                        <Button
                            type="button"
                            :disabled="
                                !form.restaurant_table_id || form.processing
                            "
                            aria-describedby="table-error"
                            class="min-h-11"
                            @click="book"
                            >{{
                                form.processing
                                    ? 'Confirming reservation…'
                                    : 'Confirm reservation'
                            }}</Button
                        >
                    </template>
                </section>
            </div>
        </SiteContainer>
    </div>
</template>
