<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import ReservationListCard from '@/components/reservations/ReservationListCard.vue';
import ReservationPagination from '@/components/reservations/ReservationPagination.vue';
import ContentState from '@/components/site/ContentState.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { dashboard, home } from '@/routes';
import { edit } from '@/routes/profile';
import type { MyReservationsPage } from '@/types/reservation';

defineProps<{ reservations?: MyReservationsPage }>();
const page = usePage();
</script>

<template>
    <Head title="My reservations"
        ><meta name="robots" content="noindex"
    /></Head>
    <div class="bg-accent">
        <SiteContainer
            class="flex flex-wrap items-center justify-between gap-4 py-6"
        >
            <div class="min-w-0">
                <h1 class="text-3xl leading-10 font-medium">My reservations</h1>
                <p class="mt-2 text-sm wrap-anywhere">
                    Hello, {{ page.props.auth.user.name }}
                </p>
            </div>
            <Link
                :href="edit()"
                class="inline-flex min-h-11 items-center rounded-sm text-sm font-medium text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                >Account settings</Link
            >
        </SiteContainer>
    </div>
    <SiteContainer class="space-y-8 py-8 sm:py-10">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <p class="max-w-xl text-sm leading-6 text-muted-foreground">
                View your bookings and open a reservation to manage guests and
                your group pre-order.
            </p>
            <Link
                :href="`${home().url}#locations`"
                class="inline-flex min-h-11 items-center justify-center rounded-sm bg-primary px-5 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >Book a table</Link
            >
        </div>
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
                    <p class="text-sm text-muted-foreground" role="status">
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
                            <ReservationListCard :reservation="reservation" />
                        </li>
                    </ul>
                </template>
                <ContentState
                    v-else-if="reservations.total === 0"
                    title="No reservations yet"
                    description="Looks like you haven’t made any reservations yet. Choose a location to book your table."
                    ><Link
                        :href="`${home().url}#locations`"
                        class="inline-flex min-h-11 items-center rounded-sm font-medium text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                        >Browse locations</Link
                    ></ContentState
                >
                <ContentState
                    v-else
                    title="No reservations on this page"
                    description="This page is no longer available. Return to the first page to see your reservations."
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
</template>
