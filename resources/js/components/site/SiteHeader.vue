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
        <SiteContainer class="site-header-container">
            <div
                class="flex min-h-[67px] flex-wrap items-center justify-between gap-x-2 gap-y-2 py-3 md:grid md:grid-cols-3 md:gap-x-6 md:py-0"
            >
                <Link
                    :href="home()"
                    aria-label="Green Tasty home"
                    class="w-fit rounded-sm text-lg font-medium tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                >
                    <img
                        src="/images/green-tasty/logo.png"
                        alt="Green Tasty — Healthy Eating Restaurant"
                        width="112"
                        height="38"
                        class="h-[38px] w-[112px]"
                    />
                </Link>

                <nav
                    aria-label="Main navigation"
                    class="order-last flex w-full justify-center whitespace-nowrap md:order-none md:w-max md:justify-self-center"
                >
                    <Link
                        :href="home()"
                        :aria-current="isHome ? 'page' : undefined"
                        :class="
                            isHome
                                ? 'border-primary text-primary'
                                : 'border-transparent hover:text-primary'
                        "
                        class="inline-flex min-h-11 items-center border-b-2 px-6 text-base font-medium focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:min-h-[67px] md:text-xl md:leading-8"
                    >
                        Main page
                    </Link>
                    <Link
                        :href="`${home().url}#locations`"
                        class="inline-flex min-h-11 items-center rounded-sm px-6 text-base font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:text-xl md:leading-8"
                    >
                        Book a Table
                    </Link>
                </nav>

                <Link
                    :href="page.props.auth.user ? dashboard() : login()"
                    :aria-current="
                        page.component === 'Dashboard' ? 'page' : undefined
                    "
                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-6 text-sm font-semibold text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:justify-self-end lg:text-base"
                >
                    {{ page.props.auth.user ? 'My reservations' : 'Sign In' }}
                </Link>
            </div>
        </SiteContainer>
    </header>
</template>

<style scoped>
@media (min-width: 1024px) {
    .site-header-container {
        padding-inline: 40px;
    }
}
</style>
