<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck } from '@lucide/vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { show } from '@/routes/restaurants';
import { create } from '@/routes/restaurants/bookings';
import { show as reservationShow } from '@/routes/reservations';
import type { BookingConfirmation } from '@/types/booking';

defineProps<{ reservation: BookingConfirmation }>();
</script>

<template>
    <Head title="Your reservation"
        ><meta name="robots" content="noindex"
    /></Head>
    <SiteContainer>
        <section
            class="mx-auto my-10 max-w-2xl space-y-6 rounded-2xl border border-border bg-card p-6 sm:my-16 sm:p-10"
            aria-labelledby="confirmation-heading"
        >
            <CircleCheck
                v-if="reservation.status === 'confirmed'"
                class="size-12 text-primary"
                aria-hidden="true"
            />
            <h1
                id="confirmation-heading"
                class="text-3xl leading-10 font-medium"
            >
                {{
                    reservation.status === 'confirmed'
                        ? 'Reservation Confirmed'
                        : 'Reservation details'
                }}
            </h1>
            <p class="text-sm">
                Reference:
                <span class="font-medium wrap-anywhere">{{
                    reservation.code
                }}</span>
            </p>
            <dl
                class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-6 gap-y-4 text-sm leading-6"
            >
                <dt>Status</dt>
                <dd class="font-medium capitalize">{{ reservation.status }}</dd>
                <dt>Restaurant</dt>
                <dd class="wrap-anywhere">{{ reservation.restaurant.name }}</dd>
                <dt>Location</dt>
                <dd class="wrap-anywhere">
                    {{ reservation.restaurant.address }}
                </dd>
                <dt>Table</dt>
                <dd class="wrap-anywhere">
                    {{ reservation.table?.name ?? 'No longer assigned' }}
                </dd>
                <dt>Date</dt>
                <dd>{{ reservation.date }}</dd>
                <dt>Time</dt>
                <dd>
                    {{ reservation.time }} – {{ reservation.end_time
                    }}<span v-if="reservation.end_date !== reservation.date">
                        ({{ reservation.end_date }})</span
                    >
                </dd>
                <dt>Timezone</dt>
                <dd>{{ reservation.timezone }}</dd>
                <dt>Guests</dt>
                <dd>{{ reservation.guest_count }}</dd>
            </dl>
            <div class="flex flex-wrap gap-3">
                <Button as-child class="min-h-11"
                    ><Link :href="reservationShow(reservation.id)"
                        >View reservation</Link
                    ></Button
                >
                <Button as-child class="min-h-11"
                    ><Link
                        :href="`${show(reservation.restaurant.slug).url}#menu`"
                        >View menu</Link
                    ></Button
                >
                <Button
                    as-child
                    variant="outline"
                    class="min-h-11 bg-card dark:bg-card"
                    ><Link :href="create(reservation.restaurant.slug)"
                        >Book another table</Link
                    ></Button
                >
            </div>
        </section>
    </SiteContainer>
</template>
