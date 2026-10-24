<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AuthNotification from '@/components/auth/AuthNotification.vue';
import SiteHeader from '@/components/site/SiteHeader.vue';
const page = usePage();
const authNotice = ref('');
watch(
    () => page.flash.authNotification,
    (value) => {
        authNotice.value = typeof value === 'string' ? value : '';
    },
    { immediate: true },
);
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
        <AuthNotification
            v-if="authNotice"
            :message="authNotice"
            class="fixed top-[84px] right-4 left-4 z-50 sm:right-10 sm:left-auto sm:w-[416px]"
            @dismiss="authNotice = ''"
        />
        <main id="main-content" tabindex="-1">
            <slot />
        </main>
    </div>
</template>
