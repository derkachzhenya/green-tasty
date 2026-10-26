<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { sessionJson, SessionRequestError } from '@/lib/sessionJson';
import { Button } from '@/components/ui/button';
import type { RestaurantReview } from '@/types/restaurant-review';
const props = defineProps<{
    review: RestaurantReview;
    reservationId: number;
}>();
const emit = defineEmits<{ changed: [] }>();
const choices = ref<Record<number, number>>({}),
    quantities = ref<Record<number, number>>({});
const busy = ref(false),
    stale = ref(false),
    error = ref(''),
    notice = ref('');
watch(
    () => props.review.version,
    () => {
        stale.value = false;
        error.value = '';
    },
);
const rejected = computed(() =>
    props.review.participants.flatMap((p) =>
        p.items
            .filter((i) => i.decision === 'rejected')
            .map((i) => ({ ...i, participant: p.name, guestId: p.id })),
    ),
);
function options(guestId: number) {
    const used =
        props.review.participants
            .find((p) => p.id === guestId)
            ?.items.map((i) => i.menu_item_id) ?? [];
    return (props.review.catalog ?? []).filter(
        (dish) => !used.includes(dish.id),
    );
}
async function replace(id: number) {
    if (busy.value || stale.value || !choices.value[id]) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        await sessionJson(
            `/reservations/${props.reservationId}/restaurant-review/items/${id}/replace`,
            'post',
            new AbortController().signal,
            {
                menu_item_id: choices.value[id],
                quantity: quantities.value[id] ?? 1,
            },
            props.review.version,
        );
        notice.value =
            'Replacement submitted for restaurant review. The original rejection remains in history.';
        emit('changed');
    } catch (reason) {
        stale.value = true;
        error.value =
            reason instanceof SessionRequestError && reason.status === 422
                ? Object.values(reason.errors).flat().join(' ')
                : 'The order changed or corrections are closed. Refresh the group before continuing.';
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <section
        aria-label="Restaurant review"
        class="space-y-3 rounded-xl border border-primary/20 bg-white p-4"
    >
        <h2 class="font-medium">Restaurant review · {{ review.status }}</h2>
        <dl class="grid grid-cols-2 gap-2 text-sm">
            <dt>Confirmed total</dt>
            <dd class="text-right">{{ review.totals.confirmed }}</dd>
            <dt>Rejected · excluded</dt>
            <dd class="text-right">{{ review.totals.rejected }}</dd>
            <dt>Pending restaurant review</dt>
            <dd class="text-right">{{ review.totals.pending }}</dd>
        </dl>
        <p class="text-xs text-muted-foreground">
            Requested totals include rejected dishes. Only the confirmed total
            is accepted by the restaurant. Replaced rows stay in history and are
            excluded from current totals.
        </p>
        <p v-if="notice" role="status" class="text-sm text-primary">
            {{ notice }}
        </p>
        <p v-if="error" role="alert" class="text-sm text-destructive">
            {{ error }}
        </p>
        <Button
            v-if="stale"
            variant="outline"
            :disabled="busy"
            @click="emit('changed')"
            >Refresh restaurant review</Button
        >
        <article
            v-for="item in rejected"
            :key="item.id"
            class="rounded-lg bg-[#fff6f8] p-3 text-sm"
        >
            <p class="font-medium">
                {{ item.participant }} · {{ item.name }} — Rejected
            </p>
            <p>
                Qty: {{ item.quantity }} · saved price {{ item.unit_price }} ·
                {{ item.total }}
            </p>
            <p v-if="item.superseded">Replaced · retained in history</p>
            <form
                v-else-if="review.can_correct"
                class="mt-3 grid gap-3"
                @submit.prevent="replace(item.id)"
            >
                <label class="grid gap-1"
                    >Replacement dish<select
                        v-model.number="choices[item.id]"
                        required
                        class="min-h-11 w-full min-w-0 rounded-lg border bg-white px-2"
                    >
                        <option :value="undefined" disabled>
                            Choose an available dish
                        </option>
                        <option
                            v-for="dish in options(item.guestId)"
                            :key="dish.id"
                            :value="dish.id"
                        >
                            {{ dish.name }} · {{ dish.price }}
                        </option>
                    </select></label
                >
                <label class="grid gap-1"
                    >Replacement quantity<input
                        v-model.number="quantities[item.id]"
                        type="number"
                        min="1"
                        max="99"
                        placeholder="1"
                        class="min-h-11 w-full min-w-0 rounded-lg border bg-white px-3"
                /></label>
                <Button
                    type="submit"
                    :disabled="busy || stale || !choices[item.id]"
                    class="min-h-11"
                    >Replace and resubmit</Button
                >
            </form>
            <p v-else>Corrections are closed for this reservation.</p>
        </article>
    </section>
</template>
