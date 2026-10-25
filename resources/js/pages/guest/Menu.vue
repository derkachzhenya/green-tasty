<script setup lang="ts">
import { ShoppingCart, ChevronRight } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import GuestCartDrawer from '@/components/guest/GuestCartDrawer.vue';
import GuestDishCard from '@/components/guest/GuestDishCard.vue';
import GuestReservationDetails from '@/components/guest/GuestReservationDetails.vue';
import ContentState from '@/components/site/ContentState.vue';
import SiteContainer from '@/components/site/SiteContainer.vue';
import { Button } from '@/components/ui/button';
import { useGuestSelections } from '@/composables/useGuestSelections';
import { bookingDate } from '@/lib/booking';
import { sortedDishes, type MenuSort } from '@/lib/guestMenu';

const props = withDefaults(
    defineProps<{ initiallyInvalid?: boolean; ownerReservationId?: number }>(),
    {
        initiallyInvalid: false,
    },
);
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
    unavailable,
    load,
    save,
    setQuantity,
    identify,
    submit,
    identifying,
    submitting,
} = useGuestSelections(props.initiallyInvalid, props.ownerReservationId);
const cartOpen = ref(false);
const ownerSubmitted = ref(false);
const nameOpen = ref(false);
const name = ref('');
const nameError = ref('');
const categoryId = ref<number | null>(null);
const sort = ref<MenuSort>('popular');
const confirmationHeading = ref<HTMLElement | null>(null);
const needsName = computed(() =>
    Boolean(
        menu.value &&
        !menu.value.guest_state.name_confirmed &&
        !menu.value.guest_state.submitted_at &&
        menu.value.order.editable,
    ),
);
const submitted = computed(() =>
    Boolean(!props.ownerReservationId && menu.value?.guest_state.submitted_at),
);
const categories = computed(() => menu.value?.catalog ?? []);
const activeCategory = computed(
    () =>
        categories.value.find((category) => category.id === categoryId.value) ??
        categories.value[0],
);
const dishes = computed(() =>
    sortedDishes(activeCategory.value?.dishes ?? [], sort.value),
);
watch(needsName, (value) => {
    nameOpen.value = value;
    if (value) name.value = menu.value?.invitation.guest.name ?? '';
});
function quantity(id: number): number {
    return rows.value.find((row) => row.menu_item_id === id)?.quantity ?? 0;
}
async function browse(): Promise<void> {
    const value = name.value.trim();
    nameError.value = !value
        ? 'Enter your name.'
        : value.length > 255 || /[\p{Cc}\p{Cf}]/u.test(value)
          ? 'Enter a name of up to 255 characters without control characters.'
          : '';
    if (nameError.value) return;
    if (await identify(value)) nameOpen.value = false;
}
async function change(id: number, value: number): Promise<void> {
    if (needsName.value) {
        nameOpen.value = true;
        return;
    }
    setQuantity(id, value);
    await save();
}
async function confirm(): Promise<void> {
    if (await submit()) {
        cartOpen.value = false;
        if (props.ownerReservationId) {
            ownerSubmitted.value = true;
            return;
        }
        await nextTick();
        confirmationHeading.value?.focus();
    }
}
function categoryKey(event: KeyboardEvent, index: number): void {
    let next = index;
    if (event.key === 'ArrowRight')
        next = (index + 1) % categories.value.length;
    else if (event.key === 'ArrowLeft')
        next = (index - 1 + categories.value.length) % categories.value.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = categories.value.length - 1;
    else return;
    event.preventDefault();
    categoryId.value = categories.value[next].id;
    document.getElementById(`guest-category-${categoryId.value}`)?.focus();
}
</script>
<template>
    <div
        class="green-tasty guest-page min-h-screen bg-background font-[Poppins,sans-serif] text-foreground"
    >
        <a
            href="#main-content"
            class="sr-only z-50 bg-card px-4 py-3 focus:not-sr-only focus:fixed focus:top-4 focus:left-4"
            >Skip to content</a
        >
        <header class="border-b border-border bg-card">
            <SiteContainer class="guest-container"
                ><div class="flex h-[68px] items-center justify-between gap-4">
                    <a
                        href="/"
                        aria-label="Green Tasty home"
                        class="shrink-0 rounded-sm focus-visible:outline-2 focus-visible:outline-offset-4"
                        ><img
                            src="/images/green-tasty/logo.png"
                            alt="Green Tasty — Healthy Eating Restaurant"
                            width="114"
                            height="40"
                            class="w-[114px]"
                    /></a>
                    <nav
                        v-if="ownerReservationId"
                        aria-label="Owner navigation"
                        class="ml-auto hidden items-center gap-6 text-base font-medium lg:flex"
                    >
                        <a href="/" class="rounded focus-visible:outline-2"
                            >Main page</a
                        ><a href="/book" class="rounded focus-visible:outline-2"
                            >Book a Table</a
                        ><a
                            href="/dashboard"
                            class="rounded text-primary focus-visible:outline-2"
                            >Reservations</a
                        >
                    </nav>
                    <a
                        v-if="ownerReservationId"
                        :href="`/reservations/${ownerReservationId}#group-pre-order`"
                        aria-label="Back to group pre-order"
                        class="ml-auto min-h-11 min-w-0 rounded px-2 py-2 text-xs text-primary underline focus-visible:outline-2 sm:text-sm"
                        ><span class="hidden sm:inline">Back to </span>Group
                        pre-order</a
                    >
                    <button
                        v-if="menu && !submitted"
                        class="relative grid size-11 shrink-0 place-items-center rounded-md focus-visible:outline-2 focus-visible:outline-ring"
                        :disabled="needsName"
                        :aria-label="`Open cart, ${count} items`"
                        @click="cartOpen = true"
                    >
                        <ShoppingCart class="size-6" aria-hidden="true" /><span
                            v-if="count"
                            class="absolute -top-1 -right-1 min-w-5 rounded-full bg-primary px-1 text-xs leading-5 text-white"
                            >{{ count }}</span
                        >
                    </button>
                </div></SiteContainer
            >
        </header>
        <main id="main-content" tabindex="-1">
            <SiteContainer v-if="invalid" class="guest-container py-16"
                ><h1 class="mb-6 text-3xl font-medium">
                    Invitation unavailable
                </h1>
                <ContentState
                    title="This invitation cannot be opened"
                    description="The link may be invalid, expired, revoked or replaced. Ask the reservation owner for a new invitation."
                    error
            /></SiteContainer>
            <SiteContainer v-else-if="!menu" class="guest-container py-16"
                ><h1 class="mb-6 text-3xl font-medium">
                    {{
                        ownerReservationId
                            ? 'Your pre-order'
                            : 'Your guest pre-order'
                    }}
                </h1>
                <ContentState
                    v-if="loading || !error"
                    title="Loading your menu…"
                    description="Checking your invitation and saved selections."
                /><ContentState
                    v-else
                    title="Unable to load your menu"
                    :description="error"
                    error
                    ><Button @click="load()">Try again</Button></ContentState
                ></SiteContainer
            >
            <template v-else-if="submitted">
                <SiteContainer class="guest-container py-6">
                    <section
                        class="mx-auto max-w-[620px] rounded-3xl bg-card p-6 shadow-[0_2px_20px_#00000005] sm:p-10"
                    >
                        <img
                            src="/images/green-tasty/icons/Check Image.png"
                            alt=""
                            width="72"
                            height="72"
                            class="mx-auto mb-6"
                        />
                        <h1
                            ref="confirmationHeading"
                            tabindex="-1"
                            class="text-center text-[28px] leading-10 font-medium focus:outline-none sm:text-[32px]"
                        >
                            Pre-Order Submitted!
                        </h1>
                        <p
                            class="mt-4 text-center text-sm leading-6 text-[#606060]"
                        >
                            Your selections have been added to this
                            reservation.<br />The reservation owner submits the
                            group order to the restaurant.
                        </p>
                        <div class="my-6 border-y border-border/40 py-6">
                            <GuestReservationDetails
                                :context="menu.context"
                                :timezone="menu.timezone"
                            />
                        </div>
                        <h2 class="mb-6 text-base font-medium">Your items</h2>
                        <ul
                            class="space-y-4 text-xs leading-5 text-muted-foreground"
                        >
                            <li
                                v-for="item in menu.selections.items"
                                :key="item.menu_item_id"
                                class="flex justify-between gap-4"
                            >
                                <span class="min-w-0 wrap-anywhere"
                                    >{{ item.name }} × {{ item.quantity }}</span
                                ><span class="shrink-0 tabular-nums">{{
                                    item.subtotal
                                }}</span>
                            </li>
                        </ul>
                        <div
                            class="mt-6 flex justify-between gap-4 font-medium"
                        >
                            <span>Total</span
                            ><output
                                aria-label="Submitted pre-order total"
                                class="text-primary"
                                >{{ menu.selections.subtotal }}</output
                            >
                        </div>
                        <p
                            class="mt-6 text-center text-xs leading-5 text-muted-foreground"
                        >
                            This selection is confirmed and cannot be changed.
                            Contact the reservation owner if you need help.
                        </p>
                        <Button
                            variant="outline"
                            class="mt-5 min-h-14 w-full"
                            disabled
                            >Create New Pre-Order</Button
                        >
                    </section>
                </SiteContainer>
            </template>
            <template v-else>
                <div class="bg-[#47b16c]">
                    <SiteContainer
                        class="guest-container py-3 text-center text-sm leading-6"
                        ><p class="wrap-anywhere">
                            You are making a pre-order for the reservation
                            {{ menu.context.address }},
                            {{ menu.context.table }},
                            {{ bookingDate(menu.context.date) }},
                            {{ menu.context.time }} – {{ menu.context.end_time
                            }}<span
                                v-if="
                                    menu.context.end_date !== menu.context.date
                                "
                            >
                                ({{ bookingDate(menu.context.end_date) }})</span
                            >
                        </p></SiteContainer
                    >
                </div>
                <SiteContainer class="guest-container"
                    ><nav
                        aria-label="Breadcrumb"
                        class="flex min-h-12 items-center gap-3 text-sm"
                    >
                        <a href="/" class="focus-visible:outline-2">Main page</a
                        ><ChevronRight
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        /><span aria-current="page">Menu</span>
                    </nav></SiteContainer
                >
                <section class="relative isolate overflow-hidden bg-[#d9e5d9]">
                    <img
                        src="/images/green-tasty/dishes/salad1.png"
                        alt=""
                        width="532"
                        height="405"
                        class="absolute inset-y-0 right-0 -z-10 h-full w-[65%] object-cover object-center opacity-60 sm:opacity-100"
                    />
                    <div
                        class="absolute inset-0 -z-10 bg-linear-to-r from-[#d9e5d9] via-[#d9e5d9]/80 to-transparent"
                    ></div>
                    <SiteContainer
                        class="guest-container flex min-h-[160px] flex-col justify-center py-8 sm:min-h-[180px]"
                        ><p
                            class="mb-2 text-base font-medium text-primary sm:text-lg"
                        >
                            Green &amp; Tasty Restaurants
                        </p>
                        <h1
                            class="text-[36px] leading-tight font-medium sm:text-5xl"
                        >
                            Menu
                        </h1></SiteContainer
                    >
                </section>
                <SiteContainer class="guest-container pb-12">
                    <div
                        v-if="needsName && !nameOpen"
                        role="status"
                        class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-accent p-3 text-sm"
                    >
                        <span>Confirm your name to start your pre-order.</span
                        ><Button @click="nameOpen = true"
                            >Enter your name</Button
                        >
                    </div>
                    <div
                        class="flex flex-wrap items-center justify-between gap-4 py-6 sm:pt-6 sm:pb-10"
                    >
                        <div
                            role="tablist"
                            aria-label="Menu categories"
                            class="flex max-w-full overflow-x-auto border-b border-border"
                        >
                            <button
                                v-for="(category, index) in categories"
                                :id="`guest-category-${category.id}`"
                                :key="category.id"
                                role="tab"
                                :aria-selected="
                                    activeCategory?.id === category.id
                                "
                                aria-controls="guest-dishes"
                                :tabindex="
                                    activeCategory?.id === category.id ? 0 : -1
                                "
                                class="min-h-11 shrink-0 border-b-2 px-4 text-sm focus-visible:outline-2 focus-visible:outline-offset-[-2px]"
                                :class="
                                    activeCategory?.id === category.id
                                        ? 'border-primary font-medium text-primary'
                                        : 'border-transparent'
                                "
                                @click="categoryId = category.id"
                                @keydown="categoryKey($event, index)"
                            >
                                {{ category.name }}
                            </button>
                        </div>
                        <label class="flex min-w-0 items-center gap-3 text-sm"
                            >Sort by:<select
                                :value="sort"
                                @change="
                                    sort = ($event.target as HTMLSelectElement)
                                        .value as MenuSort
                                "
                                class="min-h-11 w-[208px] min-w-0 rounded-lg border border-border bg-card px-3 pr-8 focus-visible:outline-2 focus-visible:outline-primary"
                                aria-label="Sort menu"
                            >
                                <option value="popular">Popular First</option>
                                <option value="newest">Newest First</option>
                                <option value="price-asc">
                                    Price: Low to High
                                </option>
                                <option value="price-desc">
                                    Price: High to Low
                                </option>
                            </select></label
                        >
                    </div>
                    <p
                        v-if="error"
                        role="alert"
                        class="mb-4 text-sm text-destructive"
                    >
                        {{ error }}
                        <button
                            :disabled="busy"
                            class="min-h-11 underline"
                            @click="load()"
                        >
                            Refresh menu and status
                        </button>
                    </p>
                    <p v-if="saving" role="status" class="sr-only">
                        Saving cart…
                    </p>
                    <p
                        v-if="locked"
                        role="status"
                        class="mb-4 rounded-lg bg-accent p-4 text-sm"
                    >
                        This group order is read-only. You can review your saved
                        dishes in the cart.
                    </p>
                    <div
                        v-if="activeCategory"
                        id="guest-dishes"
                        role="tabpanel"
                        :aria-labelledby="`guest-category-${activeCategory.id}`"
                        tabindex="0"
                        class="focus-visible:outline-2 focus-visible:outline-primary"
                    >
                        <div
                            v-if="dishes.length"
                            class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4 lg:gap-x-8 lg:gap-y-10"
                        >
                            <GuestDishCard
                                v-for="dish in dishes"
                                :key="dish.id"
                                :dish="dish"
                                :quantity="quantity(dish.id)"
                                :disabled="
                                    busy ||
                                    needsName ||
                                    (rows.length >= 100 && !quantity(dish.id))
                                "
                                :locked="locked"
                                @change="change(dish.id, $event)"
                            />
                        </div>
                        <ContentState
                            v-else
                            title="No dishes in this category"
                            description="Choose another category or check back later."
                        />
                    </div>
                    <ContentState
                        v-else
                        title="The menu is being prepared"
                        description="No dishes are available yet. Check back later."
                    />
                </SiteContainer>
                <BookingModal
                    v-model:open="nameOpen"
                    title="You’re Invited!"
                    description="Confirm your guest name to browse this reservation’s menu."
                    :busy="identifying"
                >
                    <p class="-mt-4 text-sm leading-6">
                        You’re invited to pre-order for an upcoming reservation.
                    </p>
                    <GuestReservationDetails
                        :context="menu.context"
                        :timezone="menu.timezone"
                    />
                    <form class="space-y-5" @submit.prevent="browse">
                        <div>
                            <label for="guest-name" class="mb-2 block text-sm"
                                >Enter Your Name</label
                            ><input
                                id="guest-name"
                                v-model="name"
                                class="auth-input guest-name-input"
                                placeholder="e.g. Jonson D."
                                autocomplete="name"
                                maxlength="255"
                                :disabled="identifying"
                                :aria-invalid="
                                    !!nameError || validation.length > 0
                                "
                                aria-describedby="guest-name-errors"
                            />
                            <div
                                id="guest-name-errors"
                                class="mt-2 space-y-1 text-sm text-destructive"
                            >
                                <p v-if="nameError" role="alert">
                                    {{ nameError }}
                                </p>
                                <p
                                    v-for="message in validation"
                                    :key="message"
                                    role="alert"
                                >
                                    {{ message }}
                                </p>
                                <p v-if="error" role="alert">{{ error }}</p>
                            </div>
                        </div>
                        <Button
                            type="submit"
                            class="min-h-14 w-full"
                            :disabled="identifying"
                            >{{
                                identifying ? 'Saving name…' : 'Browse Menu'
                            }}</Button
                        >
                    </form>
                </BookingModal>
                <GuestCartDrawer
                    v-model:open="cartOpen"
                    :items="rows"
                    :total="total"
                    :busy="busy"
                    :locked="locked"
                    :dirty="dirty"
                    :unavailable="unavailable"
                    :error="error"
                    :validation="validation"
                    :submitting="submitting"
                    @change="change"
                    @save="save"
                    @submit="confirm"
                    @refresh="load()"
                />
            </template>
            <BookingModal
                v-if="ownerReservationId"
                :open="ownerSubmitted"
                title="Pre-Order Submitted"
                description="Your own selection has been saved for this reservation."
                @update:open="ownerSubmitted = $event"
            >
                <p class="my-5 text-sm leading-6">
                    Your pre-order was saved successfully. Review the group
                    order in Reservations and submit it to the restaurant.
                </p>
                <a
                    href="/dashboard"
                    class="inline-flex min-h-14 w-full items-center justify-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground focus-visible:outline-2"
                    >View Reservations</a
                >
            </BookingModal>
        </main>
    </div>
</template>

<style scoped>
:global(.green-tasty.booking-dialog:has(#guest-name)) {
    box-shadow: 0 2px 20px #0000000d;
}
.guest-name-input:focus-visible {
    border-color: var(--primary);
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

@media (min-width: 1024px) {
    .guest-container {
        padding-inline: 40px;
    }
}
</style>
