<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CircleUserRound } from '@lucide/vue';
import { computed } from 'vue';
import { dashboard, home, login } from '@/routes';
import { create } from '@/routes/booking';
import { edit } from '@/routes/profile';
import SiteContainer from './SiteContainer.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const isHome = computed(() => page.component === 'restaurants/Index');
const isBooking = computed(() => page.component.startsWith('bookings/'));
</script>

<template>
    <header class="border-b border-border bg-card">
        <SiteContainer class="site-header-container">
            <div
                class="flex min-h-[67px] flex-wrap items-center justify-between gap-x-2 gap-y-2 py-3 lg:grid lg:grid-cols-[112px_minmax(0,1fr)_112px] lg:gap-x-6 lg:py-0"
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
                    class="order-last flex w-full justify-center whitespace-nowrap lg:order-none lg:w-max lg:justify-self-center"
                >
                    <Link
                        :href="home()"
                        :aria-current="isHome ? 'page' : undefined"
                        :class="
                            isHome
                                ? 'border-primary text-primary'
                                : 'border-transparent hover:text-primary'
                        "
                        class="inline-flex min-h-11 items-center border-b-2 px-2 text-xs font-medium focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring sm:px-4 sm:text-base lg:min-h-[67px] lg:text-xl lg:leading-8"
                    >
                        Main page
                    </Link>
                    <Link
                        :href="create()"
                        :aria-current="isBooking ? 'page' : undefined"
                        :class="
                            isBooking
                                ? 'border-primary text-primary'
                                : 'border-transparent'
                        "
                        class="inline-flex min-h-11 items-center border-b-2 px-2 text-xs font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring sm:px-4 sm:text-base lg:min-h-[67px] lg:text-xl lg:leading-8"
                    >
                        Book a Table
                    </Link>
                    <Link
                        v-if="user"
                        :href="dashboard()"
                        :aria-current="
                            page.component === 'Dashboard' ? 'page' : undefined
                        "
                        :class="
                            page.component === 'Dashboard'
                                ? 'border-primary text-primary'
                                : 'border-transparent'
                        "
                        class="inline-flex min-h-11 items-center border-b-2 px-2 text-xs font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 sm:px-4 sm:text-base lg:min-h-[67px] lg:text-xl"
                        >Reservations</Link
                    >
                </nav>

                <Link
                    :href="user ? edit() : login()"
                    :aria-label="user ? 'Account settings' : undefined"
                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-6 text-sm font-semibold text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:justify-self-end lg:text-base"
                >
                    <CircleUserRound
                        v-if="user"
                        aria-hidden="true"
                        class="size-6"
                    /><span v-else>Sign In</span>
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
