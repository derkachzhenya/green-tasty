<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { bookingTimes } from '@/lib/booking';
import GuestCounter from './GuestCounter.vue';
defineProps<{
    time: string;
    guests: number;
    capacity: number;
    duration: number;
    busy: boolean;
    blocked?: boolean;
    errors: Record<string, string>;
}>();
defineEmits<{ 'update:time': [string]; 'update:guests': [number]; save: [] }>();
</script>
<template>
    <form class="space-y-6" novalidate @submit.prevent="$emit('save')">
        <div
            class="grid grid-cols-1 gap-3 min-[400px]:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]"
        >
            <div class="min-w-0 space-y-1">
                <label for="edit-time" class="text-sm">Time</label
                ><select
                    id="edit-time"
                    :value="time"
                    :disabled="busy || blocked"
                    class="booking-input"
                    :aria-invalid="!!errors.time"
                    aria-describedby="edit-time-error"
                    @change="
                        $emit(
                            'update:time',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option v-for="slot in bookingTimes" :key="slot">
                        {{ slot }}
                    </option></select
                ><InputError id="edit-time-error" :message="errors.time" />
            </div>
            <div class="min-w-0 space-y-1">
                <label for="edit-guests" class="text-sm">Guests</label
                ><GuestCounter
                    id="edit-guests"
                    :model-value="guests"
                    :max="capacity"
                    :disabled="busy || blocked"
                    :invalid="!!errors.guest_count"
                    described-by="edit-guests-error"
                    @update:model-value="$emit('update:guests', $event)"
                /><InputError
                    id="edit-guests-error"
                    :message="errors.guest_count"
                />
            </div>
        </div>
        <div class="space-y-1">
            <label for="edit-duration" class="text-sm"
                >How long would you like to stay?</label
            ><select id="edit-duration" disabled class="booking-input">
                <option>{{ duration / 60 }} hours</option>
            </select>
            <p class="text-xs text-muted-foreground">
                Online reservations have a fixed {{ duration / 60 }}-hour
                duration.
            </p>
        </div>
        <Button type="submit" :disabled="busy || blocked" class="h-14 w-full">{{
            busy ? 'Saving changes…' : 'Save Changes'
        }}</Button>
    </form>
</template>
