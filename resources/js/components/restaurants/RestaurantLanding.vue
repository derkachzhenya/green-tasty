<script setup lang="ts">
import { computed } from 'vue';
import HomepageHero from '@/components/restaurants/HomepageHero.vue';
import HomepageLocationCard from '@/components/restaurants/HomepageLocationCard.vue';
import PopularDishCard from '@/components/restaurants/PopularDishCard.vue';
import RestaurantGridSkeleton from '@/components/restaurants/RestaurantGridSkeleton.vue';
import ContentState from '@/components/site/ContentState.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import {
    featuredPrice,
    homepageDishes,
    homepageLocations,
} from '@/lib/homepage';
import type { RestaurantListing } from '@/types/restaurant';

const props = defineProps<{
    restaurants?: RestaurantListing[];
    featuredPrices?: Record<string, string> | [];
    pricesResolved?: boolean;
}>();
const locations = computed(() => homepageLocations(props.restaurants ?? []));

// Only the price region is deferred; artwork/copy and geometry remain stable.
const prices = computed(() =>
    Array.isArray(props.featuredPrices) ? {} : props.featuredPrices,
);
</script>

<template>
    <HomepageHero />

    <SiteContainer>
        <section
            aria-labelledby="popular-dishes-heading"
            class="relative pt-10 sm:pt-[60px]"
        >
            <h2
                id="popular-dishes-heading"
                class="mb-8 text-2xl leading-10 font-medium sm:text-[32px]"
            >
                Most Popular Dishes
            </h2>
            <div class="xl:min-h-[308px]">
                <DeferredSection
                    data="featuredPrices"
                    error-title="We couldn’t load current menu prices"
                    :resolved="pricesResolved"
                >
                    <template #fallback>
                        <ul
                            class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4"
                            aria-label="Popular dishes"
                            aria-busy="true"
                        >
                            <li
                                v-for="dish in homepageDishes"
                                :key="dish.name"
                                class="min-w-0"
                            >
                                <PopularDishCard v-bind="dish" loading />
                            </li>
                        </ul>
                    </template>
                    <ul
                        class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4"
                        aria-label="Popular dishes"
                    >
                        <li
                            v-for="dish in homepageDishes"
                            :key="dish.name"
                            class="min-w-0"
                        >
                            <PopularDishCard
                                v-bind="dish"
                                :price="featuredPrice(dish.name, prices)"
                            />
                        </li>
                    </ul>
                </DeferredSection>
            </div>
        </section>
        <section
            id="locations"
            aria-labelledby="locations-heading"
            class="scroll-mt-6 py-10 sm:pt-[60px] sm:pb-[53px]"
        >
            <h2
                id="locations-heading"
                class="mb-8 text-2xl leading-10 font-medium sm:text-[32px]"
            >
                Locations
            </h2>
            <DeferredSection
                data="restaurants"
                error-title="We couldn’t load the locations"
            >
                <template #fallback>
                    <RestaurantGridSkeleton />
                </template>
                <ul
                    v-if="locations.length"
                    class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                    aria-label="Restaurant locations"
                >
                    <li
                        v-for="location in locations"
                        :key="location.restaurant.id"
                        class="min-w-0"
                    >
                        <HomepageLocationCard :location="location" />
                    </li>
                </ul>
                <ContentState
                    v-else
                    title="No locations available"
                    description="We don’t have any locations to show right now. Please check back soon."
                />
            </DeferredSection>
        </section>
    </SiteContainer>
</template>
