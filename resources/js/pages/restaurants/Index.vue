<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import RestaurantLanding from '@/components/restaurants/RestaurantLanding.vue';
import type { RestaurantListing } from '@/types/restaurant';
defineProps<{ restaurants?: RestaurantListing[] }>();
</script>
<template>
    <Head title="Our locations"
        ><meta
            name="description"
            content="Explore Green Tasty restaurant locations."
    /></Head>
    <RestaurantLanding :restaurants="restaurants" />
</template>
