<script setup lang="ts">
import { CircleCheck, Copy, X } from '@lucide/vue';
import { computed, watch } from 'vue';
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
const copied = computed(() => copyMessage.value === 'Invitation link copied.');
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
    <div
        v-if="copied"
        role="status"
        class="green-tasty gt-toast flex items-start gap-3 rounded-lg border border-primary bg-[#edffed] px-5 py-4 font-[Poppins,sans-serif] shadow-lg"
    >
        <CircleCheck class="mt-0.5 size-5 shrink-0 fill-primary text-white" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium">Link copied!</p>
            <p class="mt-1 text-sm">Share it with your guest.</p>
        </div>
        <button
            type="button"
            aria-label="Dismiss notification"
            class="-m-2 rounded-md p-2 focus-visible:outline-2"
            @click="copyMessage = ''"
        >
            <X class="size-5" />
        </button>
    </div>
    <BookingModal
        description="Reservation details and an individual guest invitation link."
        :open="true"
        title="Guest Invite link"
        subtitle="Share this link with your guest so they can place their pre-order."
        :busy="busy"
        @update:open="!$event && close()"
    >
        <dl
            class="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 border-b border-border/40 pb-6 text-sm leading-6"
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
        <div v-else>
            <label
                for="dashboard-invite-link"
                class="mb-2 block text-sm font-medium"
                >Invite Link</label
            >
            <div
                class="flex items-center rounded-lg border border-[#bde9c1] bg-[#f0fff2]"
            >
                <input
                    id="dashboard-invite-link"
                    :value="issued.invitation_url"
                    readonly
                    autocomplete="off"
                    spellcheck="false"
                    :aria-label="`Invite link for ${issued.name}`"
                    class="min-h-11 w-full min-w-0 rounded-lg bg-transparent px-4 text-sm focus-visible:outline-2 focus-visible:outline-primary"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <button
                    type="button"
                    aria-label="Copy invite link"
                    class="flex size-11 shrink-0 items-center justify-center rounded-lg text-foreground hover:bg-primary/5 focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-50"
                    :disabled="copying"
                    @click="copy"
                >
                    <Copy class="size-6" />
                </button>
            </div>
            <p class="mt-1 text-xs leading-5 text-muted-foreground">
                Copy this link, then paste it into a message to your guest.
            </p>
            <p
                v-if="copyMessage && !copied"
                role="status"
                class="mt-3 text-sm text-destructive"
            >
                {{ copyMessage }}
            </p>
        </div>
    </BookingModal>
</template>
