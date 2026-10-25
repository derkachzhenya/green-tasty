<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MapPin, CalendarDays, Clock, Users, Salad } from '@lucide/vue';
import { computed } from 'vue';
import { bookingDate } from '@/lib/booking';
import { show } from '@/routes/reservations';
import type { OwnedReservation } from '@/types/reservation';
const props = defineProps<{ reservation: OwnedReservation }>();
defineEmits<{ invite: []; feedback: []; group: [] }>();
const phase = computed(
    () =>
        props.reservation.phase ??
        (props.reservation.status === 'cancelled'
            ? 'canceled'
            : props.reservation.status === 'completed'
              ? 'completed'
              : 'upcoming'),
);
const label = computed(
    () =>
        ({
            upcoming:
                props.reservation.status === 'draft' ? 'Draft' : 'Reserved',
            in_progress: 'In Progress',
            completed: 'Completed',
            canceled: 'Cancelled',
        })[phase.value],
);
</script>
<template>
    <article
        class="flex h-full min-w-0 flex-col overflow-hidden rounded-3xl bg-card shadow-[0_2px_12px_#00000009]"
        :aria-labelledby="`reservation-heading-${reservation.id}`"
    >
        <p
            class="py-1 text-center text-sm leading-6 font-medium"
            :class="{
                'bg-[#dfe8ff]': phase === 'upcoming',
                'bg-[#fff3d1]': phase === 'in_progress',
                'bg-[#e8ffea]': phase === 'completed',
                'bg-[#fce8ee]': phase === 'canceled',
            }"
        >
            {{ label }}
        </p>
        <div class="flex flex-1 flex-col px-6 pt-4 pb-3">
            <h2
                :id="`reservation-heading-${reservation.id}`"
                class="flex items-start gap-2 text-sm leading-6 font-medium"
            >
                <MapPin
                    class="mt-1 size-4 shrink-0 text-primary"
                    aria-hidden="true"
                /><Link
                    :href="show(reservation.id)"
                    :aria-label="`View reservation at ${reservation.restaurant.name} on ${reservation.date} at ${reservation.time}`"
                    class="min-w-0 rounded wrap-anywhere focus-visible:outline-2"
                    >{{ reservation.restaurant.address
                    }}<span class="sr-only">
                        — View reservation at
                        {{ reservation.restaurant.name }} on
                        {{ reservation.date }} at {{ reservation.time }}</span
                    ></Link
                >
            </h2>
            <div
                v-if="reservation.can_invite"
                class="mt-3 flex flex-wrap items-center justify-between gap-2"
            >
                <p
                    class="max-w-[200px] text-xs leading-4 text-muted-foreground"
                >
                    Share link with your guests so they can place their
                    pre-orders
                </p>
                <button
                    type="button"
                    class="min-h-11 rounded-md border border-primary px-3 text-xs font-medium text-primary hover:bg-accent focus-visible:outline-2"
                    @click="$emit('invite')"
                >
                    Invite Guests
                </button>
            </div>
            <dl
                class="my-4 grid grid-cols-2 gap-x-3 gap-y-2 border-y border-border/40 py-4 text-sm leading-5"
            >
                <div class="flex min-w-0 items-start gap-2">
                    <CalendarDays
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <dt class="sr-only">Date</dt>
                    <dd class="wrap-anywhere">
                        {{ bookingDate(reservation.date) }}
                    </dd>
                </div>
                <div
                    v-if="reservation.guest_count > 1"
                    class="flex min-w-0 items-start gap-2"
                >
                    <Salad
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <dt class="sr-only">Submitted pre-orders</dt>
                    <dd>
                        Pre-orders:
                        <span
                            :class="{
                                'text-destructive':
                                    reservation.submitted_pre_orders ===
                                    reservation.guest_count,
                            }"
                            >{{ reservation.submitted_pre_orders ?? 0 }}</span
                        >/{{ reservation.guest_count }}
                    </dd>
                </div>
                <div class="col-start-1 flex min-w-0 items-start gap-2">
                    <Clock
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <dt class="sr-only">Time</dt>
                    <dd>
                        {{ reservation.time }} – {{ reservation.end_time
                        }}<span
                            v-if="reservation.end_date !== reservation.date"
                            class="block text-xs"
                            >({{ reservation.end_date }})</span
                        ><span class="sr-only">
                            {{ reservation.timezone }}</span
                        >
                    </dd>
                </div>
                <div class="flex items-start gap-2">
                    <Users
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <dt class="sr-only">Guests</dt>
                    <dd>
                        {{ reservation.guest_count }}
                        {{ reservation.guest_count === 1 ? 'Guest' : 'Guests' }}
                    </dd>
                </div>
            </dl>
            <div class="mt-auto flex min-h-11 flex-wrap justify-end gap-2">
                <a
                    v-if="reservation.can_add_pre_order"
                    :href="`/reservations/${reservation.id}/owner-pre-order`"
                    class="inline-flex min-h-11 items-center rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-2"
                    >Add Pre-Order</a
                >
                <button
                    v-if="reservation.can_view_group"
                    type="button"
                    @click="$emit('group')"
                    class="inline-flex min-h-11 items-center rounded-md border border-primary px-3 text-xs font-medium text-primary hover:bg-accent focus-visible:outline-2"
                >
                    Group Pre-Order
                </button>
                <button
                    v-if="reservation.feedback_action"
                    type="button"
                    class="min-h-11 rounded-md px-3 text-xs font-medium focus-visible:outline-2"
                    :class="
                        reservation.feedback_action === 'update'
                            ? 'border border-primary text-primary'
                            : 'bg-primary text-primary-foreground'
                    "
                    @click="$emit('feedback')"
                >
                    {{
                        reservation.feedback_action === 'update'
                            ? 'Update Feedback'
                            : 'Leave Feedback'
                    }}
                </button>
            </div>
        </div>
    </article>
</template>
