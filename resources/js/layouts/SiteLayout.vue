<script setup lang="ts">
import SiteHeader from '@/components/site/SiteHeader.vue';
</script>

<template>
    <div
        class="green-tasty min-h-screen bg-background font-[Poppins,sans-serif] text-foreground"
    >
        <a
            href="#main-content"
            class="sr-only z-50 rounded-sm bg-primary px-4 py-3 text-primary-foreground focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:outline-2 focus:outline-offset-4 focus:outline-ring"
        >
            Skip to content
        </a>
        <SiteHeader />
        <main id="main-content" tabindex="-1">
            <slot />
        </main>
    </div>
</template>
