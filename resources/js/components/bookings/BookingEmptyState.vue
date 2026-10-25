<script setup lang="ts">
defineProps<{ date: string }>();
defineEmits<{ showAll: [] }>();
</script>
<template>
    <div
        class="mx-auto flex max-w-[477px] flex-col items-center px-2 pb-12 text-center"
    >
        <img
            src="/images/green-tasty/locations/Empty State No tables.png"
            alt="An empty restaurant table with two chairs"
            width="477"
            height="465"
            class="h-auto w-full max-w-[477px]"
        />
        <h2 class="mt-6 text-[26px] leading-10 font-medium">
            No Tables Available
        </h2>
        <p class="mt-2 max-w-[340px] text-base leading-6 text-muted-foreground">
            Try adjusting your date, time, or number of guests to find available
            tables.
        </p>
        <button
            type="button"
            class="mt-5 min-h-11 rounded-sm underline underline-offset-8 focus-visible:outline-2 focus-visible:outline-offset-4"
            @click="$emit('showAll')"
        >
            Show all slots on {{ date }}
        </button>
    </div>
</template>
