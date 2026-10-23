<script setup lang="ts">
import MenuDishCard from '@/components/restaurants/MenuDishCard.vue';
import ContentState from '@/components/site/ContentState.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import GuestOrderPanel from '@/components/guest/GuestOrderPanel.vue';
import QuantityControl from '@/components/guest/QuantityControl.vue';
import { Button } from '@/components/ui/button';
import { useGuestSelections } from '@/composables/useGuestSelections';
import { formatInvitationExpiration } from '@/lib/invitationApi';

const props = withDefaults(defineProps<{ initiallyInvalid?: boolean }>(), {
    initiallyInvalid: false,
});
const {
    menu,
    rows,
    total,
    count,
    dirty,
    locked,
    busy,
    loading,
    saving,
    invalid,
    error,
    validation,
    success,
    unavailable,
    load,
    save,
    setQuantity,
} = useGuestSelections(props.initiallyInvalid);
function quantity(id: number): number {
    return rows.value.find((row) => row.menu_item_id === id)?.quantity ?? 0;
}
</script>

<template>
    <div
        class="green-tasty min-h-screen bg-background font-[Poppins,sans-serif] text-foreground"
    >
        <a
            href="#main-content"
            class="sr-only z-50 rounded-sm bg-primary px-4 py-3 text-primary-foreground focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:outline-2 focus:outline-offset-4 focus:outline-ring"
            >Skip to content</a
        >
        <header class="border-b border-border bg-card">
            <SiteContainer>
                <div
                    class="flex min-h-[68px] items-center justify-between gap-4 py-3"
                >
                    <a
                        href="/"
                        class="rounded-sm text-lg font-medium tracking-tight text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                        aria-label="Green Tasty home"
                        >Green Tasty</a
                    >
                    <a
                        v-if="menu"
                        href="#your-order"
                        class="inline-flex min-h-11 items-center rounded-sm px-2 text-sm font-medium text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                        >Your pre-order ({{ count }})</a
                    >
                </div>
            </SiteContainer>
        </header>
        <main id="main-content" tabindex="-1">
            <SiteContainer v-if="invalid" class="py-16">
                <h1 class="mb-6 text-3xl font-medium">
                    Invitation unavailable
                </h1>
                <ContentState
                    title="This invitation cannot be opened"
                    description="The link may be invalid, expired, revoked or replaced. Ask the reservation owner for a new invitation."
                    error
                />
            </SiteContainer>
            <SiteContainer v-else-if="!menu" class="py-16">
                <h1 class="mb-6 text-3xl font-medium">Your guest pre-order</h1>
                <ContentState
                    v-if="loading || !error"
                    title="Loading your menu…"
                    description="Checking your invitation and saved selections."
                />
                <ContentState
                    v-else
                    title="Unable to load your menu"
                    :description="error || 'Please try again.'"
                    error
                >
                    <Button type="button" class="min-h-11" @click="load()"
                        >Try again</Button
                    >
                </ContentState>
            </SiteContainer>
            <template v-else>
                <div class="bg-accent">
                    <SiteContainer class="py-4 text-sm leading-6">
                        <p class="wrap-anywhere">
                            A pre-order for
                            <span class="font-medium">{{
                                menu.invitation.guest.name
                            }}</span>
                            ·
                            {{
                                formatInvitationExpiration(
                                    menu.invitation.reservation.reserved_at,
                                    menu.timezone,
                                )
                            }}
                            ({{ menu.timezone }})
                        </p>
                    </SiteContainer>
                </div>
                <SiteContainer class="py-8 sm:py-12">
                    <div
                        class="mb-8 flex flex-wrap items-end justify-between gap-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="mb-2 text-sm font-medium wrap-anywhere text-primary"
                            >
                                {{ menu.invitation.restaurant.name }}
                            </p>
                            <h1
                                class="text-3xl leading-tight font-medium sm:text-4xl"
                            >
                                Menu
                            </h1>
                            <p
                                class="mt-3 text-sm leading-6 text-muted-foreground"
                            >
                                Choose something you’ll love. No account needed.
                            </p>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            class="min-h-11"
                            :disabled="busy"
                            @click="load(true)"
                            >{{
                                loading
                                    ? 'Refreshing…'
                                    : 'Refresh menu and status'
                            }}</Button
                        >
                    </div>
                    <div
                        class="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_360px]"
                    >
                        <section
                            aria-label="Restaurant menu"
                            :aria-busy="loading"
                            class="min-w-0 space-y-8"
                        >
                            <nav
                                v-if="menu.invitation.menu_categories.length"
                                aria-label="Menu categories"
                                class="flex flex-wrap gap-x-6 gap-y-2 border-b border-border"
                            >
                                <a
                                    v-for="category in menu.invitation
                                        .menu_categories"
                                    :key="category.id"
                                    :href="`#category-${category.id}`"
                                    class="inline-flex min-h-11 max-w-full items-center rounded-sm border-b-2 border-primary text-sm font-medium wrap-anywhere text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                                    >{{ category.name }}</a
                                >
                            </nav>
                            <ContentState
                                v-if="!menu.invitation.menu_categories.length"
                                title="The menu is being prepared"
                                description="No dishes are available yet. Check back later or refresh the menu."
                            />
                            <section
                                v-for="category in menu.invitation
                                    .menu_categories"
                                :id="`category-${category.id}`"
                                :key="category.id"
                                :aria-labelledby="`category-heading-${category.id}`"
                                tabindex="-1"
                                class="scroll-mt-6 space-y-5"
                            >
                                <h2
                                    :id="`category-heading-${category.id}`"
                                    class="text-xl font-medium wrap-anywhere"
                                >
                                    {{ category.name }}
                                </h2>
                                <p
                                    v-if="!category.dishes.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    No dishes are available in this category.
                                </p>
                                <div v-else class="grid gap-5 sm:grid-cols-2">
                                    <MenuDishCard
                                        v-for="dish in category.dishes"
                                        :key="dish.id"
                                        :dish="dish"
                                        heading="h3"
                                    >
                                        <p
                                            v-if="locked"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Selections are locked
                                        </p>
                                        <QuantityControl
                                            v-else-if="quantity(dish.id)"
                                            :name="dish.name"
                                            :quantity="quantity(dish.id)"
                                            :disabled="busy"
                                            @change="
                                                setQuantity(dish.id, $event)
                                            "
                                        />
                                        <Button
                                            v-else
                                            type="button"
                                            class="min-h-11 w-full"
                                            :disabled="
                                                busy || rows.length >= 100
                                            "
                                            :aria-label="`Add ${dish.name}`"
                                            @click="setQuantity(dish.id, 1)"
                                            >Add to pre-order</Button
                                        >
                                    </MenuDishCard>
                                </div>
                            </section>
                        </section>
                        <GuestOrderPanel
                            :items="rows"
                            :total="total"
                            :count="count"
                            :dirty="dirty"
                            :locked="locked"
                            :busy="busy"
                            :saving="saving"
                            :unavailable="unavailable"
                            :error="error"
                            :validation="validation"
                            :success="success"
                            @change="setQuantity"
                            @save="save"
                        />
                    </div>
                </SiteContainer>
            </template>
        </main>
    </div>
</template>
