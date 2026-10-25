<script setup lang="ts">
import { Head, Link, usePage, router } from '@inertiajs/vue3';
import { shallowRef, ref } from 'vue';
import ReservationInviteModal from '@/components/reservations/ReservationInviteModal.vue';
import ReservationFeedbackModal from '@/components/reservations/ReservationFeedbackModal.vue';
import BookingSupport from '@/components/bookings/BookingSupport.vue';
import ReservationListCard from '@/components/reservations/ReservationListCard.vue';
import ReservationPagination from '@/components/reservations/ReservationPagination.vue';
import ContentState from '@/components/site/ContentState.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { dashboard, home } from '@/routes';
import { create } from '@/routes/booking';
import type {
    MyReservationsPage,
    OwnedReservation,
    ReservationCounts,
} from '@/types/reservation';

withDefaults(
    defineProps<{
        reservations?: MyReservationsPage;
        reservationCounts?: ReservationCounts;
        activeStatus?: string;
    }>(),
    { activeStatus: 'all' },
);
const inviting = shallowRef<OwnedReservation | null>(null);
const feedback = shallowRef<OwnedReservation | null>(null);
const filtering = ref(false);
const filterError = ref('');
const tabs = [
    { id: 'all', label: 'All' },
    { id: 'upcoming', label: 'Upcoming' },
    { id: 'in_progress', label: 'In Progress' },
    { id: 'completed', label: 'Completed' },
    { id: 'canceled', label: 'Canceled' },
] as const;
function reload() {
    router.reload({ only: ['reservations', 'reservationCounts'] });
}
function failed(): false {
    filterError.value = 'Unable to load reservations. Try the filter again.';
    return false;
}
const page = usePage();
</script>

<template>
    <Head title="My reservations"
        ><meta name="robots" content="noindex"
    /></Head>
    <div class="bg-accent">
        <SiteContainer
            class="flex flex-wrap items-center justify-between gap-4 py-3"
        >
            <div class="min-w-0">
                <h1 class="sr-only">My reservations</h1>
                <p class="text-[26px] leading-10 font-medium wrap-anywhere">
                    Hello, {{ page.props.auth.user.name }}
                </p>
            </div>
            <BookingSupport greeting class="max-w-[440px]" />
        </SiteContainer>
    </div>
    <SiteContainer
        class="space-y-6"
        :class="reservations?.total === 0 ? 'pb-8' : 'py-6'"
    >
        <nav
            aria-label="Reservation status"
            :aria-busy="filtering"
            class="flex flex-wrap border-b border-border"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.id"
                :href="
                    dashboard(
                        tab.id === 'all'
                            ? undefined
                            : { query: { status: tab.id } },
                    )
                "
                :aria-current="activeStatus === tab.id ? 'page' : undefined"
                :aria-disabled="filtering || undefined"
                class="inline-flex min-h-12 items-center gap-2 border-b-2 px-4 text-sm font-medium focus-visible:outline-2"
                :class="
                    activeStatus === tab.id
                        ? 'border-primary text-primary'
                        : 'border-transparent'
                "
                @before="() => !filtering"
                @start="
                    filtering = true;
                    filterError = '';
                "
                @finish="filtering = false"
                @network-error="failed"
                @http-exception="failed"
            >
                {{ tab.label
                }}<span
                    v-if="reservationCounts"
                    class="grid min-w-5 place-items-center rounded-full border border-primary/50 px-1 text-xs"
                    :class="
                        activeStatus === tab.id
                            ? 'bg-primary text-white'
                            : 'text-primary'
                    "
                    >{{ reservationCounts[tab.id] }}</span
                >
            </Link>
        </nav>
        <p v-if="filtering" role="status" class="text-sm">
            Loading reservations…
        </p>
        <p v-if="filterError" role="alert" class="text-sm text-destructive">
            {{ filterError }}
        </p>
        <DeferredSection
            data="reservations"
            error-title="We couldn’t load your reservations"
        >
            <template #fallback
                ><div role="status" class="space-y-6">
                    <p class="text-sm">Loading your reservations…</p>
                    <div
                        aria-hidden="true"
                        class="grid gap-6 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <div
                            v-for="index in 3"
                            :key="index"
                            class="h-64 animate-pulse rounded-2xl bg-accent motion-reduce:animate-none"
                        />
                    </div></div
            ></template>
            <template v-if="reservations">
                <template v-if="reservations.data.length">
                    <p class="sr-only" role="status">
                        Showing {{ reservations.from }}–{{ reservations.to }} of
                        {{ reservations.total }} reservations
                    </p>
                    <ul
                        aria-label="Your reservations"
                        class="grid items-stretch gap-6 md:grid-cols-2 xl:grid-cols-3"
                    >
                        <li
                            v-for="reservation in reservations.data"
                            :key="reservation.id"
                            class="min-w-0"
                        >
                            <ReservationListCard
                                :reservation="reservation"
                                @invite="inviting = reservation"
                                @feedback="feedback = reservation"
                            />
                        </li>
                    </ul>
                </template>
                <section
                    v-else-if="
                        reservations.total === 0 && activeStatus === 'all'
                    "
                    aria-labelledby="empty-reservations-heading"
                    class="flex min-h-[620px] flex-col items-center pb-16 text-center"
                >
                    <img
                        src="/images/green-tasty/locations/Empty State - No reservations.png"
                        alt="Friends enjoying a meal together at a restaurant"
                        width="439"
                        height="423"
                        class="h-auto w-full max-w-[439px]"
                    />
                    <h2
                        id="empty-reservations-heading"
                        class="mt-12 text-[26px] leading-10 font-medium"
                    >
                        No Reservations
                    </h2>
                    <p class="mt-2 text-base leading-6 text-muted-foreground">
                        Looks like you haven’t made any reservations yet.
                    </p>
                    <Link
                        :href="create()"
                        class="mt-5 inline-flex min-h-11 items-center rounded-sm font-medium underline underline-offset-8 focus-visible:outline-2 focus-visible:outline-offset-4"
                        >Book a Table</Link
                    >
                </section>
                <ContentState
                    v-else
                    :title="
                        reservations.total === 0
                            ? 'No reservations in this category'
                            : 'No reservations on this page'
                    "
                    description="Choose All to see your reservations."
                    ><Link
                        :href="dashboard()"
                        class="inline-flex min-h-11 items-center rounded-sm font-medium text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >Back to first page</Link
                    ></ContentState
                >
                <ReservationPagination :reservations="reservations" />
            </template>
        </DeferredSection>
    </SiteContainer>
    <ReservationInviteModal
        v-if="inviting"
        :reservation="inviting"
        @close="inviting = null"
        @changed="reload"
    />
    <ReservationFeedbackModal
        v-if="feedback"
        :reservation="feedback"
        @close="feedback = null"
        @changed="reload"
    />
</template>
