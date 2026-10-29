<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineProps<{ name: string; quantity: number; disabled: boolean }>();
defineEmits<{ change: [quantity: number] }>();
</script>

<template>
    <div
        role="group"
        :aria-label="`${name} quantity`"
        class="guest-quantity inline-flex shrink-0 items-center gap-1 whitespace-nowrap"
    >
        <Button
            type="button"
            variant="outline"
            class="relative size-11 shrink-0 border-0 bg-transparent p-0 text-base shadow-none before:absolute before:inset-1.5 before:rounded-md before:border before:border-primary disabled:before:border-border"
            :disabled="disabled || quantity === 0"
            :aria-label="`Decrease ${name} quantity`"
            @click="$emit('change', quantity - 1)"
            >−</Button
        >
        <output
            class="w-8 shrink-0 text-center text-sm font-medium tabular-nums"
            :aria-label="`${name} selected quantity`"
            >{{ quantity }}</output
        >
        <Button
            type="button"
            variant="outline"
            class="relative size-11 shrink-0 border-0 bg-transparent p-0 text-base shadow-none before:absolute before:inset-1.5 before:rounded-md before:border before:border-primary disabled:before:border-border"
            :disabled="disabled || quantity >= 99"
            :aria-label="`Increase ${name} quantity`"
            @click="$emit('change', quantity + 1)"
            >+</Button
        >
    </div>
</template>
