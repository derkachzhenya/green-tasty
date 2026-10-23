<script setup lang="ts">
import { computed } from 'vue';
import ContentState from '@/components/site/ContentState.vue';
import type { GuestOrder, ReservationParticipant } from '@/types/reservation';

const props = withDefaults(
    defineProps<{
        participants: ReservationParticipant[];
        selections?: GuestOrder[];
    }>(),
    { selections: () => [] },
);
const quantities = computed(
    () =>
        new Map(
            props.selections.map((guest) => [
                guest.reservation_guest_id,
                guest.total_quantity,
            ]),
        ),
);

const invitationLabels: Record<
    ReservationParticipant['invitation_status'],
    string
> = {
    owner: 'Owner · no invitation needed',
    not_invited: 'Not invited',
    active: 'Active invitation',
    expired: 'Expired invitation',
    revoked: 'Revoked invitation',
    unavailable: 'Invitation unavailable',
};
</script>

<template>
    <section aria-labelledby="participants-heading" class="space-y-5">
        <h2 id="participants-heading" class="text-2xl leading-8 font-medium">
            Participants
            <span class="text-base font-normal text-muted-foreground"
                >({{ participants.length }})</span
            >
        </h2>
        <ContentState
            v-if="!participants.length"
            title="No participants yet"
            description="No participants have been recorded for this reservation."
        />
        <ul
            v-else
            aria-label="Reservation participants"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <li
                v-for="participant in participants"
                :key="participant.id"
                class="min-w-0 space-y-3 rounded-2xl border border-border bg-card p-5"
            >
                <p class="font-medium wrap-anywhere">
                    {{ participant.name
                    }}<span
                        v-if="participant.is_owner"
                        class="ml-2 text-sm font-normal text-muted-foreground"
                        >(you)</span
                    >
                </p>
                <p class="text-sm">
                    {{ invitationLabels[participant.invitation_status] }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{
                        quantities.has(participant.id)
                            ? `${quantities.get(participant.id)} selected`
                            : 'No saved selections'
                    }}
                </p>
                <p
                    v-if="!participant.is_owner"
                    class="text-sm text-muted-foreground"
                >
                    Response:
                    <span class="wrap-anywhere capitalize">{{
                        participant.response_status
                    }}</span>
                </p>
            </li>
        </ul>
    </section>
</template>
