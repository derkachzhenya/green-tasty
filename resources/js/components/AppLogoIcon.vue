<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 64 64"
        :class="className"
        v-bind="$attrs"
    >
        <path
            fill="currentColor"
            d="M29 36C17 36 12 28 13 18C25 17 34 24 29 36ZM33 31C32 19 41 12 52 12C53 24 46 33 33 31Z"
        />
        <path
            d="M31 50V37C31 29 37 24 42 21"
            fill="none"
            stroke="currentColor"
            stroke-width="4.5"
            stroke-linecap="round"
        />
    </svg>
</template>
