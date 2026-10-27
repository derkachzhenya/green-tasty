<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CircleUserRound } from '@lucide/vue';
import { computed } from 'vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { dashboard, home, login } from '@/routes';
import { create } from '@/routes/booking';
import SiteContainer from './SiteContainer.vue';

const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);
const isHome = computed(() => page.component.startsWith('restaurants/'));
const isBooking = computed(() => page.component.startsWith('bookings/'));
const isStaff = computed(() => page.component.startsWith('staff/'));
const isReservations = computed(
    () =>
        (page.component === 'Dashboard' && !page.props.dashboardPage) ||
        page.component.startsWith('reservations/'),
);
const workplaces = computed(
    () => (page.props.staffRestaurants ?? []) as { id: number; name: string }[],
);
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
                    class="order-last flex w-full flex-wrap justify-center lg:order-none lg:w-full lg:justify-self-center"
                >
                    <template v-if="isStaff">
                        <Link
                            v-for="place in workplaces"
                            :key="place.id"
                            :href="`/staff/restaurants/${place.id}/reservations`"
                            class="inline-flex min-h-11 items-center border-b-2 border-primary px-3 text-center text-sm break-words text-primary"
                            >Reservations · {{ place.name }}</Link
                        >
                        <Link
                            :href="home()"
                            class="inline-flex min-h-11 items-center px-3 text-sm"
                            >Main page</Link
                        >
                    </template>
                    <template v-else>
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
                            :aria-current="isReservations ? 'page' : undefined"
                            :class="
                                isReservations
                                    ? 'border-primary text-primary'
                                    : 'border-transparent'
                            "
                            class="inline-flex min-h-11 items-center border-b-2 px-2 text-xs font-medium hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 sm:px-4 sm:text-base lg:min-h-[67px] lg:text-xl"
                            >Reservations</Link
                        >
                    </template>
                </nav>

                <Link
                    v-if="!user"
                    :href="login()"
                    class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-6 text-sm font-semibold text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:justify-self-end lg:text-base"
                >
                    Sign In
                </Link>
                <DropdownMenu v-else>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            aria-label="Account menu"
                            class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-6 text-primary hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring md:justify-self-end"
                            :class="
                                page.component.startsWith('settings/') ||
                                page.props.dashboardPage
                                    ? 'bg-accent'
                                    : ''
                            "
                        >
                            <CircleUserRound
                                aria-hidden="true"
                                class="size-6"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="green-tasty w-64 bg-card font-[Poppins,sans-serif] text-foreground"
                    >
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
                <div
                    v-if="!isStaff && workplaces.length"
                    class="flex w-full flex-wrap justify-end gap-3 text-xs lg:col-span-3"
                >
                    <Link
                        v-for="place in workplaces"
                        :key="place.id"
                        :href="`/staff/restaurants/${place.id}/reservations`"
                        class="inline-flex min-h-11 items-center text-primary underline"
                        >Waiter · {{ place.name }}</Link
                    >
                </div>
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
