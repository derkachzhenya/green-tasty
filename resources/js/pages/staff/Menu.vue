<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Utensils } from '@lucide/vue';
import GuestDishPreview from '@/components/guest/GuestDishPreview.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { dishImage, dishImageCropped, dishWeight } from '@/lib/guestMenu';
import { dollarPrice } from '@/lib/guestSelection';
import type { GuestCatalogDish } from '@/types/guest-selection';

const props = defineProps<{
    restaurant: { id: number; name: string; address: string };
    categories: { id: number; name: string; dishes: GuestCatalogDish[] }[];
}>();
const selected = ref<number | null>(props.categories[0]?.id ?? null);
const dishes = computed(
    () =>
        props.categories.find((category) => category.id === selected.value)
            ?.dishes ?? [],
);
</script>

<template>
    <Head title="Staff menu"
        ><meta name="robots" content="noindex, nofollow"
    /></Head>
    <section class="bg-accent">
        <SiteContainer class="py-8">
            <p class="text-sm text-primary">{{ restaurant.address }}</p>
            <h1 class="mt-2 text-3xl font-medium">Menu</h1>
            <p class="mt-3 text-sm text-muted-foreground">
                {{ restaurant.name }}
            </p>
        </SiteContainer>
    </section>
    <SiteContainer class="py-8">
        <nav
            v-if="categories.length"
            aria-label="Menu categories"
            class="mb-8 flex flex-wrap gap-2 border-b"
        >
            <button
                v-for="category in categories"
                :key="category.id"
                type="button"
                :aria-pressed="selected === category.id"
                class="min-h-11 border-b-2 px-4 text-sm font-medium"
                :class="
                    selected === category.id
                        ? 'border-primary text-primary'
                        : 'border-transparent'
                "
                @click="selected = category.id"
            >
                {{ category.name }}
            </button>
        </nav>
        <div
            v-if="dishes.length"
            class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"
        >
            <article
                v-for="dish in dishes"
                :key="dish.id"
                :aria-label="dish.name"
                class="relative rounded-3xl bg-white p-6 shadow-sm"
            >
                <span
                    v-if="!dish.available"
                    class="absolute top-3 right-3 rounded-md bg-[#fce9ed] px-3 py-1 text-xs"
                    >Sold Out</span
                >
                <GuestDishPreview
                    :dish="dish"
                    :disabled="true"
                    :locked="true"
                    read-only
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
                    <Utensils
                        v-else
                        class="mx-auto my-10 size-20 text-primary/40"
                        aria-hidden="true"
                    />
                </GuestDishPreview>
                <div class="mt-4 flex items-start justify-between gap-3">
                    <h2 class="text-sm leading-6 font-medium">
                        {{ dish.name }}
                    </h2>
                    <div class="shrink-0 text-right">
                        <span class="font-medium text-primary">{{
                            dollarPrice(dish.price)
                        }}</span>
                        <p
                            v-if="dishWeight(dish.name)"
                            class="mt-1 text-xs text-muted-foreground"
                        >
                            {{ dishWeight(dish.name) }}
                        </p>
                    </div>
                </div>
            </article>
        </div>
        <p
            v-else
            class="rounded-3xl bg-white p-8 text-center text-muted-foreground"
        >
            No dishes available in this category.
        </p>
    </SiteContainer>
</template>
