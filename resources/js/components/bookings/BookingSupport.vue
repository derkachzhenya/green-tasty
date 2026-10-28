<script setup lang="ts">
import { Headset } from '@lucide/vue';

defineProps<{ phone?: string | null; greeting?: boolean }>();
</script>
<template>
    <aside
        class="flex min-w-0 items-center gap-3 rounded-lg border border-primary/10"
        :class="
            greeting
                ? 'bg-[#f7f7ed] px-3 py-2 text-xs leading-4'
                : 'bg-[#e4f6e5] p-3 text-xs leading-5 sm:text-sm'
        "
    >
        <Headset
            v-if="greeting"
            class="size-8 shrink-0 text-primary"
            :stroke-width="1.5"
            aria-hidden="true"
        />
        <img
            v-else
            src="/images/green-tasty/icons/phone-call.png"
            alt=""
            width="32"
            height="32"
            class="size-8 shrink-0 object-contain"
        />
        <p class="min-w-0 wrap-anywhere">
            {{
                greeting
                    ? phone
                        ? 'For any assistance with your table reservation or pre-order, please call us at this number: '
                        : 'For any assistance with your table reservation or pre-order, please contact your restaurant.'
                    : 'For groups larger than 4, please contact the restaurant directly.'
            }}
            <a
                v-if="phone"
                :href="`tel:${phone.replace(/[^+\d]/g, '')}`"
                :class="
                    greeting
                        ? 'hover:underline focus-visible:outline-2'
                        : 'block underline focus-visible:outline-2'
                "
                >{{ phone }}</a
            ><span v-if="greeting && phone">.</span>
        </p>
    </aside>
</template>
