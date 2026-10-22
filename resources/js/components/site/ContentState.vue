<script setup lang="ts">
withDefaults(
    defineProps<{
        title: string;
        description: string;
        error?: boolean;
    }>(),
    { error: false },
);
</script>

<template>
    <div
        :role="error ? 'alert' : 'status'"
        class="rounded-2xl border border-border bg-card px-6 py-12 text-center"
    >
        <p class="text-lg font-medium">{{ title }}</p>
        <p
            class="mx-auto mt-2 max-w-md text-sm leading-6 text-muted-foreground"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="mt-6"><slot /></div>
    </div>
</template>
