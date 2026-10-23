<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { DraftDish } from '@/types/guest-selection';
import QuantityControl from './QuantityControl.vue';

defineProps<{
    items: DraftDish[];
    total: string;
    count: number;
    dirty: boolean;
    locked: boolean;
    busy: boolean;
    saving: boolean;
    unavailable: boolean;
    error: string;
    validation: string[];
    success: string;
}>();
defineEmits<{ change: [id: number, quantity: number]; save: [] }>();
</script>

<template>
    <aside
        id="your-order"
        aria-labelledby="your-order-heading"
        tabindex="-1"
        class="scroll-mt-6 rounded-2xl border border-border bg-card p-6 shadow-sm lg:sticky lg:top-6"
    >
        <div class="flex items-start justify-between gap-3">
            <h2 id="your-order-heading" class="text-xl font-medium">
                Your pre-order
            </h2>
            <span class="rounded-full bg-accent px-3 py-1 text-sm tabular-nums"
                >{{ count }} {{ count === 1 ? 'item' : 'items' }}</span
            >
        </div>
        <p class="mt-2 text-sm leading-6 text-muted-foreground">
            Only your dishes appear here.
        </p>
        <p
            v-if="locked"
            role="status"
            class="mt-4 rounded-lg bg-accent p-3 text-sm leading-6"
        >
            The group pre-order is locked. You can review your saved selections.
        </p>
        <p v-else class="mt-4 text-sm leading-6 text-muted-foreground">
            Saving updates your draft. The reservation owner submits the group
            order.
        </p>

        <p
            v-if="!items.length"
            class="my-8 text-sm leading-6 text-muted-foreground"
        >
            No dishes selected. Browse the menu to build your pre-order.
        </p>
        <ul v-else class="mt-6 divide-y divide-border">
            <li
                v-for="item in items"
                :key="item.menu_item_id"
                class="py-5 first:pt-0"
            >
                <div class="flex items-start justify-between gap-4">
                    <h3 class="text-sm leading-6 font-medium wrap-anywhere">
                        {{ item.name }}
                    </h3>
                    <span
                        class="shrink-0 text-sm font-medium text-primary tabular-nums"
                        >{{ item.subtotal }}</span
                    >
                </div>
                <p class="mt-1 text-xs leading-5 text-muted-foreground">
                    Unit price: {{ item.unit_price }} · Quantity:
                    {{ item.quantity }}
                </p>
                <p
                    v-if="!item.available"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    No longer available.
                    <template v-if="!locked"
                        >Remove this dish before saving.</template
                    >
                </p>
                <div
                    v-if="!locked"
                    class="mt-3 flex flex-wrap items-center justify-between gap-2"
                >
                    <QuantityControl
                        v-if="item.available"
                        :name="item.name"
                        :quantity="item.quantity"
                        :disabled="busy"
                        @change="$emit('change', item.menu_item_id, $event)"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        class="min-h-11 px-2 text-sm"
                        :disabled="busy"
                        :aria-label="`Remove ${item.name}`"
                        @click="$emit('change', item.menu_item_id, 0)"
                        >Remove</Button
                    >
                </div>
            </li>
        </ul>
        <div
            class="mt-6 flex justify-between gap-4 border-t border-border pt-5 text-lg font-medium"
        >
            <span>Your total</span
            ><output
                aria-label="Your pre-order total"
                class="text-primary tabular-nums"
                >{{ total }}</output
            >
        </div>
        <p class="mt-3 text-xs leading-5 text-muted-foreground">
            Saved dishes keep their original unit prices. New dishes use the
            menu price when saved.
        </p>
        <p
            v-if="dirty && !locked"
            role="status"
            class="mt-4 text-sm font-medium"
        >
            You have unsaved changes.
        </p>
        <p v-if="unavailable && !locked" class="mt-4 text-sm">
            Remove unavailable dishes to save your changes.
        </p>
        <p
            v-if="error"
            role="alert"
            class="mt-4 text-sm leading-6 text-destructive"
        >
            {{ error }}
        </p>
        <ul
            v-if="validation.length"
            role="alert"
            class="mt-4 list-inside list-disc space-y-2 text-sm text-destructive"
        >
            <li v-for="message in validation" :key="message">{{ message }}</li>
        </ul>
        <p
            v-if="success"
            role="status"
            class="mt-4 text-sm leading-6 text-primary"
        >
            {{ success }}
        </p>
        <form
            v-if="!locked"
            class="mt-5"
            :aria-busy="saving"
            @submit.prevent="$emit('save')"
        >
            <Button
                type="submit"
                class="min-h-11 w-full"
                :disabled="busy || !dirty || unavailable"
                >{{ saving ? 'Saving selections…' : 'Save selections' }}</Button
            >
        </form>
    </aside>
</template>
