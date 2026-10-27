<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Utensils } from '@lucide/vue';
import { computed } from 'vue';
import { figmaLocationStatistics } from '@/lib/figma-demo';
import type { HomepageLocation } from '@/lib/homepage';
import { show } from '@/routes/restaurants';

const props = defineProps<{ location: HomepageLocation }>();
const demoStatistics = computed(() =>
    props.location.demoLabel
        ? figmaLocationStatistics[props.location.referenceAddress ?? '']
        : undefined,
);
</script>

<template>
    <article
        :aria-labelledby="`location-${location.restaurant.id}`"
        class="relative h-full"
    >
        <Link
            :href="show(location.restaurant.slug)"
            :aria-labelledby="`location-${location.restaurant.id}`"
            class="group gt-card block h-full overflow-hidden bg-card transition-shadow hover:ring-1 hover:ring-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
        >
            <div class="aspect-video overflow-hidden bg-accent">
                <!-- Crop the export's shadow gutter, retaining the photo's proportions. -->
                <img
                    v-if="location.image"
                    :src="location.image"
                    :alt="`Dining area at ${location.address}`"
                    width="460"
                    height="271"
                    class="h-full w-full scale-[1.064815] object-cover"
                    loading="lazy"
                    decoding="async"
                />
                <div
                    v-else
                    class="flex h-full items-center justify-center text-primary"
                    aria-hidden="true"
                >
                    <Utensils class="size-12" :stroke-width="1" />
                </div>
            </div>
            <div class="flex min-h-32 flex-col gap-4 p-6">
                <div class="flex items-start gap-2">
                    <img
                        src="/images/green-tasty/icons/location-pin.png"
                        alt=""
                        width="24"
                        height="24"
                        class="mt-2 size-6 shrink-0"
                    />
                    <h3
                        :id="`location-${location.restaurant.id}`"
                        class="text-xl leading-10 font-medium wrap-anywhere xl:text-[26px]"
                    >
                        {{ location.address }}
                    </h3>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs leading-6"
                >
                    <p class="flex items-center gap-1">
                        <img
                            src="/images/green-tasty/icons/Table.png"
                            alt=""
                            width="16"
                            height="16"
                            class="size-4"
                        />
                        {{
                            demoStatistics?.tables ??
                            location.restaurant.table_count
                        }}
                        {{
                            (demoStatistics?.tables ??
                                location.restaurant.table_count) === 1
                                ? 'table'
                                : 'tables'
                        }}
                    </p>
                    <p v-if="demoStatistics">
                        Daily occupancy:
                        <span class="ml-1">{{ demoStatistics.occupancy }}</span>
                    </p>
                    <span class="sr-only">View restaurant and menu</span>
                </div>
            </div>
        </Link>
    </article>
</template>
