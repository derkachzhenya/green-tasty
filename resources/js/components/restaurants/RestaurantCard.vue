<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, MapPin, Utensils } from '@lucide/vue';
import { show } from '@/routes/restaurants';
import type { RestaurantListing } from '@/types/restaurant';

defineProps<{ restaurant: RestaurantListing }>();
</script>

<template>
    <article :aria-labelledby="`restaurant-${restaurant.id}`" class="h-full">
        <Link
            :href="show(restaurant.slug)"
            :aria-labelledby="`restaurant-${restaurant.id}`"
            class="group gt-card block h-full overflow-hidden border border-transparent bg-card hover:border-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
        >
            <div
                class="flex aspect-[432/244] items-center justify-center bg-accent text-primary"
                aria-hidden="true"
            >
                <Utensils class="size-12" :stroke-width="1" />
            </div>
            <div class="space-y-4 p-6">
                <h3
                    :id="`restaurant-${restaurant.id}`"
                    class="text-xl leading-7 font-medium wrap-anywhere"
                >
                    {{ restaurant.name }}
                </h3>
                <p
                    class="flex items-start gap-2 text-sm leading-6 wrap-anywhere"
                >
                    <MapPin
                        class="mt-0.5 size-5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span>{{ restaurant.address }}</span>
                </p>
                <p
                    class="flex items-center gap-2 text-sm leading-6 text-muted-foreground"
                >
                    <Utensils
                        class="size-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    {{ restaurant.table_count }}
                    {{ restaurant.table_count === 1 ? 'table' : 'tables' }}
                </p>
                <p
                    class="flex min-h-11 items-center gap-2 text-sm font-medium text-primary group-hover:underline"
                >
                    View restaurant and menu
                    <ArrowRight class="size-4 shrink-0" aria-hidden="true" />
                </p>
            </div>
        </Link>
    </article>
</template>
