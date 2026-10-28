<script setup lang="ts">
import { Utensils } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dishImage, dishImageCropped, dishWeight } from '@/lib/guestMenu';
import { dollarPrice } from '@/lib/guestSelection';
import type { GuestCatalogDish } from '@/types/guest-selection';
import GuestDishPreview from './GuestDishPreview.vue';
import QuantityControl from './QuantityControl.vue';
defineProps<{
    dish: GuestCatalogDish;
    quantity: number;
    disabled: boolean;
    locked: boolean;
}>();
defineEmits<{ change: [quantity: number] }>();
</script>
<template>
    <article
        class="group relative flex min-w-0 flex-col rounded-3xl bg-card p-6 shadow-[0_2px_12px_#0000000a]"
        :aria-label="dish.name"
    >
        <span
            v-if="!dish.available"
            class="absolute top-4 right-4 z-10 rounded-md bg-[#fce9ed] px-3 py-1 text-xs"
            >Sold Out</span
        >
        <div
            class="flex flex-1 flex-col"
            :class="{ 'opacity-40': !dish.available }"
        >
            <GuestDishPreview
                :dish="dish"
                :disabled="disabled"
                :locked="locked"
                @add="$emit('change', quantity + 1)"
            >
                <img
                    v-if="dishImage(dish.name)"
                    :src="dishImage(dish.name)!"
                    :alt="dish.name"
                    width="224"
                    height="224"
                    class="mx-auto aspect-square w-full max-w-40"
                    :class="
                        dishImageCropped(dish.name)
                            ? 'rounded-full object-cover'
                            : 'object-contain'
                    "
                />
                <div
                    v-else
                    class="mx-auto flex aspect-square w-full max-w-40 items-center justify-center rounded-full bg-accent"
                >
                    <Utensils
                        class="size-16 text-primary/40"
                        aria-hidden="true"
                    /><span class="sr-only">Dish photograph unavailable</span>
                </div>
            </GuestDishPreview>
            <div class="mt-2 flex items-start justify-between gap-3">
                <h2 class="min-w-0 text-sm leading-6 font-medium wrap-anywhere">
                    {{ dish.name }}
                </h2>
                <div class="shrink-0 text-right">
                    <span
                        class="text-sm font-medium text-primary tabular-nums"
                        >{{ dollarPrice(dish.price) }}</span
                    >
                    <p
                        v-if="dishWeight(dish.name)"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ dishWeight(dish.name) }}
                    </p>
                </div>
            </div>
            <p v-if="dish.description" class="sr-only">
                {{ dish.description }}
            </p>
            <div
                class="mt-auto pt-2"
                :class="{
                    'lg:opacity-0 lg:group-focus-within:opacity-100 lg:group-hover:opacity-100':
                        dish.available && !quantity && !locked,
                }"
            >
                <p v-if="locked" class="text-xs text-muted-foreground">
                    Selections are locked
                </p>
                <QuantityControl
                    v-else-if="quantity"
                    :name="dish.name"
                    :quantity="quantity"
                    :disabled="disabled || !dish.available"
                    @change="$emit('change', $event)"
                />
                <Button
                    v-else-if="dish.available"
                    class="min-h-10 w-full lg:opacity-0 lg:group-focus-within:opacity-100 lg:group-hover:opacity-100"
                    :disabled="disabled || !dish.available"
                    :aria-label="`Add ${dish.name}`"
                    @click="$emit('change', 1)"
                    >Pre-Order</Button
                >
                <div v-else class="h-10" aria-hidden="true"></div>
            </div>
        </div>
    </article>
</template>
