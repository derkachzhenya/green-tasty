<script setup lang="ts">
import { computed } from 'vue';
import { passwordRequirements } from '@/lib/authValidation';
const props = defineProps<{ password: string; id: string }>();
const requirements = computed(() => passwordRequirements(props.password));
</script>
<template>
    <div :id="id" class="mt-1 text-xs leading-[18px]" aria-live="polite">
        <ul
            class="list-inside list-disc"
            :class="!password ? 'text-muted-foreground' : ''"
        >
            <li
                :class="
                    password
                        ? requirements.length
                            ? 'text-primary'
                            : 'text-destructive'
                        : ''
                "
            >
                8–16 characters
            </li>
            <li
                :class="
                    password
                        ? requirements.characters
                            ? 'text-primary'
                            : 'text-destructive'
                        : ''
                "
            >
                Includes: uppercase, lowercase, number, symbol
            </li>
        </ul>
    </div>
</template>
