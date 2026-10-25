<script setup lang="ts">
import { X, Utensils } from '@lucide/vue';
import {
    DialogRoot,
    DialogTrigger,
    DialogPortal,
    DialogOverlay,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogClose,
} from 'reka-ui';
import { Button } from '@/components/ui/button';
import { dishImage, dishWeight, dishImageCropped } from '@/lib/guestMenu';
import type { GuestCatalogDish } from '@/types/guest-selection';
defineProps<{ dish: GuestCatalogDish; disabled: boolean; locked: boolean }>();
defineEmits<{ add: [] }>();
</script>
<template>
    <DialogRoot>
        <DialogTrigger as-child
            ><button
                type="button"
                :aria-label="`View ${dish.name}`"
                class="block w-full rounded-xl focus-visible:outline-2 focus-visible:outline-offset-4"
            >
                <slot /></button
        ></DialogTrigger>
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-black/40" />
            <DialogContent
                class="green-tasty fixed top-1/2 left-1/2 z-50 grid max-h-[calc(100dvh-32px)] w-[calc(100%_-_32px)] max-w-[838px] -translate-x-1/2 -translate-y-1/2 gap-6 overflow-y-auto rounded-3xl bg-card p-6 pt-12 font-[Poppins,sans-serif] text-foreground shadow-xl focus:outline-none md:grid-cols-[294px_minmax(0,1fr)] md:gap-8 md:p-10"
            >
                <img
                    v-if="dishImage(dish.name)"
                    :src="
                        dish.name === 'Fresh Strawberry Mint Salad'
                            ? '/images/green-tasty/dishes/strawberry-detail.png'
                            : dishImage(dish.name)!
                    "
                    :alt="dish.name"
                    class="mx-auto aspect-square w-full max-w-[294px] self-center"
                    :class="
                        dishImageCropped(dish.name)
                            ? 'rounded-full object-cover'
                            : 'object-contain'
                    "
                    width="294"
                    height="294"
                />
                <Utensils
                    v-else
                    class="mx-auto size-32 self-center text-primary/40"
                    aria-hidden="true"
                />
                <div class="flex min-w-0 flex-col gap-6">
                    <DialogTitle
                        class="pr-4 text-[26px] leading-10 font-medium wrap-anywhere"
                        >{{ dish.name }}</DialogTitle
                    >
                    <DialogDescription class="text-sm leading-6">{{
                        dish.description ||
                        'Ask the restaurant for ingredients and nutritional information.'
                    }}</DialogDescription>
                    <div
                        class="mt-auto flex flex-wrap items-center justify-between gap-4"
                    >
                        <p>
                            <span
                                class="text-[26px] font-medium text-primary"
                                >{{ dish.price }}</span
                            ><span
                                v-if="dishWeight(dish.name)"
                                class="text-sm text-muted-foreground"
                            >
                                /{{ dishWeight(dish.name) }}</span
                            >
                        </p>
                        <Button
                            :disabled="disabled || locked || !dish.available"
                            class="min-h-11"
                            @click="$emit('add')"
                            >{{
                                !dish.available
                                    ? 'Sold Out'
                                    : locked
                                      ? 'Selections locked'
                                      : 'Pre-Order'
                            }}</Button
                        >
                    </div>
                </div>
                <DialogClose
                    aria-label="Close dish preview"
                    class="absolute top-1 right-1 grid size-11 place-items-center rounded-lg focus-visible:outline-2"
                    ><X class="size-5" aria-hidden="true"
                /></DialogClose>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
