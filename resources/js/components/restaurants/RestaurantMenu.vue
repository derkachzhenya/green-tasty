<script setup lang="ts">
import ContentState from '@/components/site/ContentState.vue';
import type { MenuCategory } from '@/types/restaurant';
import MenuDishCard from './MenuDishCard.vue';

defineProps<{ categories: MenuCategory[] }>();
</script>

<template>
    <ContentState
        v-if="!categories.length"
        title="No dishes available"
        description="There are no dishes available on this menu right now. Please check back soon."
    />
    <div v-else class="space-y-10">
        <nav aria-label="Menu categories">
            <ul class="flex flex-wrap gap-x-6 gap-y-2 border-b border-border">
                <li
                    v-for="category in categories"
                    :key="category.id"
                    class="min-w-0"
                >
                    <a
                        :href="`#category-${category.id}`"
                        class="inline-flex min-h-11 items-center rounded-sm border-b-2 border-transparent py-2 text-sm font-medium wrap-anywhere hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                        >{{ category.name }}</a
                    >
                </li>
            </ul>
        </nav>
        <section
            v-for="category in categories"
            :id="`category-${category.id}`"
            :key="category.id"
            :aria-labelledby="`category-title-${category.id}`"
            tabindex="-1"
            class="scroll-mt-6 space-y-6"
        >
            <h3
                :id="`category-title-${category.id}`"
                class="text-2xl leading-8 font-medium wrap-anywhere"
            >
                {{ category.name }}
            </h3>
            <ul
                v-if="category.dishes.length"
                :aria-label="`${category.name} dishes`"
                class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4"
            >
                <li
                    v-for="dish in category.dishes"
                    :key="dish.id"
                    class="min-w-0"
                >
                    <MenuDishCard :dish="dish" />
                </li>
            </ul>
            <ContentState
                v-else
                title="No dishes in this category"
                description="Please browse another category or check back soon."
            />
        </section>
    </div>
</template>
