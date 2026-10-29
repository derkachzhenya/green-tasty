<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
import { Copy, CircleCheck, X } from '@lucide/vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import { Button } from '@/components/ui/button';
import { sessionJson } from '@/lib/sessionJson';
import { bookingDate } from '@/lib/booking';
import type { OwnedReservation } from '@/types/reservation';
const props = defineProps<{ reservation: OwnedReservation }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const link = ref(''),
    error = ref(''),
    copied = ref(false),
    loading = ref(true);
const controller = new AbortController();
async function load() {
    loading.value = true;
    error.value = '';
    try {
        const data = (await sessionJson(
            `/reservations/${props.reservation.id}/invite-link`,
            'post',
            controller.signal,
            {},
        )) as { invitation_url: string };
        link.value = data.invitation_url;
    } catch {
        if (!controller.signal.aborted)
            error.value = 'Unable to load the invite link. Please try again.';
    } finally {
        loading.value = false;
    }
}
async function copy() {
    try {
        await navigator.clipboard.writeText(link.value);
        copied.value = true;
        error.value = '';
    } catch {
        error.value = 'Select and copy the link below.';
    }
}
onMounted(load);
onBeforeUnmount(() => controller.abort());
</script>
<template>
    <BookingModal
        :open="true"
        title="Guest Invite link"
        description="Share this link with your guests so they can place their pre-orders."
        @update:open="!$event && emit('close')"
    >
        <p class="-mt-3 text-sm leading-6 text-muted-foreground">
            Share this link with your guests so they can place their pre-orders.
        </p>
        <dl
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-2 border-b border-border/40 pb-5 text-sm leading-5"
        >
            <dt class="font-medium">Location:</dt>
            <dd>{{ reservation.restaurant.address }}</dd>
            <dt class="font-medium">Date:</dt>
            <dd>{{ bookingDate(reservation.date) }}</dd>
            <dt class="font-medium">Time:</dt>
            <dd>{{ reservation.time }} – {{ reservation.end_time }}</dd>
            <dt class="font-medium">Guests:</dt>
            <dd>{{ reservation.guest_count }} people</dd>
        </dl>
        <p v-if="loading" role="status" class="text-sm">Loading invite link…</p>
        <div v-else-if="link" class="space-y-2">
            <label for="dashboard-invite-link" class="text-sm font-medium"
                >Invite Link</label
            >
            <div class="flex rounded-md border border-primary/20 bg-[#f0fff1]">
                <input
                    id="dashboard-invite-link"
                    :value="link"
                    readonly
                    class="min-h-11 w-full min-w-0 px-3 text-xs"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <button
                    type="button"
                    aria-label="Copy invite link"
                    class="grid size-11 shrink-0 place-items-center text-muted-foreground"
                    @click="copy"
                >
                    <Copy class="size-5" />
                </button>
            </div>
            <p class="text-xs text-muted-foreground">
                Copy this link, then paste it into a message to your guests.
            </p>
        </div>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
        <Button v-if="!loading && !link" variant="outline" @click="load"
            >Try again</Button
        >
    </BookingModal>
    <div
        v-if="copied"
        role="status"
        class="green-tasty fixed top-24 right-4 z-[100] flex w-[calc(100%-32px)] max-w-[330px] gap-3 rounded-md border border-primary/30 bg-[#e8ffea] px-4 py-3 text-xs shadow-sm sm:right-8"
    >
        <CircleCheck class="size-5 shrink-0 text-primary" />
        <p>
            <strong class="font-medium">Link copied!</strong><br /><span
                class="mt-1 block"
                >Share it with your friends.</span
            >
        </p>
        <button
            aria-label="Dismiss notification"
            class="ml-auto"
            @click="copied = false"
        >
            <X class="size-4" />
        </button>
    </div>
</template>
