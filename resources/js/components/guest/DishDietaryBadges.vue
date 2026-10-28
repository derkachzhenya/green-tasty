<script setup lang="ts">
import { Leaf, MilkOff } from '@lucide/vue';
import { dishDietary } from '@/lib/guestMenu';
defineProps<{ name: string }>();
</script>
<template>
    <ul
        v-if="dishDietary(name).length"
        aria-label="Dietary preferences"
        class="flex flex-wrap gap-x-4 gap-y-2 text-xs"
    >
        <li
            v-for="label in dishDietary(name)"
            :key="label"
            class="flex items-center gap-2"
        >
            <span
                class="grid size-7 place-items-center rounded-full"
                :class="
                    label === 'Vegetarian'
                        ? 'bg-[#e8ffea] text-primary'
                        : 'bg-[#f1e1ff] text-[#8d4cc3]'
                "
            >
                <Leaf
                    v-if="label === 'Vegetarian'"
                    class="size-4"
                    aria-hidden="true"
                /><MilkOff v-else class="size-4" aria-hidden="true" /> </span
            >{{ label }}
        </li>
    </ul>
</template>
