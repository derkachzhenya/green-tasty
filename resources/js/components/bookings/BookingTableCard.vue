<script setup lang="ts">
import { computed } from 'vue';
import { bookingPhoto } from '@/lib/booking';
import type { BookingSearchTable } from '@/types/booking';
const props = defineProps<{ table: BookingSearchTable; busy: boolean }>();
defineEmits<{ select: [string] }>();
const photo = computed(() => bookingPhoto(props.table.restaurant));
</script>
<template>
    <article
        class="overflow-hidden rounded-2xl bg-card shadow-[0_2px_10px_#0000000a]"
        :aria-labelledby="`table-${table.id}`"
    >
        <img
            v-if="photo"
            :src="photo"
            :alt="`${table.restaurant.name} dining area`"
            width="428"
            height="180"
            class="aspect-[428/180] w-full object-cover"
        />
        <div class="space-y-3 p-4 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3
                    :id="`table-${table.id}`"
                    class="text-sm font-medium text-primary"
                >
                    {{ table.name }}
                </h3>
                <p class="text-xs text-muted-foreground">
                    Up to {{ table.seats }} guests
                </p>
            </div>
            <p class="text-base font-medium wrap-anywhere">
                {{ table.restaurant.address }}
            </p>
            <div
                class="grid grid-cols-4 gap-2 border-t border-border pt-3 min-[450px]:grid-cols-6 lg:grid-cols-4 xl:grid-cols-6"
                :aria-label="`Time slots for ${table.name}`"
            >
                <button
                    v-for="slot in table.slots"
                    :key="slot.time"
                    type="button"
                    :disabled="busy || !slot.available"
                    :aria-label="`${table.name}, ${table.restaurant.address}, ${slot.time}${slot.available ? '' : ', unavailable'}`"
                    class="min-h-9 min-w-0 rounded-md border border-primary px-1 text-xs text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed disabled:border-border disabled:text-muted-foreground/60 disabled:hover:bg-transparent"
                    @click="$emit('select', slot.time)"
                >
                    {{ slot.time }}
                </button>
            </div>
        </div>
    </article>
</template>
