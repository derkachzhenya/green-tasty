<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, MapPin, Utensils } from '@lucide/vue';
import { computed } from 'vue';
import MenuSkeleton from '@/components/restaurants/MenuSkeleton.vue';
import RestaurantMenu from '@/components/restaurants/RestaurantMenu.vue';
import DeferredSection from '@/components/site/DeferredSection.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import type { MenuCategory, RestaurantDetails } from '@/types/restaurant';

const props = defineProps<{
    restaurant: RestaurantDetails;
    menu?: MenuCategory[];
}>();
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
    <SiteContainer>
        <nav aria-label="Breadcrumb" class="py-5">
            <Link
                :href="`${home().url}#locations`"
                class="inline-flex min-h-11 items-center gap-2 rounded-sm text-sm font-medium text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                ><ArrowLeft class="size-4" aria-hidden="true" /> Back to
                locations</Link
            >
        </nav>
    </SiteContainer>

    <section
        aria-labelledby="restaurant-heading"
        class="bg-accent py-8 sm:py-12"
    >
        <SiteContainer>
            <div class="grid items-center gap-8 lg:grid-cols-2">
                <div
                    class="hidden min-h-64 items-center justify-center text-primary lg:flex"
                    aria-hidden="true"
                >
                    <Utensils class="size-20" :stroke-width="1" />
                </div>
                <div
                    class="min-w-0 space-y-6 rounded-2xl border border-border bg-card p-6 sm:p-8"
                >
                    <h1
                        id="restaurant-heading"
                        class="text-3xl leading-10 font-medium wrap-anywhere"
                    >
                        {{ restaurant.name }}
                    </h1>
                    <p class="flex items-start gap-2 leading-6 wrap-anywhere">
                        <MapPin
                            class="mt-0.5 size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        /><span>{{ restaurant.address }}</span>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ restaurant.table_count }}
                        {{ restaurant.table_count === 1 ? 'table' : 'tables' }}
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button as-child class="min-h-11"
                            ><a href="#reservation-info"
                                >Reservation information</a
                            ></Button
                        >
                        <Button
                            as-child
                            variant="outline"
                            class="min-h-11 border-primary bg-card text-primary hover:bg-accent dark:bg-card dark:hover:bg-accent"
                            ><a href="#menu">View menu</a></Button
                        >
                    </div>
                </div>
            </div>
        </SiteContainer>
    </section>

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
                Online booking is not available yet.
            </p>
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
