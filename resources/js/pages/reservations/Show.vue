<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch, onMounted } from 'vue';
import OwnerGroupPreOrderModal from '@/components/reservations/OwnerGroupPreOrderModal.vue';
import GroupPreOrderSubmission from '@/components/reservations/GroupPreOrderSubmission.vue';
import OwnerRestaurantReview from '@/components/reservations/OwnerRestaurantReview.vue';
import type { RestaurantReview } from '@/types/restaurant-review';
import GroupPreOrderSummary from '@/components/reservations/GroupPreOrderSummary.vue';
import GuestInvitationManager from '@/components/reservations/GuestInvitationManager.vue';
import ReservationOverview from '@/components/reservations/ReservationOverview.vue';
import ReservationParticipants from '@/components/reservations/ReservationParticipants.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { show as restaurantShow } from '@/routes/restaurants';
import { confirmation } from '@/routes/reservations';
import type {
    DashboardOrder,
    ReservationDetails,
    SubmittedGroupOrder,
} from '@/types/reservation';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    reservation: ReservationDetails;
    groupOrder?: DashboardOrder;
    restaurantReview?: RestaurantReview | null;
    canManageInvitations: boolean;
    canSubmitPreOrder: boolean;
    canEditOwnPreOrder?: boolean;
}>();
const refreshing = ref(false);
const refreshError = ref('');
const submitting = ref(false);
const confirming = ref(false);
const groupOpen = ref(false);
onMounted(() => {
    groupOpen.value = window.location?.hash === '#group-pre-order';
});
const refreshVersion = ref(0);
const submittedResult = shallowRef<SubmittedGroupOrder | null>(null);
const displayedOrder = computed<DashboardOrder | undefined>(() => {
    if (
        !submittedResult.value ||
        (props.groupOrder?.status === 'submitted' &&
            props.groupOrder.submitted_at)
    )
        return props.groupOrder;
    const { status, submitted_at, ...summary } = submittedResult.value;
    return {
        participants: props.groupOrder?.participants ?? [],
        summary,
        status,
        submitted_at,
    };
});
function onSubmitted(result: SubmittedGroupOrder) {
    submittedResult.value = result;
    refresh();
}
watch(
    () => props.reservation.id,
    () => {
        submittedResult.value = null;
        refreshError.value = '';
        refreshVersion.value = 0;
    },
);
function refresh(): void {
    if (refreshing.value) return;
    router.reload({
        only: [
            'groupOrder',
            'restaurantReview',
            'reservation',
            'canSubmitPreOrder',
            'canManageInvitations',
            'canEditOwnPreOrder',
        ],
        onStart: () => {
            refreshing.value = true;
            refreshError.value = '';
        },
        onFinish: () => {
            refreshing.value = false;
        },
        onSuccess: (page) => {
            if (
                page.rescuedProps?.includes('groupOrder') ||
                !page.props.groupOrder
            ) {
                refreshError.value =
                    'We couldn’t refresh the summary. Please try again.';
                return;
            }
            refreshVersion.value++;
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
                @click.prevent="groupOpen = true"
                class="inline-flex min-h-11 items-center rounded-sm text-sm font-medium text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                >Go to group pre-order</a
            >
        </div>
        <div class="space-y-10 pb-12 sm:space-y-[60px]">
            <ReservationOverview :reservation="reservation" />
            <Link
                :href="`${confirmation(reservation.id).url}?edit=1`"
                class="inline-flex min-h-11 items-center rounded-sm text-sm text-primary underline focus-visible:outline-2 focus-visible:outline-offset-4"
                >Edit reservation</Link
            >
            <div
                id="group-pre-order"
                tabindex="-1"
                class="scroll-mt-6 space-y-10 sm:space-y-[60px]"
            >
                <a
                    v-if="canEditOwnPreOrder"
                    :href="`/reservations/${reservation.id}/owner-pre-order`"
                    class="inline-flex min-h-11 items-center rounded-md border border-primary px-4 text-sm font-medium text-primary focus-visible:outline-2"
                    >Add or edit your pre-order</a
                >
                <DeferredSection
                    data="groupOrder"
                    :resolved="Boolean(submittedResult)"
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
                    <template v-if="displayedOrder">
                        <GuestInvitationManager
                            v-if="canManageInvitations"
                            :reservation-id="reservation.id"
                            :timezone="reservation.timezone"
                            :participants="displayedOrder.participants"
                            :selections="displayedOrder.summary.guests"
                            :refreshing="refreshing || submitting || confirming"
                            @changed="refresh"
                        />
                        <ReservationParticipants
                            v-else
                            :participants="displayedOrder.participants"
                            :selections="displayedOrder.summary.guests"
                            :timezone="reservation.timezone"
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
                                    :disabled="
                                        refreshing || submitting || confirming
                                    "
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
                            <p
                                v-if="submittedResult"
                                role="status"
                                class="rounded-xl bg-accent p-4 text-sm"
                            >
                                Group pre-order submitted. Manage permitted
                                changes in Group Pre-Order until 30 minutes
                                before the reservation.
                            </p>
                            <OwnerRestaurantReview
                                v-if="restaurantReview"
                                :review="restaurantReview"
                                :reservation-id="reservation.id"
                                @changed="refresh"
                            />
                            <GroupPreOrderSummary
                                :order="displayedOrder"
                                :timezone="reservation.timezone"
                            />
                            <GroupPreOrderSubmission
                                :reservation-id="reservation.id"
                                :reservation-status="reservation.status"
                                :order="displayedOrder"
                                :can-submit="canSubmitPreOrder"
                                :refreshing="refreshing"
                                :refresh-version="refreshVersion"
                                @submitted="onSubmitted"
                                @refresh="refresh"
                                @busy="submitting = $event"
                                @confirming="confirming = $event"
                            />
                        </div>
                    </template>
                </DeferredSection>
            </div>
        </div>
    </SiteContainer>
    <OwnerGroupPreOrderModal
        v-if="groupOpen"
        :reservation="reservation"
        @close="groupOpen = false"
        @changed="refresh"
    />
</template>
