<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Star } from '@lucide/vue';
import BookingModal from '@/components/bookings/BookingModal.vue';
import { Button } from '@/components/ui/button';
import { sessionJson } from '@/lib/sessionJson';
import type { OwnedReservation } from '@/types/reservation';
const props = defineProps<{ reservation: OwnedReservation }>();
const emit = defineEmits<{ close: []; changed: [] }>();
const restaurant = ref('');
const service = ref('');
const comment = ref('');
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const controller = new AbortController();
async function load() {
    loading.value = true;
    error.value = '';
    try {
        const response = await fetch(
            `/reservations/${props.reservation.id}/feedback`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        if (!response.ok) throw new Error();
        const data = (await response.json()) as {
            feedback: {
                restaurant_rating: number;
                service_rating: number;
                comment: string | null;
            } | null;
        };
        if (controller.signal.aborted) return;
        if (data.feedback) {
            restaurant.value = String(data.feedback.restaurant_rating);
            service.value = String(data.feedback.service_rating);
            comment.value = data.feedback.comment ?? '';
        }
    } catch {
        if (!controller.signal.aborted)
            error.value =
                'Unable to load feedback. Close this dialog and try again.';
    } finally {
        loading.value = false;
    }
}
async function save() {
    if (busy.value || loading.value || error.value) return;
    busy.value = true;
    try {
        await sessionJson(
            `/reservations/${props.reservation.id}/feedback`,
            'put',
            controller.signal,
            {
                restaurant_rating: Number(restaurant.value),
                service_rating: Number(service.value),
                comment: comment.value,
            },
        );
        if (!controller.signal.aborted) {
            emit('changed');
            emit('close');
        }
    } catch {
        if (!controller.signal.aborted)
            error.value =
                'Unable to save feedback. Reload the reservation and try again.';
    } finally {
        busy.value = false;
    }
}
onMounted(load);
onBeforeUnmount(() => controller.abort());
</script>
<template>
    <BookingModal
        description="Reservation details and available actions."
        :open="true"
        :title="
            reservation.feedback_action === 'update'
                ? 'Update Feedback'
                : 'Leave Feedback'
        "
        :busy="busy"
        @update:open="!$event && !busy && emit('close')"
    >
        <p class="mb-5 text-sm leading-6">
            {{ reservation.restaurant.name }} —
            {{ reservation.restaurant.address }}
        </p>
        <p v-if="loading" role="status">Loading feedback…</p>
        <form v-else class="space-y-5" @submit.prevent="save">
            <fieldset
                v-for="field in ['restaurant', 'service']"
                :key="field"
                class="space-y-3 rounded-xl border border-border/50 p-4"
            >
                <legend class="block text-sm font-medium">
                    {{
                        field === 'restaurant'
                            ? '1. Restaurant rating'
                            : '2. Service rating'
                    }}
                </legend>
                <div class="flex gap-2">
                    <label
                        v-for="rating in 5"
                        :key="rating"
                        class="relative cursor-pointer"
                    >
                        <input
                            :name="`feedback-${field}`"
                            type="radio"
                            :value="rating"
                            :checked="
                                Number(
                                    field === 'restaurant'
                                        ? restaurant
                                        : service,
                                ) === rating
                            "
                            :aria-label="`${field === 'restaurant' ? 'Restaurant' : 'Service'}: ${rating} out of 5`"
                            required
                            :disabled="busy || !!error"
                            class="peer absolute inset-0 size-full cursor-pointer opacity-0"
                            @change="
                                field === 'restaurant'
                                    ? (restaurant = String(rating))
                                    : (service = String(rating))
                            "
                        />
                        <span
                            class="grid size-11 place-items-center rounded-md peer-focus-visible:outline-2 peer-focus-visible:outline-primary"
                            ><Star
                                class="size-7 text-primary"
                                :class="
                                    Number(
                                        field === 'restaurant'
                                            ? restaurant
                                            : service,
                                    ) >= rating
                                        ? 'fill-primary'
                                        : 'fill-none'
                                "
                        /></span>
                    </label>
                </div>
            </fieldset>
            <label for="feedback-comment" class="block text-sm font-medium"
                >Comment (optional)</label
            >
            <textarea
                id="feedback-comment"
                v-model="comment"
                maxlength="2000"
                :disabled="busy || !!error"
                class="min-h-24 w-full rounded-md border border-border p-3 focus-visible:outline-2"
            />
            <p v-if="error" role="alert" class="text-sm text-destructive">
                {{ error }}
            </p>
            <Button
                type="submit"
                class="min-h-11 w-full"
                :disabled="busy || !!error || !restaurant || !service"
                >{{ busy ? 'Saving…' : 'Save Feedback' }}</Button
            >
        </form>
    </BookingModal>
</template>
