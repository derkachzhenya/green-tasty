<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { bookingTimes } from '@/lib/booking';
import type {
    BookingFilters,
    BookingRestaurant,
    BookingRules,
} from '@/types/booking';
import GuestCounter from './GuestCounter.vue';
defineProps<{
    filters: BookingFilters;
    restaurants: BookingRestaurant[];
    rules: BookingRules;
    errors: Record<string, string>;
    busy: boolean;
}>();
const emit = defineEmits<{ change: [Partial<BookingFilters>]; search: [] }>();
</script>
<template>
    <form
        novalidate
        class="space-y-5 rounded-[20px] bg-card p-6 sm:p-10"
        @submit.prevent="emit('search')"
    >
        <h1 class="mb-8 text-center text-[32px] leading-10 font-medium">
            Book a Table
        </h1>
        <div class="space-y-1">
            <label for="booking-location" class="text-sm">Location</label>
            <select
                id="booking-location"
                :value="filters.restaurant"
                :disabled="busy"
                class="booking-input"
                :aria-invalid="!!errors.restaurant"
                aria-describedby="restaurant-error"
                @change="
                    emit('change', {
                        restaurant: ($event.target as HTMLSelectElement).value,
                    })
                "
            >
                <option value="">All Locations</option>
                <option
                    v-for="restaurant in restaurants"
                    :key="restaurant.id"
                    :value="restaurant.slug"
                >
                    {{ restaurant.address }}
                </option>
            </select>
            <InputError id="restaurant-error" :message="errors.restaurant" />
        </div>
        <div class="space-y-1">
            <label for="booking-date" class="text-sm">Date</label>
            <input
                id="booking-date"
                type="date"
                :value="filters.date"
                :min="rules.min_date"
                :max="rules.max_date"
                :disabled="busy"
                class="booking-input"
                :aria-invalid="!!errors.date"
                aria-describedby="date-error booking-rules"
                @input="
                    emit('change', {
                        date: ($event.target as HTMLInputElement).value,
                    })
                "
            />
            <InputError id="date-error" :message="errors.date" />
        </div>
        <div
            class="grid grid-cols-1 gap-3 min-[400px]:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]"
        >
            <div class="min-w-0 space-y-1">
                <label for="booking-time" class="text-sm">Time</label>
                <select
                    id="booking-time"
                    :value="filters.time"
                    :disabled="busy"
                    class="booking-input"
                    :aria-invalid="!!errors.time"
                    aria-describedby="time-error booking-rules"
                    @change="
                        emit('change', {
                            time: ($event.target as HTMLSelectElement).value,
                        })
                    "
                >
                    <option value="" disabled>e.g. 10:00</option>
                    <option value="all">Show All</option>
                    <option v-for="time in bookingTimes" :key="time">
                        {{ time }}
                    </option>
                </select>
                <InputError id="time-error" :message="errors.time" />
            </div>
            <div class="min-w-0 space-y-1">
                <label for="guest-count" class="text-sm">Guests</label>
                <GuestCounter
                    :model-value="filters.guest_count"
                    :max="rules.max_guests ?? 255"
                    :disabled="busy"
                    :invalid="!!errors.guest_count"
                    described-by="guests-error booking-rules"
                    @update:model-value="
                        emit('change', { guest_count: $event })
                    "
                />
                <InputError id="guests-error" :message="errors.guest_count" />
            </div>
        </div>
        <Button type="submit" :disabled="busy" class="h-14 w-full text-base">{{
            busy ? 'Checking availability…' : 'Find a Table'
        }}</Button>
        <p id="booking-rules" class="text-xs leading-5 text-muted-foreground">
            {{ rules.duration_minutes / 60 }}-hour bookings ·
            {{ rules.timezone }}. Guest count includes you.
        </p>
    </form>
</template>
