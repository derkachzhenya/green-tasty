<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { dashboard, home, login } from '@/routes';
import SiteContainer from './SiteContainer.vue';

const page = usePage();
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
                        aria-current="page"
                        class="inline-flex min-h-11 items-center border-b-2 border-primary text-sm font-medium text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    >
                        Main page
                    </Link>
                    <a
                        href="#locations"
                        class="inline-flex min-h-11 items-center rounded-sm text-sm font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                    >
                        Locations
                    </a>
                </nav>

                <Link
                    :href="page.props.auth.user ? dashboard() : login()"
                    class="inline-flex min-h-11 items-center justify-center rounded-sm border border-primary px-5 text-sm font-medium text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring sm:justify-self-end"
                >
                    {{ page.props.auth.user ? 'My account' : 'Sign in' }}
                </Link>
            </div>
        </SiteContainer>
    </header>
</template>
