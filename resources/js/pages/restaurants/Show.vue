<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import FigmaReviews from '@/components/restaurants/FigmaReviews.vue';
import MenuSkeleton from '@/components/restaurants/MenuSkeleton.vue';
import RestaurantMenu from '@/components/restaurants/RestaurantMenu.vue';
import RestaurantProfileHero from '@/components/restaurants/RestaurantProfileHero.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { homepageLocations } from '@/lib/homepage';
import { home } from '@/routes';
import { create } from '@/routes/restaurants/bookings';
import type { MenuCategory, RestaurantDetails } from '@/types/restaurant';

const props = defineProps<{
    restaurant: RestaurantDetails;
    menu?: MenuCategory[];
}>();
const referenceLocation = computed(
    () => homepageLocations([props.restaurant])[0],
);
const phoneNumber = computed(() => {
    const number = props.restaurant.phone?.replace(/[^\d+]/g, '') ?? '';
    return /^\+?\d{3,15}$/.test(number) ? number : null;
});
</script>

<template>
    <Head :title="restaurant.name"
        ><meta
            name="description"
            :content="`Explore the menu at ${restaurant.name}, ${restaurant.address}.`"
    /></Head>
    <RestaurantProfileHero :restaurant="restaurant" />
    <SiteContainer>
        <FigmaReviews
            v-if="referenceLocation.referenceAddress === '48 Rustaveli Avenue'"
        />
        <nav aria-label="Breadcrumb" class="py-5">
            <Link
                :href="`${home().url}#locations`"
                class="inline-flex min-h-11 items-center gap-2 rounded-sm text-sm font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                ><ArrowLeft class="size-4" aria-hidden="true" /> Back to
                locations</Link
            >
        </nav>
    </SiteContainer>

    <SiteContainer>
        <section
            id="menu"
            aria-labelledby="menu-heading"
            tabindex="-1"
            class="scroll-mt-6 py-10 sm:py-[60px]"
        >
            <h2
                id="menu-heading"
                class="mb-8 text-2xl leading-10 font-medium sm:text-[32px]"
            >
                Menu
            </h2>
            <DeferredSection
                data="menu"
                error-title="We couldn’t load the menu"
            >
                <template #fallback><MenuSkeleton /></template>
                <RestaurantMenu :categories="menu ?? []" />
            </DeferredSection>
        </section>
        <section
            id="reservation-info"
            aria-labelledby="reservation-heading"
            tabindex="-1"
            class="mb-12 scroll-mt-6 space-y-4 rounded-2xl border border-border bg-card p-6 sm:p-8"
        >
            <h2 id="reservation-heading" class="text-2xl leading-8 font-medium">
                Reservations
            </h2>
            <p class="text-sm leading-6 text-muted-foreground">
                Choose a date, time and available table to book online. Sign in
                is required.
            </p>
            <Button as-child class="min-h-11"
                ><Link :href="create(restaurant.slug)"
                    >Find a table</Link
                ></Button
            >
            <template v-if="phoneNumber">
                <p class="text-sm leading-6">
                    Contact the restaurant to ask about a table.
                </p>
                <Button as-child class="min-h-11"
                    ><a :href="`tel:${phoneNumber}`"
                        >Call {{ restaurant.phone }}</a
                    ></Button
                >
            </template>
            <p v-else class="text-sm leading-6 wrap-anywhere">
                Please visit us at {{ restaurant.address }} to ask about a
                table.
            </p>
        </section>
    </SiteContainer>
</template>
