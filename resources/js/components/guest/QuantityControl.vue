<script setup lang="ts">
import { Button } from '@/components/ui/button';

defineProps<{ name: string; quantity: number; disabled: boolean }>();
defineEmits<{ change: [quantity: number] }>();
</script>

<template>
    <div
        role="group"
        :aria-label="`${name} quantity`"
        class="inline-flex items-center gap-3"
    >
        <Button
            type="button"
            variant="outline"
            class="size-11 text-lg"
            :disabled="disabled || quantity === 0"
            :aria-label="`Decrease ${name} quantity`"
            @click="$emit('change', quantity - 1)"
            >−</Button
        >
        <output
            class="min-w-6 text-center text-sm font-medium tabular-nums"
            :aria-label="`${name} selected quantity`"
            >{{ quantity }}</output
        >
        <Button
            type="button"
            variant="outline"
            class="size-11 text-lg"
            :disabled="disabled || quantity >= 99"
            :aria-label="`Increase ${name} quantity`"
            @click="$emit('change', quantity + 1)"
            >+</Button
        >
    </div>
</template>
