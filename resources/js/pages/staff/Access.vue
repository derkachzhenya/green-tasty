<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    ClipboardCheck,
    UtensilsCrossed,
    MapPin,
    LogOut,
} from '@lucide/vue';
import { computed } from 'vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';
defineOptions({ inheritAttrs: false });
defineProps<{ workplaces: { id: number; name: string; address: string }[] }>();
const page = usePage();
const user = computed(() => page.props.auth?.user ?? null);

function switchAccount() {
    router.clearHistory();
    router.post(
        logout().url,
        {},
        { onSuccess: () => router.visit('/staff/login') },
    );
}
</script>
<template>
    <Head title="Staff area"><meta name="robots" content="noindex" /></Head>
    <section class="bg-accent">
        <SiteContainer class="py-10 sm:py-14">
            <div
                class="mb-4 flex items-center gap-2 text-sm font-medium text-primary"
            >
                <UtensilsCrossed class="size-5" />Green Tasty team
            </div>
            <h1 class="text-3xl font-medium sm:text-4xl">Staff area</h1>
            <p
                class="mt-4 max-w-xl text-sm leading-6 text-muted-foreground sm:text-base"
            >
                Manage reservations, review pre-orders and take care of your
                guests in one workspace.
            </p>
        </SiteContainer>
    </section>
    <SiteContainer
        class="grid items-start gap-6 py-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:py-12"
    >
        <div class="grid gap-6">
            <section
                v-if="user && workplaces.length"
                aria-labelledby="workplaces-heading"
                class="rounded-3xl bg-white p-6 shadow-sm sm:p-8"
            >
                <h2 id="workplaces-heading" class="text-2xl font-medium">
                    Welcome, {{ user.name }}
                </h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    Choose a restaurant to open its staff workspace.
                </p>
                <ul class="mt-6 grid gap-4">
                    <li
                        v-for="place in workplaces"
                        :key="place.id"
                        class="flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5"
                    >
                        <div class="min-w-0">
                            <h3 class="font-medium wrap-anywhere">
                                {{ place.name }}
                            </h3>
                            <p
                                class="mt-2 flex items-start gap-2 text-sm text-muted-foreground"
                            >
                                <MapPin class="size-4 shrink-0" />{{
                                    place.address
                                }}
                            </p>
                        </div>
                        <Button as-child class="min-h-12"
                            ><Link
                                :href="`/staff/restaurants/${place.id}/reservations`"
                                >Open workspace<ArrowRight
                                    class="size-4" /></Link
                        ></Button>
                    </li>
                </ul>
            </section>
            <section
                v-else-if="!user"
                aria-labelledby="staff-sign-in-heading"
                class="rounded-3xl bg-white p-6 shadow-sm sm:p-8"
            >
                <h2 id="staff-sign-in-heading" class="text-2xl font-medium">
                    Sign in to your staff account
                </h2>
                <p
                    class="mt-3 max-w-xl text-sm leading-6 text-muted-foreground"
                >
                    Use the staff email and password provided by your restaurant
                    administrator.
                </p>
                <Button as-child class="mt-6 min-h-12"
                    ><Link href="/staff/login"
                        >Staff sign in<ArrowRight class="size-4" /></Link
                ></Button>
            </section>
            <section
                v-else
                aria-labelledby="staff-access-heading"
                class="rounded-3xl bg-white p-6 shadow-sm sm:p-8"
            >
                <h2 id="staff-access-heading" class="text-2xl font-medium">
                    Use a staff account to continue
                </h2>
                <p class="mt-3 text-sm leading-6 text-muted-foreground">
                    You are signed in as {{ user.name }}. This account has no
                    active staff access. Switch to the separate staff sign-in
                    and use the account provided by your restaurant
                    administrator.
                </p>
                <Button
                    variant="outline"
                    class="mt-6 min-h-12"
                    @click="switchAccount"
                    ><LogOut class="size-4" />Switch to staff sign in</Button
                >
                <p class="mt-4 text-sm text-muted-foreground">
                    This signs you out of your customer account in this browser.
                </p>
            </section>
            <Link
                v-if="!page.props.auth?.isStaff"
                href="/"
                class="inline-flex min-h-11 w-fit items-center text-sm text-primary underline underline-offset-4"
                >Back to main page</Link
            >
        </div>
        <aside
            aria-label="Staff workspace features"
            class="rounded-3xl border border-primary/10 bg-white/70 p-6 sm:p-8"
        >
            <h2 class="text-lg font-medium">Your shift, at a glance</h2>
            <ul class="mt-6 space-y-6">
                <li class="flex gap-3">
                    <CalendarDays class="mt-1 size-5 shrink-0 text-primary" />
                    <div>
                        <h3 class="font-medium">Reservations</h3>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            Find bookings, change tables and manage visits.
                        </p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <ClipboardCheck class="mt-1 size-5 shrink-0 text-primary" />
                    <div>
                        <h3 class="font-medium">Pre-orders</h3>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            Confirm dishes and review changes from your guests.
                        </p>
                    </div>
                </li>
                <li class="flex gap-3">
                    <UtensilsCrossed
                        class="mt-1 size-5 shrink-0 text-primary"
                    />
                    <div>
                        <h3 class="font-medium">Orders</h3>
                        <p class="mt-1 text-sm leading-6 text-muted-foreground">
                            Create and update orders while guests are dining.
                        </p>
                    </div>
                </li>
            </ul>
        </aside>
    </SiteContainer>
</template>
