<script setup lang="ts">
import { Bell } from '@lucide/vue';
import { onClickOutside } from '@vueuse/core';
import { ref } from 'vue';
import type { RestaurantNotification } from '@/types/restaurant-review';

defineProps<{ notifications: RestaurantNotification[] }>();
const menu = ref<HTMLDetailsElement | null>(null);
onClickOutside(menu, () => {
    if (menu.value) menu.value.open = false;
});
function notificationLabel(kind: string): string {
    const labels: Record<string, string> = {
        customer_preorder_submitted: 'Customer pre-order submitted',
        customer_preorder_updated: 'Customer updated the pre-order',
        guest_preorder_submitted: 'Guest selections updated',
        guest_removed: 'Guest removed',
        group_submitted: 'Group pre-order submitted',
    };
    return labels[kind] ?? 'Customer pre-order changed';
}
</script>

<template>
    <details ref="menu" class="relative text-sm">
        <summary
            class="relative flex size-11 cursor-pointer list-none items-center justify-center rounded-lg text-foreground hover:bg-accent focus-visible:outline-2 focus-visible:outline-primary [&::-webkit-details-marker]:hidden"
            aria-label="Restaurant notifications"
        >
            <Bell class="size-6" aria-hidden="true" />
            <span class="sr-only">Notifications</span>
            <span
                v-if="notifications.length"
                class="absolute top-0.5 right-0.5 grid min-w-4 place-items-center rounded-full bg-primary px-1 text-[10px] leading-4 text-white"
                >{{ notifications.length }}</span
            >
        </summary>
        <div
            class="absolute right-0 z-30 mt-2 max-h-80 w-[min(340px,calc(100vw-32px))] overflow-y-auto rounded-xl border bg-white p-4 shadow-lg"
        >
            <h2 class="mb-2 font-medium">Restaurant notifications</h2>
            <p v-if="!notifications.length">No pre-order notifications</p>
            <ul>
                <li
                    v-for="(event, index) in notifications"
                    :key="index"
                    class="border-b py-3 last:border-0"
                >
                    Reservation #{{ event.reservation_id }}<br />{{
                        notificationLabel(event.kind)
                    }}
                </li>
            </ul>
        </div>
    </details>
</template>
