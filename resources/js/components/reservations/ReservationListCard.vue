<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CalendarDays, Clock, Users, UtensilsCrossed } from '@lucide/vue';
import { show } from '@/routes/reservations';
import type { OwnedReservation } from '@/types/reservation';

defineProps<{ reservation: OwnedReservation }>();
</script>

<template>
    <article
        :aria-labelledby="`reservation-${reservation.id}-heading`"
        class="flex h-full min-w-0 flex-col overflow-hidden rounded-2xl border border-border bg-card shadow-sm"
    >
        <p
            :class="
                reservation.status === 'cancelled'
                    ? 'bg-destructive/10'
                    : reservation.status === 'draft'
                      ? 'bg-muted'
                      : 'bg-accent'
            "
            class="px-5 py-2 text-center text-sm font-medium wrap-anywhere capitalize"
        >
            <span class="sr-only">Reservation status: </span
            >{{ reservation.status }}
        </p>
        <div class="flex flex-1 flex-col p-5 sm:p-6">
            <h2
                :id="`reservation-${reservation.id}-heading`"
                class="text-lg font-medium wrap-anywhere"
            >
                {{ reservation.restaurant.name }}
            </h2>
            <p
                class="mt-2 text-sm leading-6 wrap-anywhere text-muted-foreground"
            >
                {{ reservation.restaurant.address }}
            </p>
            <dl
                class="my-5 grid gap-4 border-y border-border py-5 text-sm sm:grid-cols-2 md:grid-cols-1 lg:grid-cols-2"
            >
                <div class="flex items-start gap-3">
                    <CalendarDays
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <div>
                        <dt class="sr-only">Date</dt>
                        <dd>{{ reservation.date }}</dd>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <Users
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <div>
                        <dt class="sr-only">Guests, including you</dt>
                        <dd>
                            {{ reservation.guest_count }}
                            {{
                                reservation.guest_count === 1
                                    ? 'guest'
                                    : 'guests'
                            }}
                        </dd>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <Clock
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <div class="min-w-0">
                        <dt class="sr-only">Time</dt>
                        <dd>
                            {{ reservation.time }} – {{ reservation.end_time
                            }}<span
                                v-if="reservation.end_date !== reservation.date"
                            >
                                ({{ reservation.end_date }})</span
                            ><span
                                class="mt-1 block text-xs text-muted-foreground"
                                >{{ reservation.timezone }}</span
                            >
                        </dd>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <UtensilsCrossed
                        class="mt-0.5 size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <div class="min-w-0">
                        <dt class="text-muted-foreground">Group pre-order</dt>
                        <dd class="mt-1 font-medium wrap-anywhere capitalize">
                            {{ reservation.pre_order_status ?? 'Not started' }}
                        </dd>
                    </div>
                </div>
            </dl>
            <Link
                :href="show(reservation.id)"
                :aria-label="`View reservation at ${reservation.restaurant.name} on ${reservation.date} at ${reservation.time}`"
                class="mt-auto inline-flex min-h-11 items-center justify-center rounded-sm border border-primary px-4 py-2 text-sm font-medium text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >View reservation</Link
            >
        </div>
    </article>
</template>
