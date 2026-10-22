<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
</script>

<template>
    <Head title="Restaurant not found"
        ><meta name="robots" content="noindex"
    /></Head>
    <SiteContainer>
        <div class="space-y-6 py-16 text-center">
            <p class="text-sm font-medium text-primary">404</p>
            <h1 class="text-3xl leading-10 font-medium">
                Restaurant not found
            </h1>
            <p class="mx-auto max-w-md text-sm leading-6 text-muted-foreground">
                This location isn’t available. Explore our other locations to
                find a restaurant.
            </p>
            <Button as-child class="min-h-11"
                ><Link :href="`${home().url}#locations`"
                    >Back to locations</Link
                ></Button
            >
        </div>
    </SiteContainer>
</template>
