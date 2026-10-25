<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        modelValue: number;
        max?: number;
        disabled?: boolean;
        id?: string;
        invalid?: boolean;
        describedBy?: string;
    }>(),
    { max: 255, id: 'guest-count' },
);
const emit = defineEmits<{ 'update:modelValue': [number] }>();
</script>
<template>
    <div
        class="flex h-11 min-w-0 items-center justify-between gap-1 rounded-lg border border-border bg-card px-2"
    >
        <button
            type="button"
            aria-label="Fewer guests"
            :disabled="disabled || modelValue <= 1"
            class="grid size-8 shrink-0 place-items-center rounded-md border border-primary text-primary focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-40"
            @click="
                emit('update:modelValue', Math.max(1, props.modelValue - 1))
            "
        >
            −
        </button>
        <input
            :id="id"
            :value="modelValue"
            type="number"
            min="1"
            :max="max"
            :disabled="disabled"
            aria-label="Guests, including you"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
            class="w-full min-w-0 [appearance:textfield] appearance-none bg-transparent text-center text-sm [&::-webkit-inner-spin-button]:appearance-none"
            @input="
                emit(
                    'update:modelValue',
                    Number(($event.target as HTMLInputElement).value),
                )
            "
        />
        <button
            type="button"
            aria-label="More guests"
            :disabled="disabled || modelValue >= max"
            class="grid size-8 shrink-0 place-items-center rounded-md border border-primary text-primary focus-visible:outline-2 focus-visible:outline-offset-2 disabled:opacity-40"
            @click="
                emit('update:modelValue', Math.min(max, props.modelValue + 1))
            "
        >
            +
        </button>
    </div>
</template>
