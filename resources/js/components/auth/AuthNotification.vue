<script setup lang="ts">
import { CircleCheck, CircleAlert, X } from '@lucide/vue';
import { onMounted, watch } from 'vue';
const props = withDefaults(
    defineProps<{ message: string; error?: boolean }>(),
    {
        error: false,
    },
);
const emit = defineEmits<{ dismiss: [] }>();
onMounted(() => {
    watch(
        [() => props.message, () => props.error],
        ([message, error], _previous, onCleanup) => {
            if (!message || error) return;
            const timer = setTimeout(() => emit('dismiss'), 5000);
            onCleanup(() => clearTimeout(timer));
        },
        { immediate: true },
    );
});
</script>
<template>
    <div
        v-if="message"
        :role="error ? 'alert' : 'status'"
        :class="
            error
                ? 'border-destructive/30 bg-[#fff5f5]'
                : 'border-primary/20 bg-[#e9ffea]'
        "
        class="flex items-start gap-3 rounded-lg border p-4 text-sm leading-6"
    >
        <component
            :is="error ? CircleAlert : CircleCheck"
            class="mt-1 size-5 shrink-0"
            :class="error ? 'text-destructive' : 'text-primary'"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <p class="font-medium">
                {{ error ? 'Unable to continue' : 'Success' }}
            </p>
            <p class="mt-1 wrap-anywhere">{{ message }}</p>
        </div>
        <button
            type="button"
            aria-label="Dismiss notification"
            class="-m-2 grid size-11 shrink-0 place-items-center rounded-lg focus-visible:outline-2 focus-visible:outline-ring"
            @click="$emit('dismiss')"
        >
            <X class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
