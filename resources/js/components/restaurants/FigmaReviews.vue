<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { demoReviews, type DemoReviewCategory } from '@/lib/figma-demo';

const category = ref<DemoReviewCategory>('Service');
const sort = ref('newest');
const page = ref(1);
const categories: DemoReviewCategory[] = ['Service', 'Cuisine experience'];
const pageSize = 4;
watch([category, sort], () => {
    page.value = 1;
});
const reviews = computed(() =>
    demoReviews
        .filter((review) => review.category === category.value)
        .sort((a, b) => {
            if (sort.value === 'oldest') return a.date.localeCompare(b.date);
            if (sort.value === 'highest')
                return b.rating - a.rating || b.date.localeCompare(a.date);
            if (sort.value === 'lowest')
                return a.rating - b.rating || b.date.localeCompare(a.date);
            return b.date.localeCompare(a.date);
        }),
);
const pageCount = computed(() => Math.ceil(reviews.value.length / pageSize));
const visibleReviews = computed(() =>
    reviews.value.slice((page.value - 1) * pageSize, page.value * pageSize),
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
                aria-label="Review category"
                class="flex flex-wrap border-b border-border"
            >
                <button
                    v-for="label in categories"
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
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest first</option>
                </select>
            </label>
        </div>
        <p class="sr-only" role="status">
            {{ category }} — {{ reviews.length }} reviews. Page {{ page }} of
            {{ pageCount }}.
        </p>
        <ul
            class="grid gap-6 sm:grid-cols-2 xl:grid-cols-4"
            aria-label="Customer reviews"
        >
            <li
                v-for="review in visibleReviews"
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
            aria-label="Review pages"
            class="mt-6 flex items-center justify-center gap-3 text-xs leading-6"
        >
            <button
                v-for="number in pageCount"
                :key="number"
                type="button"
                :aria-current="page === number ? 'page' : undefined"
                :aria-label="`Page ${number}`"
                class="min-h-11 min-w-11 border-b focus-visible:outline-2 focus-visible:outline-ring"
                :class="
                    page === number
                        ? 'border-primary text-primary'
                        : 'border-transparent text-muted-foreground hover:text-primary'
                "
                @click="page = number"
            >
                {{ number }}</button
            ><button
                type="button"
                :disabled="page === pageCount"
                aria-label="Next review page"
                class="min-h-11 min-w-11 text-primary focus-visible:outline-2 focus-visible:outline-ring disabled:opacity-40"
                @click="page = Math.min(page + 1, pageCount)"
            >
                »
            </button>
        </nav>
    </section>
</template>
