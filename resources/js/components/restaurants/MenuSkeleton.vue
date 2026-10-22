<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div role="status" aria-live="polite">
        <p class="sr-only">Loading menu…</p>
        <div aria-hidden="true" class="space-y-8">
            <Skeleton class="h-11 max-w-sm motion-reduce:animate-none" />
            <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="index in 4"
                    :key="index"
                    class="space-y-4 rounded-2xl border border-border bg-card p-6"
                >
                    <Skeleton class="h-7 w-4/5 motion-reduce:animate-none" />
                    <Skeleton class="h-16 w-full motion-reduce:animate-none" />
                    <Skeleton class="h-6 w-1/3 motion-reduce:animate-none" />
                </div>
            </div>
        </div>
    </div>
</template>
