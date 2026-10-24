<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RestaurantLanding from '@/components/restaurants/RestaurantLanding.vue';
import type { RestaurantListing } from '@/types/restaurant';
defineProps<{
    restaurants?: RestaurantListing[];
    featuredPrices?: Record<string, string> | [];
}>();
</script>
<template>
    <Head title="Main page"
        ><meta
            name="description"
            content="Green by nature, Georgian by heart. Explore Green Tasty menus and book a table in Tbilisi."
    /></Head>
    <RestaurantLanding
        :restaurants="restaurants"
        :featured-prices="featuredPrices"
    />
</template>
