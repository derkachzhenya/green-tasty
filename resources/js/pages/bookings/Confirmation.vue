<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import EditBookingFields from '@/components/bookings/EditBookingFields.vue';
import ReservationFacts from '@/components/bookings/ReservationFacts.vue';
import InputError from '@/components/InputError.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { useGuestInvitations } from '@/composables/useGuestInvitations';
import { bookingGuestError } from '@/lib/booking';
import { dashboard } from '@/routes';
import { create } from '@/routes/booking';
import { show as reservationShow } from '@/routes/reservations';
import { update } from '@/routes/reservations/booking';
import type { BookingConfirmation } from '@/types/booking';

const props = defineProps<{
    reservation: BookingConfirmation;
    canEdit: boolean;
}>();
const open = ref(true);
const editing = ref(false);
const duration = computed(
    () =>
        (Date.parse(
            `${props.reservation.end_date}T${props.reservation.end_time}:00Z`,
        ) -
            Date.parse(
                `${props.reservation.date}T${props.reservation.time}:00Z`,
            )) /
        60000,
);
const form = useForm({
    time: props.reservation.time,
    guest_count: props.reservation.guest_count,
    duration_minutes: duration.value,
});
const editError = ref('');
const invitation = useGuestInvitations(
    () => props.reservation.id,
    () => {},
);
const {
    name,
    nameError,
    error,
    issued,
    busy,
    uncertain,
    copying,
    copyMessage,
} = invitation;
function generate(): void {
    if (busy.value || uncertain.value || issued.value) return;
    const guestName = name.value;
    invitation.open('create');
    name.value = guestName;
    void invitation.submit();
}
function beginEdit(): void {
    form.time = props.reservation.time;
    form.guest_count = props.reservation.guest_count;
    form.clearErrors();
    editing.value = true;
    open.value = true;
}
function save(): void {
    if (form.processing || editError.value) return;
    form.clearErrors();
    const guestError = bookingGuestError(
        form.guest_count,
        Math.min(props.reservation.table?.seats ?? 1, 255),
    );
    if (guestError) {
        form.setError('guest_count', guestError);
        return;
    }
    form.patch(update(props.reservation.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
        onNetworkError: () => {
            editError.value =
                'The result could not be confirmed. Reload the saved reservation before making more changes.';
            return false;
        },
        onHttpException: () => {
            editError.value =
                'Please reload the reservation before trying again.';
            return false;
        },
    });
}
watch(
    () => props.reservation,
    () => {
        editError.value = '';
    },
);
watch(
    () => props.reservation.id,
    () => {
        invitation.clear();
        editing.value = false;
        open.value = true;
    },
);
</script>
<template>
    <Head title="Your reservation"
        ><meta name="robots" content="noindex"
    /></Head>
    <SiteContainer class="py-12">
        <section
            class="mx-auto max-w-[600px] space-y-6 rounded-3xl bg-card p-6 sm:p-10"
        >
            <h1 class="text-[32px] leading-10 font-medium">
                {{
                    reservation.status === 'confirmed'
                        ? 'Reservation Confirmed'
                        : 'Reservation details'
                }}
            </h1>
            <p class="text-sm wrap-anywhere">
                Reference: {{ reservation.code }} · {{ reservation.status }}
            </p>
            <ReservationFacts
                :address="reservation.restaurant.address"
                :table="reservation.table?.name ?? 'No longer assigned'"
                :date="reservation.date"
                :time="reservation.time"
                :end-date="reservation.end_date"
                :end-time="reservation.end_time"
                :guests="reservation.guest_count"
                :timezone="reservation.timezone"
            />
            <div class="flex flex-wrap gap-3">
                <Button v-if="canEdit" variant="outline" @click="beginEdit"
                    >Edit Reservation</Button
                ><Button
                    variant="outline"
                    @click="
                        editing = false;
                        open = true;
                    "
                    >Show confirmation</Button
                ><Link
                    :href="dashboard()"
                    class="inline-flex min-h-11 items-center underline"
                    >My Reservations</Link
                ><Link
                    :href="create()"
                    class="inline-flex min-h-11 items-center underline"
                    >Book another table</Link
                >
            </div>
        </section>
    </SiteContainer>
    <BookingModal
        v-model:open="open"
        :title="
            editing
                ? 'Edit Reservation'
                : reservation.status === 'confirmed'
                  ? 'Reservation Confirmed'
                  : 'Reservation details'
        "
        description="Saved reservation details and individual guest invitations."
        :busy="form.processing || busy"
    >
        <ReservationFacts
            :address="reservation.restaurant.address"
            :table="reservation.table?.name ?? 'No longer assigned'"
            :date="reservation.date"
            :time="reservation.time"
            :end-date="reservation.end_date"
            :end-time="reservation.end_time"
            :guests="reservation.guest_count"
            :timezone="reservation.timezone"
        />
        <template v-if="editing">
            <p v-if="editError" role="alert" class="text-sm text-destructive">
                {{ editError }}
            </p>
            <EditBookingFields
                v-model:time="form.time"
                v-model:guests="form.guest_count"
                :capacity="reservation.table?.seats ?? 1"
                :duration="duration"
                :busy="form.processing"
                :blocked="!!editError"
                :errors="form.errors"
                @save="save"
            />
        </template>
        <template v-else>
            <div
                v-if="reservation.status === 'confirmed'"
                class="space-y-2 border-t border-border pt-4"
            >
                <h2 class="text-sm font-medium">Invite Link</h2>
                <template v-if="issued">
                    <div
                        class="flex min-w-0 items-center gap-2 rounded-lg border border-primary/20 bg-[#f0fff0] p-2"
                    >
                        <input
                            :value="issued.invitation_url"
                            readonly
                            aria-label="Guest invite link"
                            class="min-w-0 flex-1 bg-transparent text-sm"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        /><button
                            type="button"
                            :disabled="copying"
                            class="min-h-11 shrink-0 rounded-md px-2 text-sm text-primary focus-visible:outline-2"
                            @click="invitation.copy"
                        >
                            Copy Link
                        </button>
                    </div>
                    <p class="text-xs leading-5">
                        This individual link is for {{ issued.name }}. Create
                        separate invitations for other guests in your
                        reservation.
                    </p>
                </template>
                <form v-else class="space-y-2" @submit.prevent="generate">
                    <label for="invitation-name" class="text-xs"
                        >Guest name</label
                    >
                    <input
                        id="invitation-name"
                        v-model="name"
                        maxlength="255"
                        class="booking-input"
                        :disabled="busy || uncertain"
                        :aria-invalid="!!nameError"
                        aria-describedby="invitation-name-error"
                    />
                    <InputError
                        id="invitation-name-error"
                        :message="nameError"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="busy || uncertain"
                        class="min-h-11 w-full"
                        >{{
                            busy ? 'Creating invitation…' : 'Create Invite Link'
                        }}</Button
                    >
                    <p class="text-xs leading-5 text-muted-foreground">
                        Create one private link per guest for their pre-order.
                        Links are shown once; manage or regenerate them from
                        your reservation.
                    </p>
                </form>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
                <p
                    v-if="copyMessage"
                    role="status"
                    class="rounded-lg bg-accent p-3 text-sm"
                >
                    {{
                        copyMessage === 'Invitation link copied.'
                            ? 'Link copied! Share it with your guest.'
                            : copyMessage
                    }}
                </p>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <Button as-child variant="outline" class="min-h-14 bg-card"
                    ><Link :href="reservationShow(reservation.id)"
                        >View Reservation</Link
                    ></Button
                ><Button as-child class="min-h-14"
                    ><Link
                        :href="`${reservationShow(reservation.id).url}#group-pre-order`"
                        >Make Pre-Order</Link
                    ></Button
                >
            </div>
            <Button
                v-if="canEdit"
                variant="outline"
                class="min-h-11"
                @click="beginEdit"
                >Edit Reservation</Button
            >
        </template>
    </BookingModal>
</template>
