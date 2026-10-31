<script setup lang="ts">
import { CalendarDays, ChevronDown } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { bookingDate } from '@/lib/booking';

const props = defineProps<{
    id: string;
    modelValue: string;
    min: string;
    max: string;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    variant?: 'filter';
}>();
const emit = defineEmits<{ 'update:modelValue': [string] }>();
const touched = ref(false);
const focused = ref(false);

function displayDate(value: string): string {
    const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    return parts ? `${parts[3]}.${parts[2]}.${parts[1]}` : '';
}

function isoDate(value: string): string {
    const parts = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(value);
    if (!parts) return '';
    const iso = `${parts[3]}-${parts[2]}-${parts[1]}`;
    const date = new Date(`${iso}T12:00:00Z`);
    // Reject impossible dates rather than letting Date roll them into another month.
    return Number.isFinite(date.getTime()) &&
        date.toISOString().slice(0, 10) === iso
        ? iso
        : '';
}

const draft = ref(displayDate(props.modelValue));
const formatError = computed(() =>
    touched.value && draft.value && !isoDate(draft.value)
        ? 'Enter a valid date in dd.mm.yyyy format.'
        : '',
);
watch(
    () => props.modelValue,
    (value) => {
        // Keep a partially typed date when the parent clears its ISO value.
        if (value !== isoDate(draft.value)) draft.value = displayDate(value);
    },
);

function input(event: Event): void {
    draft.value = (event.target as HTMLInputElement).value;
    emit('update:modelValue', isoDate(draft.value));
}
function chooseDate(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    draft.value = displayDate(value);
    touched.value = false;
    emit('update:modelValue', value);
}
</script>

<template>
    <div>
        <div class="relative">
            <CalendarDays
                v-if="variant === 'filter'"
                class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                :id="id"
                type="text"
                placeholder="dd.mm.yyyy"
                autocomplete="off"
                maxlength="10"
                lang="en"
                :value="draft"
                :disabled="disabled"
                class="booking-input !pr-12"
                :class="{
                    '!min-h-14 !pl-11': variant === 'filter',
                    '!text-transparent':
                        variant === 'filter' && !focused && !!modelValue,
                }"
                :aria-invalid="invalid || !!formatError"
                :aria-describedby="
                    `${describedBy ?? ''} ${id}-format-error`.trim()
                "
                @input="input"
                @focus="focused = true"
                @blur="
                    touched = true;
                    focused = false;
                "
            />
            <span
                v-if="variant === 'filter' && !focused && modelValue"
                class="pointer-events-none absolute inset-y-0 right-12 left-11 flex items-center text-sm"
                aria-hidden="true"
                >{{ bookingDate(modelValue) }}</span
            >
            <span
                class="absolute inset-y-0 right-1 flex w-11 items-center justify-center rounded-md has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-primary"
            >
                <component
                    :is="variant === 'filter' ? ChevronDown : CalendarDays"
                    class="size-5"
                    aria-hidden="true"
                />
                <!-- Keep native calendar clicks and keyboard support; visible text uses a fixed format. -->
                <input
                    type="date"
                    lang="en"
                    aria-label="Open calendar"
                    :value="modelValue"
                    :min="min"
                    :max="max"
                    :disabled="disabled"
                    class="booking-calendar-trigger"
                    @input="chooseDate"
                />
            </span>
        </div>
        <InputError :id="`${id}-format-error`" :message="formatError" />
    </div>
</template>
