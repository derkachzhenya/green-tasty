<script setup lang="ts">
import { computed } from 'vue';
import ContentState from '@/components/site/ContentState.vue';
import type { DashboardOrder } from '@/types/reservation';

const props = defineProps<{ order: DashboardOrder }>();
const statusLabel = computed(() =>
    props.order.status === null ? 'Not started' : props.order.status,
);
const locked = computed(
    () =>
        (props.order.status !== 'draft' && props.order.status !== null) ||
        props.order.submitted_at !== null,
);
// Only collect display metadata. Every quantity and monetary total comes from the backend summary.
const savedPrices = computed(() => {
    const prices = new Map<number, Set<string>>();
    for (const guest of props.order.summary.guests) {
        for (const item of guest.items) {
            if (!prices.has(item.menu_item_id))
                prices.set(item.menu_item_id, new Set());
            prices.get(item.menu_item_id)?.add(item.unit_price);
        }
    }
    return prices;
});
</script>

<template>
    <section aria-labelledby="group-order-heading" class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="group-order-heading" class="text-2xl leading-8 font-medium">
                Group pre-order
            </h2>
            <span
                class="rounded-full border border-primary/30 bg-accent px-3 py-1 text-sm font-medium wrap-anywhere capitalize"
                >{{ statusLabel }}</span
            >
        </div>
        <p
            v-if="locked"
            role="status"
            class="rounded-xl border border-border bg-accent px-5 py-4 text-sm leading-6"
        >
            {{
                order.status === 'submitted'
                    ? 'This group pre-order has been submitted. Selections are locked.'
                    : 'Selections are locked for this order.'
            }}
        </p>
        <p v-else class="text-sm leading-6 text-muted-foreground">
            {{
                order.status === 'draft'
                    ? 'Draft — this group pre-order has not been submitted.'
                    : 'The group pre-order has not been started.'
            }}
            This page shows saved selections only.
        </p>
        <ContentState
            v-if="!order.summary.items.length"
            title="No dishes selected"
            description="Saved selections will appear here when participants add dishes."
        />
        <template v-else>
            <section
                aria-labelledby="consolidated-dishes-heading"
                class="overflow-hidden rounded-2xl border border-border bg-card p-5 sm:p-8"
            >
                <h3
                    id="consolidated-dishes-heading"
                    class="mb-5 text-lg font-medium"
                >
                    All selected dishes
                </h3>
                <p class="mb-5 text-sm leading-6 text-muted-foreground">
                    Saved unit prices can differ for the same dish. Subtotals
                    use each participant’s saved price.
                </p>
                <ul
                    aria-label="Consolidated dishes"
                    class="divide-y divide-border"
                >
                    <li
                        v-for="dish in order.summary.items"
                        :key="dish.menu_item_id"
                        class="grid min-w-0 gap-3 py-5 first:pt-0 sm:grid-cols-[minmax(0,1fr)_auto]"
                    >
                        <div class="min-w-0">
                            <p class="font-medium wrap-anywhere">
                                {{ dish.name }}
                            </p>
                            <p
                                class="mt-2 text-sm leading-6 wrap-anywhere text-muted-foreground"
                            >
                                Saved unit prices:
                                {{
                                    [
                                        ...(savedPrices.get(
                                            dish.menu_item_id,
                                        ) ?? []),
                                    ].join(' · ')
                                }}
                            </p>
                        </div>
                        <dl
                            class="flex flex-wrap items-start gap-x-8 gap-y-2 text-sm sm:text-right"
                        >
                            <div>
                                <dt class="text-muted-foreground">Quantity</dt>
                                <dd class="mt-1 font-medium">
                                    {{ dish.quantity }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Subtotal</dt>
                                <dd
                                    class="mt-1 font-medium text-primary tabular-nums"
                                >
                                    {{ dish.subtotal }}
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>
            </section>
            <section aria-labelledby="guest-orders-heading" class="space-y-4">
                <h3 id="guest-orders-heading" class="text-lg font-medium">
                    Selections by participant
                </h3>
                <details
                    v-for="guest in order.summary.guests"
                    :key="guest.reservation_guest_id"
                    class="min-w-0 rounded-2xl border border-border bg-card p-5 sm:p-6"
                    open
                >
                    <summary
                        class="min-h-11 cursor-pointer rounded-sm font-medium wrap-anywhere focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    >
                        {{ guest.name
                        }}<span
                            class="ml-2 text-sm font-normal text-muted-foreground"
                            >· {{ guest.total_quantity }} selected</span
                        >
                    </summary>
                    <ul
                        :aria-label="`Selected dishes for ${guest.name}`"
                        class="divide-y divide-border"
                    >
                        <li
                            v-for="(item, index) in guest.items"
                            :key="`${item.menu_item_id}-${index}`"
                            class="grid min-w-0 gap-3 py-4 sm:grid-cols-[minmax(0,1fr)_auto]"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium wrap-anywhere">
                                    {{ item.name }}
                                </p>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Quantity: {{ item.quantity }} · Saved unit
                                    price: {{ item.unit_price }}
                                </p>
                            </div>
                            <p class="text-sm tabular-nums">
                                Line subtotal:
                                <span class="font-medium text-primary">{{
                                    item.subtotal
                                }}</span>
                            </p>
                        </li>
                    </ul>
                    <p
                        class="border-t border-border pt-4 text-sm sm:text-right"
                    >
                        Participant subtotal:
                        <span
                            class="ml-2 font-medium text-primary tabular-nums"
                            >{{ guest.subtotal }}</span
                        >
                    </p>
                </details>
            </section>
        </template>
        <div
            class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-primary/30 bg-accent p-6 sm:p-8"
        >
            <div>
                <p class="text-lg font-medium">Grand total</p>
                <p class="mt-1 text-sm">
                    {{ order.summary.total_quantity }} selected across the group
                </p>
            </div>
            <p
                class="text-2xl font-medium wrap-anywhere text-primary tabular-nums"
            >
                {{ order.summary.grand_total }}
            </p>
        </div>
    </section>
</template>
