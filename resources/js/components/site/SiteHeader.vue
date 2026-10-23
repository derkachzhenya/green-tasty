<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard, home, login } from '@/routes';
import SiteContainer from './SiteContainer.vue';

const page = usePage();
const isHome = computed(() => page.component === 'restaurants/Index');
</script>

<template>
    <header class="border-b border-border bg-card">
        <SiteContainer>
            <div
                class="flex min-h-[68px] flex-wrap items-center justify-between gap-x-6 gap-y-2 py-3 sm:grid sm:grid-cols-3 sm:py-0"
            >
                <Link
                    :href="home()"
                    aria-label="Green Tasty home"
                    class="w-fit rounded-sm text-lg font-medium tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >
                    Green Tasty
                </Link>

                <nav
                    aria-label="Main navigation"
                    class="order-last flex w-full justify-center gap-6 sm:order-none sm:w-auto"
                >
                    <Link
                        :href="home()"
                        :aria-current="isHome ? 'page' : undefined"
                        :class="
                            isHome
                                ? 'border-primary text-primary'
                                : 'border-transparent hover:text-primary'
                        "
                        class="inline-flex min-h-11 items-center border-b-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    >
                        Main page
                    </Link>
                    <Link
                        :href="`${home().url}#locations`"
                        class="inline-flex min-h-11 items-center rounded-sm text-sm font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    >
                        Locations
                    </Link>
                </nav>

                <Link
                    :href="page.props.auth.user ? dashboard() : login()"
                    :aria-current="
                        page.component === 'Dashboard' ? 'page' : undefined
                    "
                    class="inline-flex min-h-11 items-center justify-center rounded-sm border border-primary px-5 text-sm font-medium text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring sm:justify-self-end"
                >
                    {{ page.props.auth.user ? 'My reservations' : 'Sign in' }}
                </Link>
            </div>
        </SiteContainer>
    </header>
</template>
