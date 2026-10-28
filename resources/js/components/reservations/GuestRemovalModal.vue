<script setup lang="ts">
import BookingModal from '@/components/bookings/BookingModal.vue';
import { Button } from '@/components/ui/button';
defineProps<{
    open: boolean;
    guestName: string;
    busy: boolean;
    blocked: boolean;
    error: string;
    returnFocusTarget: HTMLElement | null;
}>();
defineEmits<{ close: []; confirm: [] }>();
</script>
<template>
    <BookingModal
        :open="open"
        title="Remove Guest"
        description="Confirm removal of this participant and their current pre-order."
        :busy="busy"
        :return-focus-target="returnFocusTarget"
        @update:open="!$event && $emit('close')"
    >
        <p class="guest-remove-copy my-6 text-sm leading-6 wrap-anywhere">
            Remove <strong>{{ guestName }}</strong> and their current pre-order
            from the active participants? Their invitation will stop working.
            Booked places and reservation history are retained.
        </p>
        <p
            v-if="error || blocked"
            role="alert"
            class="mb-4 text-sm leading-6 text-destructive"
        >
            {{
                error ||
                'The order is no longer editable. Close this confirmation and refresh the saved order.'
            }}
        </p>
        <div class="flex flex-wrap justify-end gap-3">
            <Button
                variant="ghost"
                class="min-h-11 underline underline-offset-8"
                :disabled="busy"
                @click="$emit('close')"
                >Cancel</Button
            >
            <Button
                variant="outline"
                class="min-h-11 border-destructive! text-destructive! hover:bg-destructive/10! hover:text-destructive!"
                :disabled="busy || blocked"
                @click="$emit('confirm')"
                >{{ busy ? 'Removing…' : 'Remove Guest' }}</Button
            >
        </div>
    </BookingModal>
</template>

<style scoped>
:global(.booking-dialog:has(.guest-remove-copy) [data-slot='dialog-close']) {
    width: 44px;
    height: 44px;
    top: 8px;
    right: 8px;
    display: grid;
    place-items: center;
}
</style>
