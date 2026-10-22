<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div role="status" aria-live="polite">
        <p class="sr-only">Loading locations…</p>
        <div
            class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"
            aria-hidden="true"
        >
            <div
                v-for="index in 3"
                :key="index"
                class="overflow-hidden rounded-2xl border border-border bg-card"
            >
                <Skeleton
                    class="aspect-[432/244] rounded-none motion-reduce:animate-none"
                />
                <div class="space-y-4 p-6">
                    <Skeleton class="h-7 w-4/5 motion-reduce:animate-none" />
                    <Skeleton class="h-6 w-3/5 motion-reduce:animate-none" />
                    <Skeleton class="h-6 w-1/3 motion-reduce:animate-none" />
                </div>
            </div>
        </div>
    </div>
</template>
