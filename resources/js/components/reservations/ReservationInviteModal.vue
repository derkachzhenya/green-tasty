<script setup lang="ts">
import { watch } from 'vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import { Button } from '@/components/ui/button';
import { useGuestInvitations } from '@/composables/useGuestInvitations';
import { bookingDate } from '@/lib/booking';
import type { OwnedReservation } from '@/types/reservation';
const props = defineProps<{ reservation: OwnedReservation }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const {
    name,
    issued,
    nameError,
    error,
    busy,
    uncertain,
    copying,
    copyMessage,
    open,
    clear,
    submit,
    copy,
} = useGuestInvitations(
    () => props.reservation.id,
    () => emit('changed'),
);
open('create');
watch(
    () => props.reservation.id,
    () => open('create'),
);
function close() {
    if (!busy.value) {
        clear();
        emit('close');
    }
}
</script>
<template>
    <BookingModal
        description="Reservation details and available actions."
        :open="true"
        title="Guest Invite link"
        :busy="busy"
        @update:open="!$event && close()"
    >
        <p class="mb-6 text-sm leading-6 text-muted-foreground">
            Share this individual link with your guest so they can place their
            pre-order.
        </p>
        <dl
            class="mb-6 grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 border-b border-border/40 pb-6 text-sm leading-6"
        >
            <dt class="font-medium">Location:</dt>
            <dd class="wrap-anywhere">{{ reservation.restaurant.address }}</dd>
            <dt class="font-medium">Date:</dt>
            <dd>{{ bookingDate(reservation.date) }}</dd>
            <dt class="font-medium">Time:</dt>
            <dd>
                {{ reservation.time }} – {{ reservation.end_time
                }}<span v-if="reservation.end_date !== reservation.date">
                    ({{ reservation.end_date }})</span
                ><span class="sr-only"> {{ reservation.timezone }}</span>
            </dd>
            <dt class="font-medium">Guests:</dt>
            <dd>{{ reservation.guest_count }} people</dd>
        </dl>
        <form v-if="!issued" class="space-y-4" @submit.prevent="submit">
            <label class="block text-sm font-medium" for="dashboard-guest-name"
                >Guest name</label
            >
            <input
                id="dashboard-guest-name"
                v-model="name"
                required
                maxlength="255"
                autocomplete="off"
                :disabled="busy"
                :aria-invalid="!!nameError"
                aria-describedby="dashboard-invite-error"
                class="min-h-11 w-full rounded-md border border-border px-3 focus-visible:outline-2 focus-visible:outline-primary"
            />
            <p class="text-xs leading-5 text-muted-foreground">
                Create one private link per guest. Previously issued links can
                be regenerated from your reservation.
            </p>
            <p
                v-if="nameError || error"
                id="dashboard-invite-error"
                role="alert"
                class="text-sm text-destructive"
            >
                {{ nameError || error }}
            </p>
            <Button
                type="submit"
                class="min-h-11 w-full"
                :disabled="busy || uncertain"
                >{{ busy ? 'Creating…' : 'Create Invite Link' }}</Button
            >
        </form>
        <div v-else class="space-y-3">
            <label for="dashboard-invite-link" class="text-sm font-medium"
                >Invite Link for {{ issued.name }}</label
            >
            <div class="flex rounded-md border border-primary/30 bg-[#efffef]">
                <input
                    id="dashboard-invite-link"
                    :value="issued.invitation_url"
                    readonly
                    autocomplete="off"
                    spellcheck="false"
                    class="min-h-11 w-full min-w-0 px-3 text-sm focus-visible:outline-2"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <button
                    type="button"
                    class="min-h-11 shrink-0 rounded-md px-3 text-sm text-primary focus-visible:outline-2"
                    :disabled="copying"
                    @click="copy"
                >
                    Copy Link
                </button>
            </div>
            <p class="text-xs leading-5 text-muted-foreground">
                Copy this link, then paste it into a message to this guest. This
                link is shown only until you close the modal.
            </p>
            <p
                v-if="copyMessage"
                role="status"
                class="rounded-md bg-accent p-3 text-sm"
            >
                {{
                    copyMessage === 'Invitation link copied.'
                        ? 'Link copied! Share it with your guest.'
                        : copyMessage
                }}
            </p>
        </div>
    </BookingModal>
</template>
