<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AuthModal from '@/components/auth/AuthModal.vue';
import RestaurantLanding from '@/components/restaurants/RestaurantLanding.vue';
import type { AuthStep, CodeContext } from '@/types/auth-flow';
import type { RestaurantListing } from '@/types/restaurant';
defineProps<{
    initialStep: AuthStep;
    challenge: CodeContext | null;
    restaurants: RestaurantListing[];
    verificationRequired?: boolean;
}>();
</script>
<template>
    <Head title="Your account"><meta name="robots" content="noindex" /></Head>
    <RestaurantLanding :restaurants="restaurants" />
    <AuthModal
        :initial-step="initialStep"
        :challenge="challenge"
        :verification-required="verificationRequired"
    />
</template>
