<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RestaurantCard from '@/components/restaurants/RestaurantCard.vue';
import RestaurantGridSkeleton from '@/components/restaurants/RestaurantGridSkeleton.vue';
import ContentState from '@/components/site/ContentState.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import type { RestaurantListing } from '@/types/restaurant';

defineProps<{ restaurants?: RestaurantListing[] }>();
</script>

<template>
    <Head title="Our locations">
        <meta
            name="description"
            content="Explore Green Tasty restaurant locations."
        />
    </Head>

    <section aria-labelledby="welcome-heading" class="bg-accent">
        <SiteContainer>
            <div
                class="flex min-h-72 flex-col items-start justify-center gap-6 py-12 sm:min-h-[396px] sm:py-16"
            >
                <div class="space-y-4">
                    <h1
                        id="welcome-heading"
                        class="max-w-2xl text-3xl leading-10 font-medium sm:text-5xl sm:leading-[52px]"
                    >
                        Green by nature,<br class="hidden sm:block" />
                        Georgian by heart.
                    </h1>
                    <p class="text-sm leading-6 text-primary uppercase">
                        Seasonal bowls • Vegan friendly • Tbilisi
                    </p>
                </div>
                <Button as-child class="min-h-11 px-6">
                    <a href="#locations">Explore locations</a>
                </Button>
            </div>
        </SiteContainer>
    </section>

    <SiteContainer>
        <section
            id="locations"
            aria-labelledby="locations-heading"
            class="scroll-mt-6 py-10 sm:py-[60px]"
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
                    v-if="restaurants?.length"
                    class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
                    aria-label="Restaurant locations"
                >
                    <li
                        v-for="restaurant in restaurants"
                        :key="restaurant.id"
                        class="min-w-0"
                    >
                        <RestaurantCard :restaurant="restaurant" />
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
