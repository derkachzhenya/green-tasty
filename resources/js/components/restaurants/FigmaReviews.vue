<script setup lang="ts">
import { computed, ref } from 'vue';
import { figmaReviews } from '@/lib/figma-demo';

const category = ref('Service');
const sort = ref('newest');
const reviews = computed(() =>
    [...figmaReviews].sort((a, b) => {
        if (sort.value === 'oldest') return a.date.localeCompare(b.date);
        if (sort.value === 'highest')
            return b.rating - a.rating || b.date.localeCompare(a.date);
        if (sort.value === 'lowest')
            return a.rating - b.rating || b.date.localeCompare(a.date);
        return b.date.localeCompare(a.date);
    }),
);
const dateLabel = (date: string) =>
    new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${date}T00:00:00Z`));
</script>

<template>
    <section
        aria-labelledby="reviews-heading"
        class="relative py-[60px] xl:pb-[17px]"
    >
        <h2
            id="reviews-heading"
            class="mb-8 text-2xl leading-10 font-medium sm:text-[32px]"
        >
            Customer Reviews
        </h2>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div
                role="group"
                aria-label="Figma review category"
                class="flex flex-wrap border-b border-border"
            >
                <button
                    v-for="label in ['Service', 'Cuisine experience']"
                    :key="label"
                    type="button"
                    :aria-pressed="category === label"
                    class="min-h-11 border-b px-6 text-base leading-6 font-medium focus-visible:outline-2 focus-visible:outline-ring"
                    :class="
                        category === label
                            ? 'border-primary text-primary'
                            : 'border-transparent'
                    "
                    @click="category = label"
                >
                    {{ label }}
                </button>
            </div>
            <label class="flex items-center gap-3 text-xs leading-4"
                >Sort by:
                <select
                    :value="sort"
                    @change="sort = ($event.target as HTMLSelectElement).value"
                    class="min-h-11 w-52 rounded-lg border border-primary bg-white px-3 text-sm text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >
                    <option value="highest">Top rated first</option>
                    <option value="lowest">Low rated first</option>
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </label>
        </div>
        <p class="sr-only" role="status">
            {{ category }} — Figma sample reviews,
            {{ reviews.length }} examples. Category switching previews the same
            sample set.
        </p>
        <ul
            class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4"
            aria-label="Figma demo reviews"
        >
            <li
                v-for="review in reviews"
                :key="review.id"
                class="gt-card min-w-0 bg-white p-6 xl:min-h-[324px]"
            >
                <article class="space-y-6">
                    <div class="flex items-start gap-3">
                        <img
                            :src="review.avatar"
                            alt=""
                            width="60"
                            height="60"
                            class="size-[60px] shrink-0 rounded-full"
                            loading="lazy"
                        />
                        <div class="min-w-0 grow space-y-2 py-1">
                            <div
                                class="flex flex-wrap items-center justify-between gap-2 text-xs leading-4"
                            >
                                <p>{{ review.name }}</p>
                                <time
                                    :datetime="review.date"
                                    class="text-muted-foreground"
                                    >{{ dateLabel(review.date) }}</time
                                >
                            </div>
                            <p
                                class="flex gap-1"
                                :aria-label="`${review.rating} out of 5 stars`"
                            >
                                <img
                                    v-for="star in review.rating"
                                    :key="star"
                                    src="/images/green-tasty/icons/star-01.png"
                                    alt=""
                                    width="16"
                                    height="16"
                                    class="size-4"
                                />
                            </p>
                        </div>
                    </div>
                    <p class="text-sm leading-6">{{ review.text }}</p>
                </article>
            </li>
        </ul>
        <nav
            aria-label="Demo review pages"
            class="mt-6 flex justify-center gap-3 text-xs leading-6"
        >
            <span
                aria-current="page"
                class="min-w-6 border-b border-primary text-center text-primary"
                >1</span
            ><button
                v-for="page in [2, 3]"
                :key="page"
                type="button"
                disabled
                class="min-h-11 min-w-6 text-muted-foreground"
                :aria-label="`Demo page ${page} unavailable`"
            >
                {{ page }}</button
            ><button
                type="button"
                disabled
                aria-label="No additional demo reviews"
                class="min-h-11 min-w-6 text-muted-foreground"
            >
                »
            </button>
        </nav>
        <p class="sr-only">
            Figma demo — these are design examples, not real customer reviews.
            Filters preview this same sample set; no additional review pages are
            available.
        </p>
    </section>
</template>
