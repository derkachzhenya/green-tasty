<script setup lang="ts">
import { Utensils } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dishImage, dishWeight } from '@/lib/guestMenu';
import type { GuestCatalogDish } from '@/types/guest-selection';
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
        class="relative flex min-w-0 flex-col rounded-3xl bg-card p-6 shadow-[0_2px_12px_#0000000a]"
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
            <img
                v-if="dishImage(dish.name)"
                :src="dishImage(dish.name)!"
                :alt="dish.name"
                width="224"
                height="224"
                class="mx-auto aspect-square w-full max-w-44 object-contain"
            />
            <div
                v-else
                class="mx-auto flex aspect-square w-full max-w-44 items-center justify-center rounded-full bg-accent"
            >
                <Utensils
                    class="size-16 text-primary/40"
                    aria-hidden="true"
                /><span class="sr-only">Dish photograph unavailable</span>
            </div>
            <div class="mt-4 flex items-start justify-between gap-3">
                <h2 class="min-w-0 text-sm leading-6 font-medium wrap-anywhere">
                    {{ dish.name }}
                </h2>
                <div class="shrink-0 text-right">
                    <span
                        class="text-sm font-medium text-primary tabular-nums"
                        >{{ dish.price }}</span
                    >
                    <p
                        v-if="dishWeight(dish.name)"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ dishWeight(dish.name) }}
                    </p>
                </div>
            </div>
            <p
                v-if="dish.description"
                class="mt-2 text-xs leading-5 text-muted-foreground"
            >
                {{ dish.description }}
            </p>
            <div class="mt-auto pt-3">
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
                    v-else
                    class="min-h-11 w-full"
                    :disabled="disabled || !dish.available"
                    :aria-label="`Add ${dish.name}`"
                    @click="$emit('change', 1)"
                    >Pre-Order</Button
                >
            </div>
        </div>
    </article>
</template>
