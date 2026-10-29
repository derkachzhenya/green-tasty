<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import {
    Sheet,
    SheetContent,
    SheetTitle,
    SheetDescription,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import {
    dishImage,
    dishImageCropped,
    dishPreparationMinutes,
} from '@/lib/guestMenu';
import { dollarPrice } from '@/lib/guestSelection';
import type { DraftDish } from '@/types/guest-selection';
import QuantityControl from './QuantityControl.vue';
const props = defineProps<{
    ownerEdit?: boolean;
    alreadySubmitted?: boolean;
    open: boolean;
    items: DraftDish[];
    total: string;
    busy: boolean;
    locked: boolean;
    dirty: boolean;
    unavailable: boolean;
    error: string;
    validation: string[];
    submitting: boolean;
}>();
const emit = defineEmits<{
    'update:open': [boolean];
    change: [id: number, quantity: number];
    submit: [];
    save: [];
    cancel: [];
    refresh: [];
}>();
let returnFocus: HTMLElement | null = null;
function rememberFocus(): void {
    returnFocus =
        document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
}
function restoreFocus(event: Event): void {
    if (returnFocus?.isConnected) {
        event.preventDefault();
        returnFocus.focus();
    }
}
function close(): void {
    if (!props.submitting) emit('update:open', false);
}
</script>
<template>
    <Sheet
        :open="open"
        @update:open="!submitting && emit('update:open', $event)"
    >
        <SheetContent
            class="green-tasty guest-cart w-full gap-0 bg-card font-[Poppins,sans-serif] text-foreground sm:max-w-[640px]"
            @open-auto-focus="rememberFocus"
            @close-auto-focus="restoreFocus"
            @escape-key-down.prevent="close"
            @keydown.esc.stop.prevent="close"
            @interact-outside.prevent="close"
        >
            <header
                class="border-b border-border/40 px-6 py-6 text-center sm:px-10"
            >
                <SheetTitle class="text-[26px] leading-10 font-medium"
                    >Cart</SheetTitle
                ><SheetDescription class="sr-only"
                    >Your dishes for this reservation. Confirming your selection
                    leaves group submission with the reservation
                    owner.</SheetDescription
                >
            </header>
            <div
                v-if="!items.length"
                class="flex min-h-0 flex-1 flex-col items-center justify-center-safe overflow-y-auto p-6 pb-[clamp(24px,22dvh,224px)] text-center"
            >
                <img
                    src="/images/green-tasty/icons/Illustration.png"
                    alt=""
                    width="381"
                    height="187"
                    class="mb-4 w-[381px] max-w-full shrink-0"
                />
                <h2 class="text-[26px] leading-9 font-medium">
                    Your cart is empty
                </h2>
                <p class="mt-2 text-base leading-6 text-muted-foreground">
                    Looks like you haven’t added any dishes to your cart yet
                </p>
                <button
                    class="mt-4 min-h-11 shrink-0 px-3 text-base underline underline-offset-8 focus-visible:outline-2"
                    @click="close"
                >
                    Go to Menu
                </button>
            </div>
            <div
                v-else
                class="min-h-0 flex-1 overflow-y-auto p-4 sm:px-10 sm:py-6"
            >
                <h3
                    v-if="ownerEdit && items.length"
                    class="mb-4 text-base font-medium"
                >
                    My Pre-Order
                </h3>
                <ul class="space-y-3">
                    <li
                        v-for="item in items"
                        :key="item.menu_item_id"
                        class="flex min-w-0 gap-3 rounded-lg bg-card p-3 shadow-[0_1px_8px_#00000012] sm:gap-4 sm:p-4"
                    >
                        <img
                            v-if="dishImage(item.name)"
                            :src="dishImage(item.name)!"
                            alt=""
                            width="96"
                            height="96"
                            class="size-16 shrink-0 self-start sm:size-24"
                            :class="
                                dishImageCropped(item.name)
                                    ? 'rounded-full object-cover'
                                    : 'object-contain'
                            "
                        />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <h2 class="text-sm font-medium wrap-anywhere">
                                    {{ item.name }}
                                </h2>
                                <button
                                    v-if="!locked"
                                    class="-mt-2 -mr-2 -mb-2 grid size-11 shrink-0 place-items-center rounded-md focus-visible:outline-2"
                                    :disabled="busy"
                                    :aria-label="`Remove ${item.name}`"
                                    @click="
                                        emit('change', item.menu_item_id, 0)
                                    "
                                >
                                    <Trash2 class="size-5" />
                                </button>
                            </div>
                            <p
                                v-if="dishPreparationMinutes(item.name)"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Expected ready time –
                                {{ dishPreparationMinutes(item.name) }} minutes
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                Unit price: {{ dollarPrice(item.unit_price) }}
                            </p>
                            <p v-if="!item.available" class="mt-1 text-xs">
                                No longer available. Remove before submitting.
                            </p>
                            <div
                                class="mt-1 flex flex-wrap items-center justify-between gap-2"
                            >
                                <QuantityControl
                                    v-if="!locked"
                                    :name="item.name"
                                    :quantity="item.quantity"
                                    :disabled="busy || !item.available"
                                    @change="
                                        emit(
                                            'change',
                                            item.menu_item_id,
                                            $event,
                                        )
                                    "
                                /><span v-else class="text-sm"
                                    >Quantity: {{ item.quantity }}</span
                                >
                                <span
                                    class="text-sm font-medium text-primary tabular-nums"
                                    >{{ dollarPrice(item.subtotal) }}</span
                                >
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
            <footer
                v-if="
                    items.length ||
                    (ownerEdit && dirty) ||
                    error ||
                    validation.length ||
                    locked
                "
                class="max-h-[45dvh] shrink-0 overflow-y-auto border-t border-border/40 p-4 sm:px-10 sm:py-6"
            >
                <p
                    v-if="error"
                    role="alert"
                    class="mb-3 text-sm text-destructive"
                >
                    {{ error }}
                </p>
                <ul
                    v-if="validation.length"
                    role="alert"
                    class="mb-3 space-y-2 text-sm text-destructive"
                >
                    <li v-for="message in validation" :key="message">
                        {{ message }}
                    </li>
                </ul>
                <p v-if="locked" role="status" class="mb-3 text-sm">
                    This cart is read-only. Refresh to retrieve the saved order
                    status.
                </p>
                <p v-if="unavailable" class="mb-3 text-sm">
                    Remove unavailable dishes before submitting.
                </p>
                <div
                    v-if="items.length || (ownerEdit && dirty)"
                    class="mb-4 flex justify-between gap-4 text-lg font-medium"
                >
                    <span>Grand Total</span
                    ><output
                        aria-label="Your pre-order total"
                        class="text-primary tabular-nums"
                        >{{ dollarPrice(total) }}</output
                    >
                </div>
                <div
                    class="grid gap-3"
                    :class="ownerEdit && dirty ? 'sm:grid-cols-2' : ''"
                >
                    <Button
                        v-if="ownerEdit && dirty && !locked"
                        variant="outline"
                        class="min-h-14 w-full"
                        :disabled="busy"
                        @click="emit('cancel')"
                        >Cancel Changes</Button
                    >
                    <Button
                        v-if="(items.length || (ownerEdit && dirty)) && !locked"
                        class="min-h-14 w-full"
                        :disabled="
                            busy ||
                            unavailable ||
                            (ownerEdit && alreadySubmitted && !dirty)
                        "
                        @click="emit('submit')"
                        >{{
                            submitting ? 'Submitting…' : 'Submit Pre-Order'
                        }}</Button
                    >
                </div>
                <button
                    v-if="dirty && !ownerEdit && !busy && !locked"
                    class="mt-2 min-h-11 w-full text-sm underline"
                    @click="emit('save')"
                >
                    Retry saving cart
                </button>
                <button
                    v-if="error || locked"
                    class="mt-2 min-h-11 w-full text-sm underline"
                    :disabled="busy"
                    @click="emit('refresh')"
                >
                    Refresh menu and status
                </button>
            </footer>
        </SheetContent>
    </Sheet>
</template>
