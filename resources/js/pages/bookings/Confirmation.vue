<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, Copy, X } from '@lucide/vue';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import EditBookingFields from '@/components/bookings/EditBookingFields.vue';
import ReservationFacts from '@/components/bookings/ReservationFacts.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { useGuestInvitations } from '@/composables/useGuestInvitations';
import { bookingDate, bookingGuestError } from '@/lib/booking';
import { dashboard } from '@/routes';
import { create as bookingCreate } from '@/routes/booking';
import { show as reservationShow } from '@/routes/reservations';
import { update } from '@/routes/reservations/booking';
import type {
    BookingConfirmation,
    BookingRestaurant,
    BookingRules,
} from '@/types/booking';

const BookingPage = defineAsyncComponent(() => import('./Create.vue'));

type BookingBackground = {
    restaurant: BookingRestaurant | null;
    restaurants: BookingRestaurant[];
    bookingRules: BookingRules;
    initialSelection: Record<string, unknown>;
};
const props = withDefaults(
    defineProps<{
        reservation: BookingConfirmation;
        canEdit: boolean;
        startEditing?: boolean;
        bookingBackground: BookingBackground;
    }>(),
    { startEditing: false },
);
const open = ref(true);
const editing = ref(props.startEditing && props.canEdit);
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
function dismissCopyMessage(): void {
    copyMessage.value = '';
}
function handleModalOpen(next: boolean): void {
    if (next) {
        open.value = true;
        return;
    }
    open.value = false;
    router.visit(
        editing.value
            ? reservationShow(props.reservation.id).url
            : bookingCreate().url,
    );
}
function beginEdit(): void {
    form.time = props.reservation.time;
    form.guest_count = props.reservation.guest_count;
    form.duration_minutes = duration.value;
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
    () => props.startEditing,
    (value) => {
        if (value && props.canEdit) beginEdit();
    },
);
watch(
    () => props.reservation.id,
    () => {
        invitation.clear();
        editing.value = props.startEditing && props.canEdit;
        open.value = true;
    },
);
</script>
<template>
    <Head title="Your reservation"
        ><meta name="robots" content="noindex"
    /></Head>
    <BookingPage
        :restaurant="bookingBackground.restaurant"
        :restaurants="bookingBackground.restaurants"
        :booking-rules="bookingBackground.bookingRules"
        :initial-selection="bookingBackground.initialSelection"
        background-only
    />
    <div
        v-if="copyMessage"
        role="status"
        class="gt-toast flex items-start gap-3 rounded-lg border border-primary bg-[#edffed] px-5 py-4 shadow-lg"
    >
        <CircleCheck class="mt-0.5 size-5 shrink-0 fill-primary text-white" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">
                {{
                    copyMessage === 'Invitation link copied.'
                        ? 'Link copied!'
                        : copyMessage
                }}
            </p>
            <p
                v-if="copyMessage === 'Invitation link copied.'"
                class="mt-1 text-sm"
            >
                Share it with your friend.
            </p>
        </div>
        <button
            type="button"
            aria-label="Dismiss notification"
            class="-m-2 rounded-md p-2 focus-visible:outline-2"
            @click="dismissCopyMessage"
        >
            <X class="size-5" />
        </button>
    </div>
    <BookingModal
        :open="open"
        :title="
            editing
                ? 'Edit Reservation'
                : reservation.status === 'confirmed'
                  ? 'Reservation Confirmed'
                  : 'Reservation details'
        "
        description="Saved reservation details and individual guest invitations."
        :subtitle="
            editing
                ? `${reservation.restaurant.address} · ${reservation.table?.name ?? 'No longer assigned'} · ${bookingDate(reservation.date)}`
                : undefined
        "
        :busy="form.processing || busy"
        @update:open="handleModalOpen"
    >
        <ReservationFacts
            v-if="!editing"
            :address="reservation.restaurant.address"
            :table="reservation.table?.name ?? 'No longer assigned'"
            :date="reservation.date"
            :time="reservation.time"
            :end-date="reservation.end_date"
            :end-time="reservation.end_time"
            :guests="reservation.guest_count"
            :timezone="reservation.timezone"
            :show-table="false"
            :show-timezone="false"
        />
        <template v-if="editing">
            <p v-if="editError" role="alert" class="text-sm text-destructive">
                {{ editError }}
            </p>
            <EditBookingFields
                v-model:time="form.time"
                v-model:guests="form.guest_count"
                v-model:duration="form.duration_minutes"
                :capacity="reservation.table?.seats ?? 1"
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
                        class="flex min-w-0 items-center gap-2 rounded-lg border border-[#b9e6bd] bg-[#efffef] px-3"
                    >
                        <input
                            :value="issued.invitation_url"
                            readonly
                            aria-label="Guest invite link"
                            autocomplete="off"
                            spellcheck="false"
                            class="min-h-12 min-w-0 flex-1 bg-transparent text-sm focus-visible:outline-2"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        /><button
                            type="button"
                            aria-label="Copy invite link"
                            :disabled="copying"
                            class="grid size-11 shrink-0 place-items-center rounded-md focus-visible:outline-2"
                            @click="invitation.copy"
                        >
                            <Copy class="size-5" />
                        </button>
                    </div>
                    <p class="text-xs leading-5 text-muted-foreground">
                        You are able to share this link for pre-order with
                        {{ issued.name }}.
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
                        Enter a guest name to create their private pre-order
                        link. Create a separate link for each guest.
                    </p>
                </form>
                <p v-if="error" role="alert" class="text-sm text-destructive">
                    {{ error }}
                </p>
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-2">
                <Button as-child variant="outline" class="min-h-14 bg-card"
                    ><Link :href="dashboard()">View Reservation</Link></Button
                ><Button as-child class="min-h-14"
                    ><a
                        v-if="canEdit"
                        :href="`/reservations/${reservation.id}/owner-pre-order`"
                        >Make Pre-Order</a
                    ><Link
                        v-else
                        :href="`${reservationShow(reservation.id).url}#group-pre-order`"
                        >View Pre-Order</Link
                    ></Button
                >
            </div>
        </template>
    </BookingModal>
</template>
