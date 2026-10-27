<script setup lang="ts">
import { X } from '@lucide/vue';
import {
    DialogRoot,
    DialogTrigger,
    DialogPortal,
    DialogOverlay,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogClose,
} from 'reka-ui';
import { figmaDishPreview } from '@/lib/figma-demo';
import { formatMenuPrice } from '@/lib/homepage';

defineProps<{
    name: string;
    image: string;
    price?: string;
    weight?: string | null;
}>();
</script>

<template>
    <DialogRoot>
        <div class="relative h-full">
            <slot />
            <DialogTrigger as-child>
                <button
                    type="button"
                    :aria-label="`View ${name}`"
                    class="absolute inset-0 h-full w-full rounded-3xl hover:ring-1 hover:ring-primary focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring"
                />
            </DialogTrigger>
        </div>
        <DialogPortal>
            <DialogOverlay class="fixed inset-0 z-50 bg-black/40" />
            <DialogContent
                class="green-tasty dish-preview fixed top-1/2 left-1/2 z-50 w-[calc(100%-32px)] max-w-[838px] -translate-x-1/2 -translate-y-1/2 rounded-3xl bg-white font-[Poppins,sans-serif] text-foreground focus:outline-none"
            >
                <img
                    :src="
                        name === figmaDishPreview.strawberry.name
                            ? figmaDishPreview.strawberry.image
                            : image
                    "
                    :alt="name"
                    class="dish-preview-image"
                    width="294"
                    height="296"
                />
                <div class="min-w-0 space-y-6">
                    <DialogTitle
                        class="pr-4 text-[26px] leading-10 font-medium"
                        >{{ name }}</DialogTitle
                    >
                    <div class="space-y-4">
                        <template
                            v-if="name === figmaDishPreview.strawberry.name"
                        >
                            <p class="text-sm leading-6">
                                {{ figmaDishPreview.strawberry.description }}
                            </p>
                            <ul class="space-y-2 text-xs leading-4">
                                <li
                                    v-for="line in figmaDishPreview.strawberry
                                        .nutrition"
                                    :key="line[0].label"
                                    class="flex flex-wrap gap-x-3"
                                >
                                    <span
                                        v-for="field in line"
                                        :key="field.label"
                                        ><strong class="font-semibold"
                                            >{{ field.label }}:</strong
                                        >
                                        {{ field.value }}</span
                                    >
                                </li>
                            </ul>
                        </template>
                        <p
                            v-else
                            class="text-sm leading-6 text-muted-foreground"
                        >
                            See a location’s live menu for ingredients and
                            availability.
                        </p>
                        <p>
                            <span
                                class="text-xl leading-8 font-semibold text-primary"
                                >{{ formatMenuPrice(price) }}</span
                            ><span class="text-sm text-muted-foreground">
                                {{
                                    weight
                                        ? `/ ${weight}`
                                        : '· Weight not specified'
                                }}</span
                            >
                        </p>
                    </div>
                    <DialogDescription
                        class="text-xs leading-5 text-muted-foreground"
                        >Starting price across locations. Choose a location’s
                        menu to check availability and price.</DialogDescription
                    >
                </div>
                <DialogClose
                    aria-label="Close dish preview"
                    class="absolute top-1 right-1 grid size-11 place-items-center rounded-lg hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-[-4px] focus-visible:outline-ring"
                    ><X class="size-5"
                /></DialogClose>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style scoped>
.dish-preview {
    display: grid;
    gap: 24px;
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    padding: 40px 24px 24px;
    box-shadow: 0 0 10px 4px rgb(218 218 218 / 70%);
}
.dish-preview-image {
    width: 196px;
    height: 196px;
    object-fit: contain;
    justify-self: center;
}
@media (min-width: 768px) {
    .dish-preview {
        grid-template-columns: 294px minmax(0, 1fr);
        gap: 32px;
        padding: 40px 40px 24px;
        min-height: 360px;
    }
    .dish-preview-image {
        width: 322px;
        max-width: none;
        height: 324px;
        margin: -14px;
    }
}
</style>
