<script setup lang="ts">
import type { ReservationDetails } from '@/types/reservation';

defineProps<{ reservation: ReservationDetails }>();
</script>

<template>
    <section
        aria-labelledby="reservation-details-heading"
        class="space-y-6 rounded-2xl border border-border bg-card p-6 sm:p-8"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h2
                    id="reservation-details-heading"
                    class="text-2xl leading-8 font-medium wrap-anywhere"
                >
                    {{ reservation.restaurant.name }}
                </h2>
                <p class="mt-2 text-sm leading-6 wrap-anywhere">
                    {{ reservation.restaurant.address }}
                </p>
            </div>
            <span
                class="rounded-full bg-accent px-3 py-1 text-sm font-medium wrap-anywhere capitalize"
                >{{ reservation.status }}</span
            >
        </div>
        <dl class="grid gap-x-8 gap-y-5 text-sm sm:grid-cols-2 xl:grid-cols-4">
            <div>
                <dt class="text-muted-foreground">Date</dt>
                <dd class="mt-1 font-medium">{{ reservation.date }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">
                    Time · {{ reservation.timezone }}
                </dt>
                <dd class="mt-1 font-medium">
                    {{ reservation.time }} – {{ reservation.end_time
                    }}<span v-if="reservation.end_date !== reservation.date">
                        ({{ reservation.end_date }})</span
                    >
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Table</dt>
                <dd class="mt-1 font-medium wrap-anywhere">
                    {{ reservation.table?.name ?? 'No longer assigned' }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Guests, including you</dt>
                <dd class="mt-1 font-medium">{{ reservation.guest_count }}</dd>
            </div>
        </dl>
    </section>
</template>
