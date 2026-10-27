<script setup lang="ts">
import DishPreview from '@/components/restaurants/DishPreview.vue';
import { computed } from 'vue';
import { dishPresentation, formatMenuPrice } from '@/lib/homepage';
const props = defineProps<{
    name: string;
    image: string;
    price?: string;
    loading?: boolean;
}>();
const presentation = computed(() => dishPresentation(props.name, props.price));
</script>

<template>
    <DishPreview
        :name="name"
        :image="image"
        :price="presentation.price"
        :weight="presentation.weight"
    >
        <article
            class="gt-card flex h-full flex-col items-center gap-4 bg-card p-6"
        >
            <div class="relative aspect-square w-[196px] max-w-full">
                <!-- The 224px export includes 14px of shadow around Figma's 196px image. -->
                <img
                    :src="image"
                    :alt="name"
                    width="224"
                    height="224"
                    class="absolute -top-3.5 -left-3.5 aspect-square w-[calc(100%+28px)] max-w-none object-contain"
                    loading="lazy"
                    decoding="async"
                />
            </div>
            <div class="flex min-h-12 w-full items-start justify-between gap-4">
                <h3 class="text-base leading-6 font-medium wrap-anywhere">
                    {{ name }}
                </h3>
                <div class="shrink-0 text-right tabular-nums">
                    <template v-if="!loading">
                        <p class="text-base leading-6 font-medium text-primary">
                            {{ formatMenuPrice(presentation.price) }}
                        </p>
                        <p
                            :aria-label="
                                presentation.weight
                                    ? undefined
                                    : 'Weight not specified'
                            "
                            class="text-xs leading-4 text-muted-foreground"
                        >
                            {{ presentation.weight ?? 'Weight —' }}
                        </p>
                    </template>
                    <template v-else>
                        <p class="text-base leading-6 font-medium text-primary">
                            Loading price…
                        </p>
                    </template>
                </div>
            </div>
        </article>
    </DishPreview>
</template>
