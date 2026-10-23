<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import GroupPreOrderSummary from '@/components/reservations/GroupPreOrderSummary.vue';
import ReservationOverview from '@/components/reservations/ReservationOverview.vue';
import ReservationParticipants from '@/components/reservations/ReservationParticipants.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { show as restaurantShow } from '@/routes/restaurants';
import type { DashboardOrder, ReservationDetails } from '@/types/reservation';

defineProps<{ reservation: ReservationDetails; groupOrder?: DashboardOrder }>();
const refreshing = ref(false);
const refreshError = ref('');
function refresh(): void {
    router.reload({
        only: ['groupOrder'],
        onStart: () => {
            refreshing.value = true;
            refreshError.value = '';
        },
        onFinish: () => {
            refreshing.value = false;
        },
        onNetworkError: () => {
            refreshError.value =
                'We couldn’t refresh the summary. Please try again.';
            return false;
        },
        onHttpException: () => {
            refreshError.value =
                'We couldn’t refresh the summary. Please reload this page.';
            return false;
        },
    });
}
</script>

<template>
    <Head title="Your reservation"
        ><meta name="robots" content="noindex"
    /></Head>
    <SiteContainer>
        <Link
            :href="restaurantShow(reservation.restaurant.slug)"
            class="my-5 inline-flex min-h-11 items-center rounded-sm text-sm text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
            >Back to restaurant</Link
        >
        <div class="mb-8 flex flex-wrap items-start justify-between gap-5">
            <div class="min-w-0">
                <h1
                    class="text-3xl leading-10 font-medium sm:text-[40px] sm:leading-[48px]"
                >
                    Your reservation
                </h1>
                <p class="mt-3 text-sm wrap-anywhere text-muted-foreground">
                    Reference: {{ reservation.code }}
                </p>
            </div>
            <a
                href="#group-pre-order"
                class="inline-flex min-h-11 items-center rounded-sm text-sm font-medium text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                >Go to group pre-order</a
            >
        </div>
        <div class="space-y-10 pb-12 sm:space-y-[60px]">
            <ReservationOverview :reservation="reservation" />
            <div
                id="group-pre-order"
                tabindex="-1"
                class="scroll-mt-6 space-y-10 sm:space-y-[60px]"
            >
                <DeferredSection
                    data="groupOrder"
                    error-title="We couldn’t load your participants and group pre-order"
                >
                    <template #fallback
                        ><div
                            role="status"
                            aria-live="polite"
                            class="rounded-2xl border border-border bg-card p-8"
                        >
                            <p>Loading participants and group pre-order…</p>
                            <div
                                aria-hidden="true"
                                class="mt-6 h-32 animate-pulse rounded-xl bg-accent motion-reduce:animate-none"
                            /></div
                    ></template>
                    <template v-if="groupOrder">
                        <ReservationParticipants
                            :participants="groupOrder.participants"
                            :selections="groupOrder.summary.guests"
                        />
                        <div class="space-y-5" :aria-busy="refreshing">
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <p
                                    class="text-sm text-muted-foreground"
                                    role="status"
                                >
                                    {{
                                        refreshing
                                            ? 'Refreshing saved selections…'
                                            : 'Saved group selections'
                                    }}
                                </p>
                                <Button
                                    variant="outline"
                                    type="button"
                                    class="min-h-11 bg-card"
                                    :disabled="refreshing"
                                    @click="refresh"
                                    >{{
                                        refreshing
                                            ? 'Refreshing…'
                                            : 'Refresh summary'
                                    }}</Button
                                >
                            </div>
                            <p
                                v-if="refreshError"
                                role="alert"
                                class="text-sm text-destructive"
                            >
                                {{ refreshError }}
                            </p>
                            <GroupPreOrderSummary :order="groupOrder" />
                        </div>
                    </template>
                </DeferredSection>
            </div>
        </div>
    </SiteContainer>
</template>
