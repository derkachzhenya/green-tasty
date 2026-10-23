<script setup lang="ts">
import type { MenuDish } from '@/types/restaurant';

withDefaults(defineProps<{ dish: MenuDish; heading?: 'h3' | 'h4' }>(), {
    heading: 'h4',
});
</script>

<template>
    <article
        :aria-labelledby="`dish-${dish.id}`"
        class="gt-card flex h-full flex-col gap-4 bg-card p-6"
    >
        <component
            :is="heading"
            :id="`dish-${dish.id}`"
            class="text-lg leading-7 font-medium wrap-anywhere"
        >
            {{ dish.name }}
        </component>
        <p
            v-if="dish.description"
            class="text-sm leading-6 wrap-anywhere whitespace-pre-line text-muted-foreground"
        >
            {{ dish.description }}
        </p>
        <p class="mt-auto pt-2 text-sm font-medium text-primary">
            <span class="sr-only">Price: </span>{{ dish.price }}
        </p>
        <slot />
    </article>
</template>
